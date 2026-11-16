// Parsing and saving punches that come from a file (USB export, CSV/TXT)
// or from a device on the LAN. ADMS punches are saved in server.js.

const MAX_ROWS = 500000;

const pad = (n) => String(n).padStart(2, '0');

// Normalise many date/time spellings into "YYYY-MM-DD HH:MM:SS".
// dateOrder: 'dmy' | 'mdy' (only used when the year is last and both parts are <= 12)
function normaliseDateTime(dateStr, timeStr, dateOrder = 'dmy') {
  let s = `${dateStr || ''} ${timeStr || ''}`.trim().replace(/\s+/g, ' ');
  if (!s) return null;
  s = s.replace('T', ' ').replace(/\.\d+$/, '').replace(/Z$|[+-]\d{2}:?\d{2}$/, '');

  let y, m, d, rest;
  let mt = s.match(/^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})\s*(.*)$/);
  if (mt) { [, y, m, d, rest] = mt; }
  else {
    mt = s.match(/^(\d{1,2})[-/.](\d{1,2})[-/.](\d{2,4})\s*(.*)$/);
    if (!mt) return null;
    let a = +mt[1], b = +mt[2];
    y = mt[3].length === 2 ? '20' + mt[3] : mt[3];
    rest = mt[4];
    if (a > 12) { d = a; m = b; }
    else if (b > 12) { m = a; d = b; }
    else if (dateOrder === 'mdy') { m = a; d = b; }
    else { d = a; m = b; }
  }

  const tm = (rest || '').match(/^(\d{1,2}):(\d{2})(?::(\d{2}))?\s*([AaPp][Mm])?/);
  if (!tm) return null;
  let h = +tm[1];
  const ap = (tm[4] || '').toLowerCase();
  if (ap === 'pm' && h < 12) h += 12;
  if (ap === 'am' && h === 12) h = 0;
  const out = { y: +y, m: +m, d: +d, h, mi: +tm[2], s: +(tm[3] || 0) };
  if (out.m < 1 || out.m > 12 || out.d < 1 || out.d > 31 || out.h > 23 || out.mi > 59 || out.s > 59) return null;
  if (out.y < 2000 || out.y > 2099) return null;
  return `${out.y}-${pad(out.m)}-${pad(out.d)} ${pad(out.h)}:${pad(out.mi)}:${pad(out.s)}`;
}

const int = (v) => { const n = parseInt(v, 10); return Number.isFinite(n) ? n : null; };

// --- ZKTeco text logs: attlog.dat from a USB stick, or the ADMS ATTLOG format ---
// "  1\t2026-11-16 08:01:22\t1\t0\t1\t0"
const ZK_LINE = /^\s*([^\s,;]+)\s+(\d{4}-\d{1,2}-\d{1,2})[ T](\d{1,2}:\d{2}(?::\d{2})?)\s*(.*)$/;

function parseZkText(lines) {
  const rows = [];
  let skipped = 0;
  for (const line of lines) {
    const mt = line.match(ZK_LINE);
    if (!mt) { skipped++; continue; }
    const time = normaliseDateTime(mt[2], mt[3]);
    if (!time) { skipped++; continue; }
    const extra = mt[4].trim() ? mt[4].trim().split(/\s+/) : [];
    let status = null, verify = null;
    if (extra.length === 4) { status = int(extra[1]); verify = int(extra[2]); } // attlog.dat: dev, state, verify, workcode
    else if (extra.length >= 2) { status = int(extra[0]); verify = int(extra[1]); } // ADMS: state, verify, ...
    rows.push({ pin: mt[1], time, status, verify, raw: line.trim() });
  }
  return { rows, skipped };
}

// --- CSV / TSV with a header row (this dashboard's export, ZKTeco software exports, Excel "Save as CSV") ---
function splitCsvLine(line, delim) {
  const out = [];
  let cur = '', q = false;
  for (let i = 0; i < line.length; i++) {
    const c = line[i];
    if (q) {
      if (c === '"' && line[i + 1] === '"') { cur += '"'; i++; }
      else if (c === '"') q = false;
      else cur += c;
    } else if (c === '"') q = true;
    else if (c === delim) { out.push(cur); cur = ''; }
    else cur += c;
  }
  out.push(cur);
  return out.map((v) => v.trim());
}

const HEADERS = {
  pin: /^(employee ?(id|no\.?|number|code)|emp\.? ?(id|no\.?|code)|user ?(id|no\.?)|ac-?no\.?|pin|id|no\.?|badge.*|enroll.*|person ?id|staff ?(id|no\.?)|personnel ?(id|no\.?))$/i,
  name: /^(name|employee ?name|emp\.? ?name|user ?name|full ?name|first ?name|person ?name)$/i,
  datetime: /^(date ?time|date ?& ?time|date and time|time ?stamp|check ?time|punch ?time|clock ?time|att(endance)? ?time|record ?time|log ?time|verify ?time)$/i,
  date: /^(date|day|att(endance)? ?date|punch ?date|check ?date)$/i,
  time: /^(time|punch|clock|check ?in.?out time|hour)$/i,
  device: /^(device|terminal|machine|device ?name|terminal ?(name|sn)|sn|serial.*)$/i,
};

function parseCsv(lines, dateOrder) {
  const delim = [',', ';', '\t'].map((d) => [d, lines[0].split(d).length]).sort((a, b) => b[1] - a[1])[0][0];
  // find the header line in the first 10 lines
  let headerIdx = -1, cols = {};
  for (let i = 0; i < Math.min(10, lines.length); i++) {
    const cells = splitCsvLine(lines[i].replace(/^﻿/, ''), delim);
    const found = {};
    cells.forEach((c, idx) => {
      const name = c.replace(/[_:/]/g, ' ').replace(/\s+/g, ' ').trim();
      for (const [key, re] of Object.entries(HEADERS)) {
        if (found[key] === undefined && re.test(name)) { found[key] = idx; break; }
      }
    });
    // a lone "Time" column usually holds the full date and time (ZKTeco software exports)
    if (found.datetime === undefined && found.date === undefined && found.time !== undefined) {
      found.datetime = found.time; delete found.time;
    }
    if (found.pin !== undefined && (found.datetime !== undefined || found.date !== undefined)) {
      headerIdx = i; cols = found; break;
    }
  }
  if (headerIdx < 0) return null;

  const rows = [];
  const users = new Map();
  let skipped = 0;
  for (const line of lines.slice(headerIdx + 1)) {
    const c = splitCsvLine(line, delim);
    const pin = (c[cols.pin] || '').trim();
    let time = null;
    if (cols.datetime !== undefined) time = normaliseDateTime(c[cols.datetime], '', dateOrder);
    if (!time && cols.date !== undefined) time = normaliseDateTime(c[cols.date], cols.time !== undefined ? c[cols.time] : '', dateOrder);
    if (!pin || !time) { skipped++; continue; }
    rows.push({ pin, time, status: null, verify: null, raw: line.trim() });
    const name = cols.name !== undefined ? (c[cols.name] || '').trim() : '';
    if (name && !users.has(pin)) users.set(pin, name);
  }
  return { rows, skipped, users: [...users].map(([pin, name]) => ({ pin, name })) };
}

function parseAttendanceText(text, { filename = '', dateOrder = 'dmy' } = {}) {
  const lines = String(text || '').replace(/^﻿/, '').split(/\r?\n/).filter((l) => l.trim());
  if (!lines.length) return { format: 'empty', rows: [], users: [], skipped: 0 };

  // Try the ZKTeco text log first; if most lines match, use it.
  const zk = parseZkText(lines);
  if (zk.rows.length && zk.rows.length >= lines.length * 0.6) {
    return { format: /\.dat$/i.test(filename) ? 'ZKTeco USB log (attlog.dat)' : 'ZKTeco text log', ...zk, users: [] };
  }
  const csv = parseCsv(lines, dateOrder);
  if (csv && csv.rows.length) return { format: 'CSV / spreadsheet', ...csv };
  if (zk.rows.length) return { format: 'ZKTeco text log', ...zk, users: [] };
  return { format: 'unknown', rows: [], users: [], skipped: lines.length };
}

function summarise(rows) {
  if (!rows.length) return { count: 0, employees: 0, first: null, last: null };
  let first = rows[0].time, last = rows[0].time;
  const pins = new Set();
  for (const r of rows) {
    if (r.time < first) first = r.time;
    if (r.time > last) last = r.time;
    pins.add(r.pin);
  }
  return { count: rows.length, employees: pins.size, first, last };
}

// How many of these rows are already in the database (same employee + same second)
async function countExisting(pool, rows) {
  let existing = 0;
  for (let i = 0; i < rows.length; i += 5000) {
    const chunk = rows.slice(i, i + 5000);
    const { rows: r } = await pool.query(
      `SELECT COUNT(*)::int AS n FROM attendance a
       JOIN unnest($1::text[], $2::timestamp[]) AS x(pin, t) ON a.pin = x.pin AND a.punch_time = x.t`,
      [chunk.map((x) => x.pin), chunk.map((x) => x.time)]
    );
    existing += r[0].n;
  }
  return existing;
}

// Insert punches, skipping any that already exist for the same employee at the same second
async function savePunches(pool, rows, { sn, source }) {
  let inserted = 0;
  const seen = new Set();
  const unique = rows.filter((r) => {
    const k = r.pin + '|' + r.time;
    if (seen.has(k)) return false;
    seen.add(k);
    return true;
  });
  for (let i = 0; i < unique.length; i += 2000) {
    const c = unique.slice(i, i + 2000);
    const res = await pool.query(
      `INSERT INTO attendance (sn, pin, punch_time, status, verify, raw, source)
       SELECT $1, x.pin, x.t, x.st, x.vf, x.raw, $7
       FROM unnest($2::text[], $3::timestamp[], $4::int[], $5::int[], $6::text[]) AS x(pin, t, st, vf, raw)
       WHERE NOT EXISTS (SELECT 1 FROM attendance a WHERE a.pin = x.pin AND a.punch_time = x.t)
       ON CONFLICT DO NOTHING`,
      [sn, c.map((r) => r.pin), c.map((r) => r.time), c.map((r) => r.status), c.map((r) => r.verify),
        c.map((r) => r.raw || null), source]
    );
    inserted += res.rowCount;
  }
  const pins = [...new Set(unique.map((r) => r.pin))];
  if (pins.length) {
    await pool.query(
      `INSERT INTO employees (pin) SELECT unnest($1::text[]) ON CONFLICT DO NOTHING`, [pins]);
  }
  return { total: rows.length, unique: unique.length, inserted, duplicates: unique.length - inserted };
}

// Fill in names we don't have yet (never overwrite a name typed in the dashboard)
async function saveUsers(pool, users) {
  const list = (users || []).filter((u) => u && u.pin && u.name);
  if (!list.length) return 0;
  const res = await pool.query(
    `INSERT INTO employees (pin, name)
     SELECT * FROM unnest($1::text[], $2::text[])
     ON CONFLICT (pin) DO UPDATE SET name = EXCLUDED.name WHERE employees.name IS NULL OR employees.name = ''`,
    [list.map((u) => String(u.pin)), list.map((u) => String(u.name))]
  );
  return res.rowCount;
}

async function logImport(pool, { source, label, sn, result, names, username }) {
  await pool.query(
    `INSERT INTO imports (source, label, sn, total, inserted, duplicates, names, username)
     VALUES ($1, $2, $3, $4, $5, $6, $7, $8)`,
    [source, label || null, sn || null, result.total, result.inserted, result.duplicates, names || 0, username || null]
  );
}

// Clean rows sent as JSON (LAN bridge)
function cleanRecords(records) {
  const rows = [];
  let skipped = 0;
  for (const r of records || []) {
    const pin = String(r.pin ?? '').trim();
    const time = normaliseDateTime(String(r.time ?? ''), '');
    if (!pin || !time) { skipped++; continue; }
    rows.push({ pin, time, status: int(r.status), verify: int(r.verify), raw: r.raw ? String(r.raw).slice(0, 500) : null });
  }
  return { rows, skipped };
}

module.exports = {
  MAX_ROWS, parseAttendanceText, normaliseDateTime, summarise, countExisting,
  savePunches, saveUsers, logImport, cleanRecords,
};
