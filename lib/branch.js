// Sharing attendance between branches that each run their own copy of this program.
// Branch A exports a file for a period; branch B imports it. Employee IDs are company-wide
// employee numbers, so the same ID is the same person in every branch.

const importer = require('./importer');

const TYPE = 'es-attendance-branch-export';
const VERSION = 1;

async function getBranchName(pool) {
  const { rows } = await pool.query(`SELECT value FROM settings WHERE key = 'branch_name'`);
  return rows[0] ? rows[0].value : '';
}

async function saveBranchName(pool, name) {
  await pool.query(
    `INSERT INTO settings (key, value) VALUES ('branch_name', $1) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value`,
    [name]);
}

/* ---------------- export ---------------- */

async function buildExport(pool, { from, to, departmentId, branch, username }) {
  const params = [from, to];
  let empFilter = '';
  if (departmentId) { params.push(departmentId); empFilter = ` AND e.department_id = $${params.length}`; }

  // Employees with punches in the period, plus active employees (so absences carry over too)
  const { rows: employees } = await pool.query(
    `SELECT e.pin, e.name, e.start_date, e.active, d.name AS department,
            s.name AS shift_name, to_char(s.start_time, 'HH24:MI') AS shift_start, to_char(s.end_time, 'HH24:MI') AS shift_end,
            s.grace_in, s.grace_out, s.weekend
     FROM employees e
     LEFT JOIN departments d ON d.id = e.department_id
     LEFT JOIN shifts s ON s.id = COALESCE(e.shift_id, d.shift_id, (SELECT id FROM shifts WHERE is_default LIMIT 1))
     WHERE (e.active OR EXISTS (SELECT 1 FROM attendance a WHERE a.pin = e.pin AND a.deleted_at IS NULL
                                AND a.punch_time >= $1::date AND a.punch_time < $2::date + 1))${empFilter}
     ORDER BY CASE WHEN e.pin ~ '^[0-9]+$' THEN e.pin::bigint END NULLS LAST, e.pin`, params);
  const pins = employees.map((e) => e.pin);

  const { rows: punches } = await pool.query(
    `SELECT pin, to_char(punch_time, 'YYYY-MM-DD HH24:MI:SS') AS time, source = 'manual' AS manual
     FROM attendance WHERE pin = ANY($3) AND deleted_at IS NULL
       AND punch_time >= $1::date AND punch_time < $2::date + 1
     ORDER BY punch_time`, [from, to, pins]);

  // Punches removed or corrected here, so the other branch removes them too
  const { rows: removed } = await pool.query(
    `SELECT DISTINCT a.pin, to_char(a.punch_time, 'YYYY-MM-DD HH24:MI:SS') AS time
     FROM attendance a WHERE a.pin = ANY($3) AND a.deleted_at IS NOT NULL
       AND a.punch_time >= $1::date AND a.punch_time < $2::date + 1
       AND NOT EXISTS (SELECT 1 FROM attendance b WHERE b.pin = a.pin AND b.punch_time = a.punch_time AND b.deleted_at IS NULL)`,
    [from, to, pins]);

  const { rows: leaves } = await pool.query(
    `SELECT pin, type, start_date, end_date, note FROM leaves
     WHERE pin = ANY($3) AND end_date >= $1 AND start_date <= $2 ORDER BY start_date`, [from, to, pins]);

  return {
    type: TYPE, version: VERSION,
    branch: { name: branch },
    period: { from, to },
    exported_at: new Date().toISOString(),
    exported_by: username,
    employees: employees.map((e) => ({
      pin: e.pin, name: e.name, department: e.department, start_date: e.start_date, active: e.active,
      shift: e.shift_start ? {
        name: e.shift_name, start: e.shift_start, end: e.shift_end,
        grace_in: e.grace_in, grace_out: e.grace_out, weekend: e.weekend,
      } : null,
    })),
    punches, removed, leaves,
  };
}

/* ---------------- import ---------------- */

const isDate = (s) => /^\d{4}-\d{2}-\d{2}$/.test(s || '');
const isStamp = (s) => /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(s || '');
const isTime = (s) => /^\d{2}:\d{2}$/.test(s || '');

function validate(pkg) {
  if (!pkg || pkg.type !== TYPE) throw new Error('This is not a branch attendance file from this program');
  if (pkg.version > VERSION) throw new Error('This file was made by a newer version of the program. Update this server first');
  if (!Array.isArray(pkg.employees) || !Array.isArray(pkg.punches)) throw new Error('The file is incomplete');
  if (pkg.punches.length > importer.MAX_ROWS) throw new Error('The file is too large');
}

async function preview(pool, pkg) {
  validate(pkg);
  const pins = pkg.employees.map((e) => String(e.pin));
  const { rows: existing } = await pool.query('SELECT pin FROM employees WHERE pin = ANY($1)', [pins]);
  const here = new Set(existing.map((r) => r.pin));
  const rows = pkg.punches.filter((p) => isStamp(p.time)).map((p) => ({ pin: String(p.pin), time: p.time }));
  const already = await importer.countExisting(pool, rows);
  const employees = pkg.employees.map((e) => ({ pin: String(e.pin), name: e.name, department: e.department, exists: here.has(String(e.pin)) }));
  return {
    branch: pkg.branch, period: pkg.period, exported_at: pkg.exported_at, exported_by: pkg.exported_by,
    employees, punches: rows.length, new_punches: Math.max(0, rows.length - already),
    removed: (pkg.removed || []).length, leaves: (pkg.leaves || []).length,
  };
}

async function findOrCreateShift(pool, shift, branchName, cache) {
  if (!shift || !isTime(shift.start) || !isTime(shift.end)) return null;
  const weekend = (Array.isArray(shift.weekend) ? shift.weekend : []).map(Number).filter((d) => d >= 0 && d <= 6).sort();
  const key = [shift.start, shift.end, shift.grace_in, shift.grace_out, weekend.join('')].join('|');
  if (cache.has(key)) return cache.get(key);
  // Reuse a shift here with exactly the same times, grace and weekend
  const { rows } = await pool.query(
    `SELECT id FROM shifts WHERE to_char(start_time, 'HH24:MI') = $1 AND to_char(end_time, 'HH24:MI') = $2
       AND grace_in = $3 AND grace_out = $4
       AND (SELECT array_agg(x ORDER BY x) FROM unnest(weekend) x) IS NOT DISTINCT FROM $5::int[]
     ORDER BY is_default DESC, id LIMIT 1`,
    [shift.start, shift.end, parseInt(shift.grace_in, 10) || 0, parseInt(shift.grace_out, 10) || 0, weekend.length ? weekend : null]);
  let id = rows[0] && rows[0].id;
  if (!id) {
    const ins = await pool.query(
      `INSERT INTO shifts (name, start_time, end_time, grace_in, grace_out, weekend) VALUES ($1, $2, $3, $4, $5, $6) RETURNING id`,
      [`${branchName || 'Branch'} - ${shift.name || 'Shift'}`.slice(0, 100), shift.start, shift.end,
        parseInt(shift.grace_in, 10) || 0, parseInt(shift.grace_out, 10) || 0, weekend]);
    id = ins.rows[0].id;
  }
  cache.set(key, id);
  return id;
}

async function findOrCreateDepartment(pool, name, cache) {
  const n = String(name || '').trim();
  if (!n) return null;
  const key = n.toLowerCase();
  if (cache.has(key)) return cache.get(key);
  const { rows } = await pool.query('SELECT id FROM departments WHERE lower(name) = $1 LIMIT 1', [key]);
  const id = rows[0] ? rows[0].id : (await pool.query('INSERT INTO departments (name) VALUES ($1) RETURNING id', [n])).rows[0].id;
  cache.set(key, id);
  return id;
}

async function apply(pool, pkg, { withLeaves, username }) {
  validate(pkg);
  const map = (pin) => String(pin);
  const branchName = (pkg.branch && pkg.branch.name) || 'Other branch';

  // Employees: new ones get their name, department and shift from the file;
  // employees already here keep anything set here, only empty fields are filled in
  const shiftCache = new Map();
  const deptCache = new Map();
  let newEmployees = 0;
  for (const e of pkg.employees) {
    const pin = map(e.pin);
    const shiftId = await findOrCreateShift(pool, e.shift, branchName, shiftCache);
    const departmentId = await findOrCreateDepartment(pool, e.department, deptCache);
    const r = await pool.query(
      `INSERT INTO employees (pin, name, department_id, shift_id, start_date) VALUES ($1, $2, $3, $4, $5)
       ON CONFLICT (pin) DO UPDATE SET
         name = COALESCE(employees.name, EXCLUDED.name),
         department_id = COALESCE(employees.department_id, EXCLUDED.department_id),
         shift_id = COALESCE(employees.shift_id, EXCLUDED.shift_id),
         start_date = COALESCE(employees.start_date, EXCLUDED.start_date)
       RETURNING (xmax = 0) AS inserted`,
      [pin, e.name || null, departmentId, shiftId, isDate(e.start_date) ? e.start_date : null]);
    if (r.rows[0].inserted) newEmployees++;
  }

  // Punches (corrections made at the other branch stay marked as manual)
  const sn = `BRANCH:${branchName}`.slice(0, 120);
  const valid = pkg.punches.filter((p) => isStamp(p.time));
  const toRows = (list) => list.map((p) => ({ pin: map(p.pin), time: p.time, status: null, verify: null, raw: null }));
  const a = await importer.savePunches(pool, toRows(valid.filter((p) => !p.manual)), { sn, source: 'branch' });
  const b = await importer.savePunches(pool, toRows(valid.filter((p) => p.manual)), { sn, source: 'manual' });

  // Punches the other branch deleted or corrected: remove the copies that came from there
  let removed = 0;
  const gone = (pkg.removed || []).filter((p) => isStamp(p.time));
  if (gone.length) {
    const r = await pool.query(
      `UPDATE attendance a SET deleted_at = now(), deleted_by = $3, note = $4
       FROM unnest($1::text[], $2::timestamp[]) AS x(pin, t)
       WHERE a.pin = x.pin AND a.punch_time = x.t AND a.deleted_at IS NULL AND a.sn = $5`,
      [gone.map((p) => map(p.pin)), gone.map((p) => p.time), username, `Removed at ${branchName}`, sn]);
    removed = r.rowCount;
  }

  // Leaves, skipping ones already recorded
  let leaves = 0;
  if (withLeaves) {
    for (const l of pkg.leaves || []) {
      if (!isDate(l.start_date) || !isDate(l.end_date) || !l.type) continue;
      const r = await pool.query(
        `INSERT INTO leaves (pin, type, start_date, end_date, note)
         SELECT $1, $2, $3, $4, $5
         WHERE EXISTS (SELECT 1 FROM employees WHERE pin = $1)
           AND NOT EXISTS (SELECT 1 FROM leaves WHERE pin = $1 AND type = $2 AND start_date = $3 AND end_date = $4)`,
        [map(l.pin), String(l.type), l.start_date, l.end_date, l.note || null]);
      leaves += r.rowCount;
    }
  }

  return {
    sn, branchName, newEmployees, employees: pkg.employees.length,
    total: a.total + b.total, inserted: a.inserted + b.inserted, duplicates: a.duplicates + b.duplicates,
    removed, leaves,
  };
}

module.exports = { TYPE, getBranchName, saveBranchName, buildExport, preview, apply };
