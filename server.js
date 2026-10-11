const express = require('express');
const path = require('path');
const { pool, init } = require('./db');
const { compute, today, LEAVE_TYPES } = require('./attendance');
const { buildWorkbook } = require('./export');
const { buildTimesheet } = require('./timesheet');
const auth = require('./auth');
const { requireAuth, need, audit } = auth;

const PORT = process.env.PORT || 3000;
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || '';
const DEVICE_TZ_OFFSET = process.env.DEVICE_TZ_OFFSET || '3'; // Saudi Arabia = +3

if (!ADMIN_PASSWORD) {
  console.warn('WARNING: ADMIN_PASSWORD is not set. The built-in "admin" account is disabled until you set it.');
}

const app = express();
app.set('trust proxy', true);

/* ------------------------------------------------------------------ */
/*  ADMS / iclock protocol (the device talks to these endpoints)       */
/* ------------------------------------------------------------------ */

const iclock = express.Router();
iclock.use(express.text({ type: () => true, limit: '20mb' }));

function plain(res, body) {
  res.set('Content-Type', 'text/plain');
  res.send(body);
}

async function touchDevice(req) {
  const sn = req.query.SN;
  if (!sn) return null;
  const ip = req.ip;
  const info = req.query.INFO || null;
  await pool.query(
    `INSERT INTO devices (sn, ip, info) VALUES ($1, $2, $3)
     ON CONFLICT (sn) DO UPDATE SET last_seen = now(), ip = EXCLUDED.ip,
       info = COALESCE(EXCLUDED.info, devices.info)`,
    [sn, ip, info]
  );
  return sn;
}

// Parse "KEY=value\tKEY2=value2" into an object
function parseKV(line) {
  const out = {};
  for (const part of line.split('\t')) {
    const i = part.indexOf('=');
    if (i > 0) out[part.slice(0, i).trim()] = part.slice(i + 1).trim();
  }
  return out;
}

// Device handshake + data upload
iclock.get('/cdata', async (req, res) => {
  try {
    const sn = await touchDevice(req);
    if (req.query.options === 'all') {
      console.log(`[ADMS] handshake from ${sn} (${req.ip})`);
      return plain(res, [
        `GET OPTION FROM: ${sn}`,
        'ATTLOGStamp=None',
        'OPERLOGStamp=9999',
        'ATTPHOTOStamp=None',
        'ErrorDelay=30',
        'Delay=10',
        'TransTimes=00:00;14:05',
        'TransInterval=1',
        'TransFlag=TransData AttLog\tOpLog\tEnrollUser\tChgUser',
        `TimeZone=${DEVICE_TZ_OFFSET}`,
        'Realtime=1',
        'Encrypt=None',
      ].join('\n'));
    }
    plain(res, 'OK');
  } catch (e) {
    console.error('[ADMS] cdata GET error', e);
    plain(res, 'OK');
  }
});

iclock.post('/cdata', async (req, res) => {
  const table = (req.query.table || '').toUpperCase();
  const body = typeof req.body === 'string' ? req.body : '';
  const lines = body.split(/\r?\n/).filter((l) => l.trim());
  try {
    const sn = await touchDevice(req);

    if (table === 'ATTLOG') {
      let saved = 0;
      for (const line of lines) {
        const [pin, time, status, verify] = line.split('\t');
        if (!pin || !time) continue;
        const r = await pool.query(
          `INSERT INTO attendance (sn, pin, punch_time, status, verify, raw)
           VALUES ($1, $2, $3, $4, $5, $6) ON CONFLICT DO NOTHING`,
          [sn, pin.trim(), time.trim(), parseInt(status, 10) || 0, parseInt(verify, 10) || 0, line]
        );
        saved += r.rowCount;
        await pool.query(
          `INSERT INTO employees (pin) VALUES ($1) ON CONFLICT DO NOTHING`,
          [pin.trim()]
        );
      }
      console.log(`[ADMS] ${sn}: received ${lines.length} punches, ${saved} new`);
      return plain(res, `OK: ${lines.length}`);
    }

    // User info arrives in OPERLOG ("USER PIN=1\tName=...") or USERINFO ("PIN=1\tName=...")
    let users = 0;
    for (const line of lines) {
      let rest = null;
      if (line.startsWith('USER ')) rest = line.slice(5);
      else if (table === 'USERINFO' && line.includes('PIN=')) rest = line;
      if (!rest) continue;
      const kv = parseKV(rest);
      if (!kv.PIN) continue;
      const name = kv.Name || null;
      await pool.query(
        `INSERT INTO employees (pin, name) VALUES ($1, $2)
         ON CONFLICT (pin) DO UPDATE SET name = COALESCE(employees.name, EXCLUDED.name)`,
        [kv.PIN, name]
      );
      users++;
    }
    if (users) console.log(`[ADMS] ${sn}: received ${users} user records`);
    plain(res, `OK: ${lines.length}`);
  } catch (e) {
    console.error('[ADMS] cdata POST error', e);
    res.status(500);
    plain(res, 'ERROR');
  }
});

// Device asks for pending commands
iclock.get('/getrequest', async (req, res) => {
  try {
    const sn = await touchDevice(req);
    const { rows } = await pool.query(
      `UPDATE commands SET status = 'sent', sent_at = now()
       WHERE id = (SELECT id FROM commands WHERE sn = $1 AND status = 'pending' ORDER BY id LIMIT 1)
       RETURNING id, command`,
      [sn]
    );
    if (rows.length) {
      console.log(`[ADMS] ${sn}: sending command #${rows[0].id}: ${rows[0].command}`);
      return plain(res, `C:${rows[0].id}:${rows[0].command}`);
    }
    plain(res, 'OK');
  } catch (e) {
    console.error('[ADMS] getrequest error', e);
    plain(res, 'OK');
  }
});

// Device reports command results
iclock.post('/devicecmd', async (req, res) => {
  try {
    await touchDevice(req);
    const body = typeof req.body === 'string' ? req.body : '';
    for (const line of body.split(/\r?\n/)) {
      const m = line.match(/ID=(\d+)/);
      if (!m) continue;
      const ret = (line.match(/Return=(-?\d+)/) || [])[1];
      await pool.query(
        `UPDATE commands SET status = $2, result = $3, done_at = now() WHERE id = $1`,
        [m[1], ret === '0' ? 'done' : 'failed', line]
      );
    }
    plain(res, 'OK');
  } catch (e) {
    console.error('[ADMS] devicecmd error', e);
    plain(res, 'OK');
  }
});

// Anything else the device may call (ping, registry, push, fdata...)
iclock.all('*', async (req, res) => {
  try { await touchDevice(req); } catch (_) {}
  plain(res, 'OK');
});

app.use('/iclock', iclock);

/* ------------------------------------------------------------------ */
/*  Login                                                              */
/* ------------------------------------------------------------------ */

app.use(express.urlencoded({ extended: false }));
app.use(express.json());

app.get('/login', (req, res) => res.sendFile(path.join(__dirname, 'public', 'login.html')));

// Wrap async handlers so a DB error returns 500 instead of crashing the process
const h = (fn) => (req, res) => fn(req, res).catch((e) => {
  console.error('[API]', req.method, req.originalUrl, e.message);
  if (!res.headersSent) res.status(500).json({ error: e.message });
});

app.post('/login', h(auth.login));

app.get('/logout', (req, res) => {
  res.set('Set-Cookie', 'sid=; Path=/; Max-Age=0');
  res.redirect('/login');
});

app.get('/health', (req, res) => res.send('ok'));

/* ------------------------------------------------------------------ */
/*  Dashboard + API                                                    */
/* ------------------------------------------------------------------ */

app.get('/', requireAuth, (req, res) => res.sendFile(path.join(__dirname, 'public', 'index.html')));

const api = express.Router();
api.use(requireAuth);

const isDate = (s) => /^\d{4}-\d{2}-\d{2}$/.test(s || '');

const isTime = (s) => /^\d{2}:\d{2}(:\d{2})?$/.test(s || '');
const intOrNull = (v) => (v === '' || v == null ? null : parseInt(v, 10) || null);
const text = (v) => String(v ?? '').trim() || null;

async function employeeLabel(pin) {
  const { rows } = await pool.query('SELECT name FROM employees WHERE pin = $1', [pin]);
  return rows[0] && rows[0].name ? `${rows[0].name} (${pin})` : `Employee ${pin}`;
}

function rangeOk(from, to, maxDays = 366) {
  return isDate(from) && isDate(to) && from <= to &&
    (Date.parse(to) - Date.parse(from)) / 86400000 <= maxDays;
}

api.get('/meta', h(async (req, res) => {
  const [shifts, departments] = await Promise.all([
    pool.query('SELECT * FROM shifts ORDER BY is_default DESC, id'),
    pool.query('SELECT * FROM departments ORDER BY name'),
  ]);
  res.json({
    today: today(), leaveTypes: LEAVE_TYPES, shifts: shifts.rows, departments: departments.rows,
    me: req.user, permissionList: auth.PERMISSIONS,
  });
}));

api.get('/summary', need('attendance.view'), h(async (req, res) => {
  const date = req.query.date;
  if (!isDate(date)) return res.status(400).json({ error: 'bad date' });
  const { days } = await compute(date, date);
  res.json(days);
}));

api.get('/report', need('report.view'), h(async (req, res) => {
  const { from, to } = req.query;
  if (!rangeOk(from, to)) return res.status(400).json({ error: 'bad range' });
  res.json(await compute(from, to));
}));

/* Shifts */
function shiftBody(b) {
  if (!text(b.name) || !isTime(b.start_time) || !isTime(b.end_time)) return null;
  const weekend = (Array.isArray(b.weekend) ? b.weekend : []).map(Number).filter((d) => d >= 0 && d <= 6);
  return [text(b.name), b.start_time, b.end_time,
    Math.max(0, parseInt(b.grace_in, 10) || 0), Math.max(0, parseInt(b.grace_out, 10) || 0), weekend];
}

api.post('/shifts', need('settings.edit'), h(async (req, res) => {
  const v = shiftBody(req.body);
  if (!v) return res.status(400).json({ error: 'Shift details are incomplete' });
  const { rows } = await pool.query(
    `INSERT INTO shifts (name, start_time, end_time, grace_in, grace_out, weekend)
     VALUES ($1, $2, $3, $4, $5, $6) RETURNING *`, v);
  await audit(req, 'Add shift', `${v[0]} (${v[1]} - ${v[2]})`);
  res.json(rows[0]);
}));

api.put('/shifts/:id', need('settings.edit'), h(async (req, res) => {
  const v = shiftBody(req.body);
  if (!v) return res.status(400).json({ error: 'Shift details are incomplete' });
  await pool.query(
    `UPDATE shifts SET name = $2, start_time = $3, end_time = $4, grace_in = $5, grace_out = $6, weekend = $7
     WHERE id = $1`, [req.params.id, ...v]);
  if (req.body.is_default) {
    await pool.query('UPDATE shifts SET is_default = (id = $1)', [req.params.id]);
  }
  await audit(req, req.body.is_default ? 'Set default shift' : 'Edit shift', `${v[0]} (${v[1]} - ${v[2]}, grace ${v[3]} min)`);
  res.json({ ok: true });
}));

api.delete('/shifts/:id', need('settings.edit'), h(async (req, res) => {
  const { rows } = await pool.query('DELETE FROM shifts WHERE id = $1 AND NOT is_default RETURNING name', [req.params.id]);
  if (!rows.length) return res.status(400).json({ error: 'The default shift cannot be deleted' });
  await audit(req, 'Delete shift', rows[0].name);
  res.json({ ok: true });
}));

/* Departments */
api.post('/departments', need('settings.edit'), h(async (req, res) => {
  if (!text(req.body.name)) return res.status(400).json({ error: 'Enter a department name' });
  const { rows } = await pool.query(
    'INSERT INTO departments (name, shift_id) VALUES ($1, $2) RETURNING *',
    [text(req.body.name), intOrNull(req.body.shift_id)]);
  await audit(req, 'Add department', rows[0].name);
  res.json(rows[0]);
}));

api.put('/departments/:id', need('settings.edit'), h(async (req, res) => {
  if (!text(req.body.name)) return res.status(400).json({ error: 'Enter a department name' });
  await pool.query('UPDATE departments SET name = $2, shift_id = $3 WHERE id = $1',
    [req.params.id, text(req.body.name), intOrNull(req.body.shift_id)]);
  await audit(req, 'Edit department', text(req.body.name));
  res.json({ ok: true });
}));

api.delete('/departments/:id', need('settings.edit'), h(async (req, res) => {
  const { rows } = await pool.query('DELETE FROM departments WHERE id = $1 RETURNING name', [req.params.id]);
  if (rows.length) await audit(req, 'Delete department', rows[0].name);
  res.json({ ok: true });
}));

/* Holidays */
api.get('/holidays', need('leaves.view'), h(async (req, res) => {
  const { rows } = await pool.query('SELECT * FROM holidays ORDER BY start_date DESC LIMIT 300');
  res.json(rows);
}));

api.post('/holidays', need('holidays.edit'), h(async (req, res) => {
  const { name, start_date, end_date } = req.body;
  if (!text(name) || !rangeOk(start_date, end_date)) return res.status(400).json({ error: 'Check the name and dates' });
  await pool.query('INSERT INTO holidays (name, start_date, end_date) VALUES ($1, $2, $3)',
    [text(name), start_date, end_date]);
  await audit(req, 'Add public holiday', `${text(name)} (${start_date} to ${end_date})`);
  res.json({ ok: true });
}));

api.delete('/holidays/:id', need('holidays.edit'), h(async (req, res) => {
  const { rows } = await pool.query('DELETE FROM holidays WHERE id = $1 RETURNING *', [req.params.id]);
  if (rows.length) await audit(req, 'Delete public holiday', `${rows[0].name} (${rows[0].start_date} to ${rows[0].end_date})`);
  res.json({ ok: true });
}));

/* Leaves */
api.get('/leaves', need('leaves.view'), h(async (req, res) => {
  const { rows } = await pool.query(
    `SELECT l.*, e.name FROM leaves l LEFT JOIN employees e ON e.pin = l.pin
     WHERE l.end_date >= CURRENT_DATE - 120 ORDER BY l.start_date DESC LIMIT 500`);
  res.json(rows);
}));

api.post('/leaves', need('leaves.edit'), h(async (req, res) => {
  const { pin, type, start_date, end_date, note } = req.body;
  if (!text(pin) || !LEAVE_TYPES.includes(type) || !rangeOk(start_date, end_date)) {
    return res.status(400).json({ error: 'Check the employee, type and dates' });
  }
  await pool.query(
    'INSERT INTO leaves (pin, type, start_date, end_date, note) VALUES ($1, $2, $3, $4, $5)',
    [text(pin), type, start_date, end_date, text(note)]);
  await audit(req, 'Add leave', `${await employeeLabel(pin)}: ${type} (${start_date} to ${end_date})`);
  res.json({ ok: true });
}));

api.delete('/leaves/:id', need('leaves.edit'), h(async (req, res) => {
  const { rows } = await pool.query('DELETE FROM leaves WHERE id = $1 RETURNING *', [req.params.id]);
  if (rows.length) {
    const l = rows[0];
    await audit(req, 'Delete leave', `${await employeeLabel(l.pin)}: ${l.type} (${l.start_date} to ${l.end_date})`);
  }
  res.json({ ok: true });
}));

api.get('/punches', need('punches.view'), h(async (req, res) => {
  const { from, to, pin } = req.query;
  if (!isDate(from) || !isDate(to)) return res.status(400).json({ error: 'bad date' });
  const params = [from, to];
  let where = 'a.punch_time >= $1::date AND a.punch_time < $2::date + 1';
  if (pin) { params.push(pin); where += ` AND a.pin = $${params.length}`; }
  const { rows } = await pool.query(
    `SELECT a.id, a.pin, e.name, a.punch_time, a.verify, a.status, a.sn, d.name AS device_name
     FROM attendance a
     LEFT JOIN employees e ON e.pin = a.pin
     LEFT JOIN devices d ON d.sn = a.sn
     WHERE ${where} ORDER BY a.punch_time DESC LIMIT 5000`,
    params
  );
  res.json(rows);
}));

// Also needed for the employee pickers in the leave form and punch log
api.get('/employees', need('employees.view', 'leaves.edit', 'punches.view'), h(async (req, res) => {
  const { rows } = await pool.query(
    `SELECT e.pin, e.name, e.department_id, e.shift_id, e.active, e.start_date,
            MAX(a.punch_time) AS last_punch, COUNT(a.id)::int AS total
     FROM employees e LEFT JOIN attendance a ON a.pin = e.pin
     GROUP BY e.pin
     ORDER BY CASE WHEN e.pin ~ '^[0-9]+$' THEN e.pin::bigint END NULLS LAST, e.pin`
  );
  res.json(rows);
}));

// Partial update: only the fields present in the body change
api.put('/employees/:pin', need('employees.edit'), h(async (req, res) => {
  const b = req.body;
  const fields = {
    name: () => text(b.name),
    department_id: () => intOrNull(b.department_id),
    shift_id: () => intOrNull(b.shift_id),
    active: () => b.active !== false,
    start_date: () => (isDate(b.start_date) ? b.start_date : null),
  };
  const keys = Object.keys(fields).filter((k) => k in b);
  if (!keys.length) return res.status(400).json({ error: 'nothing to update' });
  const values = keys.map((k) => fields[k]());
  await pool.query(
    `INSERT INTO employees (pin, ${keys.join(', ')}) VALUES ($1, ${keys.map((_, i) => '$' + (i + 2)).join(', ')})
     ON CONFLICT (pin) DO UPDATE SET ${keys.map((k) => `${k} = EXCLUDED.${k}`).join(', ')}`,
    [req.params.pin, ...values]
  );
  const LABELS = { name: 'name', department_id: 'department', shift_id: 'shift', active: 'active', start_date: 'start date' };
  await audit(req, 'Edit employee',
    `${await employeeLabel(req.params.pin)}: ` + keys.map((k, i) => `${LABELS[k]} = ${values[i] ?? 'empty'}`).join(', '));
  res.json({ ok: true });
}));

api.get('/devices', need('devices.view'), h(async (req, res) => {
  const { rows } = await pool.query(
    `SELECT d.*, (SELECT COUNT(*)::int FROM attendance a WHERE a.sn = d.sn) AS total,
            (SELECT COUNT(*)::int FROM commands c WHERE c.sn = d.sn AND c.status IN ('pending','sent')) AS pending
     FROM devices d ORDER BY last_seen DESC`
  );
  res.json(rows);
}));

api.put('/devices/:sn', need('devices.manage'), h(async (req, res) => {
  const name = String(req.body.name || '').trim() || null;
  await pool.query(`UPDATE devices SET name = $2 WHERE sn = $1`, [req.params.sn, name]);
  await audit(req, 'Rename device', `${req.params.sn}: ${name || 'no name'}`);
  res.json({ ok: true });
}));

// Queue commands for the device; it picks them up on its next /iclock/getrequest
const COMMANDS = {
  'sync-users': 'DATA QUERY USERINFO',
  'resend-logs': 'DATA QUERY ATTLOG StartTime=2000-01-01 00:00:00\tEndTime=2099-12-31 23:59:59',
  'check': 'CHECK',
};

api.post('/devices/:sn/:action', need('devices.manage'), h(async (req, res) => {
  const command = COMMANDS[req.params.action];
  if (!command) return res.status(400).json({ error: 'unknown action' });
  await pool.query(`INSERT INTO commands (sn, command) VALUES ($1, $2)`, [req.params.sn, command]);
  const NAMES = { 'sync-users': 'pull employee names', 'resend-logs': 're-pull all punches', check: 'check' };
  await audit(req, 'Device command', `${req.params.sn}: ${NAMES[req.params.action]}`);
  res.json({ ok: true });
}));

/* Users, roles and audit log */
const USERNAME_RE = /^[a-zA-Z0-9._-]{3,32}$/;

async function roleExists(id) {
  const { rows } = await pool.query('SELECT 1 FROM roles WHERE id = $1', [id]);
  return rows.length > 0;
}

api.post('/me/password', h(async (req, res) => {
  if (req.user.builtin) return res.status(400).json({ error: 'The admin password is set by the ADMIN_PASSWORD variable in Railway' });
  const { current, password } = req.body;
  const { rows } = await pool.query('SELECT password_hash FROM users WHERE id = $1', [req.user.id]);
  if (!rows.length || !auth.checkPassword(current, rows[0].password_hash)) {
    return res.status(400).json({ error: 'Current password is incorrect' });
  }
  if (String(password || '').length < 6) return res.status(400).json({ error: 'Password must be at least 6 characters' });
  // Bumping token_version ends other sessions; give this one a fresh cookie
  await pool.query('UPDATE users SET password_hash = $2, token_version = token_version + 1 WHERE id = $1',
    [req.user.id, auth.hashPassword(password)]);
  await audit(req, 'Change own password');
  res.json({ ok: true, relogin: true });
}));

api.get('/users', need('users.manage'), h(async (req, res) => {
  const { rows } = await pool.query(
    `SELECT u.id, u.username, u.name, u.role_id, r.name AS role, u.active, u.last_login, u.created_at
     FROM users u LEFT JOIN roles r ON r.id = u.role_id ORDER BY u.id`);
  res.json(rows);
}));

api.post('/users', need('users.manage'), h(async (req, res) => {
  const { username, name, password, role_id } = req.body;
  if (!USERNAME_RE.test(username || '')) {
    return res.status(400).json({ error: 'Username must be 3-32 letters, digits, dots, dashes or underscores, without spaces' });
  }
  if (username.toLowerCase() === auth.OWNER_USERNAME) return res.status(400).json({ error: 'The username admin is reserved' });
  if (String(password || '').length < 6) return res.status(400).json({ error: 'Password must be at least 6 characters' });
  if (!(await roleExists(role_id))) return res.status(400).json({ error: 'Select a role' });
  const { rows: dup } = await pool.query('SELECT 1 FROM users WHERE lower(username) = lower($1)', [username]);
  if (dup.length) return res.status(400).json({ error: 'That username is already taken' });
  await pool.query('INSERT INTO users (username, name, password_hash, role_id) VALUES ($1, $2, $3, $4)',
    [username, text(name), auth.hashPassword(password), role_id]);
  await audit(req, 'Add user', `${text(name) || username} (${username})`);
  res.json({ ok: true });
}));

api.put('/users/:id', need('users.manage'), h(async (req, res) => {
  const { name, role_id, active, password } = req.body;
  if (!(await roleExists(role_id))) return res.status(400).json({ error: 'Select a role' });
  if (Number(req.params.id) === req.user.id && active === false) {
    return res.status(400).json({ error: 'You cannot deactivate your own account' });
  }
  if (password && String(password).length < 6) return res.status(400).json({ error: 'Password must be at least 6 characters' });
  const { rows } = await pool.query(
    `UPDATE users SET name = $2, role_id = $3, active = $4 WHERE id = $1 RETURNING username`,
    [req.params.id, text(name), role_id, active !== false]);
  if (!rows.length) return res.status(404).json({ error: 'User not found' });
  if (password) {
    await pool.query('UPDATE users SET password_hash = $2, token_version = token_version + 1 WHERE id = $1',
      [req.params.id, auth.hashPassword(password)]);
  }
  await audit(req, 'Edit user',
    `${rows[0].username}${active === false ? ' (inactive)' : ''}${password ? ', password changed' : ''}`);
  res.json({ ok: true });
}));

api.delete('/users/:id', need('users.manage'), h(async (req, res) => {
  if (Number(req.params.id) === req.user.id) return res.status(400).json({ error: 'You cannot delete your own account' });
  const { rows } = await pool.query('DELETE FROM users WHERE id = $1 RETURNING username', [req.params.id]);
  if (rows.length) await audit(req, 'Delete user', rows[0].username);
  res.json({ ok: true });
}));

api.get('/roles', need('users.manage'), h(async (req, res) => {
  const { rows } = await pool.query(
    `SELECT r.*, (SELECT COUNT(*)::int FROM users u WHERE u.role_id = r.id) AS users
     FROM roles r ORDER BY r.is_system DESC, r.id`);
  res.json(rows.map((r) => (r.is_system ? { ...r, permissions: auth.ALL } : r)));
}));

function roleBody(b) {
  const permissions = (Array.isArray(b.permissions) ? b.permissions : []).filter((p) => auth.ALL.includes(p));
  return text(b.name) ? [text(b.name), permissions] : null;
}

api.post('/roles', need('users.manage'), h(async (req, res) => {
  const v = roleBody(req.body);
  if (!v) return res.status(400).json({ error: 'Enter a role name' });
  await pool.query('INSERT INTO roles (name, permissions) VALUES ($1, $2)', v);
  await audit(req, 'Add role', `${v[0]} (${v[1].length} permissions)`);
  res.json({ ok: true });
}));

api.put('/roles/:id', need('users.manage'), h(async (req, res) => {
  const v = roleBody(req.body);
  if (!v) return res.status(400).json({ error: 'Enter a role name' });
  // The system role always keeps every permission; only its name can change
  await pool.query(
    'UPDATE roles SET name = $2, permissions = CASE WHEN is_system THEN permissions ELSE $3 END WHERE id = $1',
    [req.params.id, ...v]);
  await audit(req, 'Edit role', `${v[0]} (${v[1].length} permissions)`);
  res.json({ ok: true });
}));

api.delete('/roles/:id', need('users.manage'), h(async (req, res) => {
  const { rows } = await pool.query(
    `SELECT name, is_system, (SELECT COUNT(*)::int FROM users WHERE role_id = $1) AS users FROM roles WHERE id = $1`,
    [req.params.id]);
  if (!rows.length) return res.json({ ok: true });
  if (rows[0].is_system) return res.status(400).json({ error: 'The system admin role cannot be deleted' });
  if (rows[0].users) return res.status(400).json({ error: 'This role still has users. Change their role first' });
  await pool.query('DELETE FROM roles WHERE id = $1', [req.params.id]);
  await audit(req, 'Delete role', rows[0].name);
  res.json({ ok: true });
}));

api.get('/audit', need('users.manage'), h(async (req, res) => {
  const { rows } = await pool.query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 300');
  res.json(rows);
}));

app.use('/api', api);

app.get('/export.xlsx', requireAuth, need('report.export'), h(async (req, res) => {
  const { from, to } = req.query;
  if (!rangeOk(from, to)) return res.status(400).send('bad date range');
  const wb = await buildWorkbook(from, to, intOrNull(req.query.department_id));
  res.set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  res.set('Content-Disposition', `attachment; filename="attendance_${from}_${to}.xlsx"`);
  await wb.xlsx.write(res);
  res.end();
}));

app.get('/timesheet.xlsx', requireAuth, need('report.export'), h(async (req, res) => {
  const month = String(req.query.month || '');
  if (!/^\d{4}-\d{2}$/.test(month)) return res.status(400).send('bad month');
  const { wb, period } = await buildTimesheet(month, intOrNull(req.query.department_id));
  await audit(req, 'Export timesheet', `${period.from} to ${period.to}`);
  res.set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  res.set('Content-Disposition', `attachment; filename="Timesheet_${month}.xlsx"`);
  await wb.xlsx.write(res);
  res.end();
}));

app.use('/static', express.static(path.join(__dirname, 'public')));

/* ------------------------------------------------------------------ */

init()
  .then(auth.initAuth)
  .then(() => {
    app.listen(PORT, () => console.log(`Attendance server listening on port ${PORT}`));
  })
  .catch((e) => {
    console.error('Database init failed', e);
    process.exit(1);
  });
