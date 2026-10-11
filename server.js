const express = require('express');
const crypto = require('crypto');
const path = require('path');
const { pool, init } = require('./db');
const { compute, today, LEAVE_TYPES } = require('./attendance');
const { buildWorkbook } = require('./export');

const PORT = process.env.PORT || 3000;
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || '';
const SESSION_SECRET = process.env.SESSION_SECRET || crypto.randomBytes(32).toString('hex');
const DEVICE_TZ_OFFSET = process.env.DEVICE_TZ_OFFSET || '3'; // Saudi Arabia = +3

if (!ADMIN_PASSWORD) {
  console.warn('WARNING: ADMIN_PASSWORD is not set. The dashboard will refuse all logins until you set it.');
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

function sign(value) {
  return crypto.createHmac('sha256', SESSION_SECRET).update(value).digest('hex');
}

function makeToken() {
  const exp = String(Date.now() + 30 * 24 * 3600 * 1000);
  return `${exp}.${sign(exp)}`;
}

function validToken(token) {
  if (!token) return false;
  const [exp, sig] = token.split('.');
  if (!exp || !sig || Number(exp) < Date.now()) return false;
  const expected = sign(exp);
  return sig.length === expected.length &&
    crypto.timingSafeEqual(Buffer.from(sig), Buffer.from(expected));
}

function getCookie(req, name) {
  const header = req.headers.cookie || '';
  for (const part of header.split(';')) {
    const [k, ...v] = part.trim().split('=');
    if (k === name) return decodeURIComponent(v.join('='));
  }
  return null;
}

function requireAuth(req, res, next) {
  if (validToken(getCookie(req, 'sid'))) return next();
  if (req.originalUrl.startsWith('/api/')) return res.status(401).json({ error: 'unauthorized' });
  res.redirect('/login');
}

app.use(express.urlencoded({ extended: false }));
app.use(express.json());

app.get('/login', (req, res) => res.sendFile(path.join(__dirname, 'public', 'login.html')));

app.post('/login', (req, res) => {
  const given = Buffer.from(String(req.body.password || ''));
  const real = Buffer.from(ADMIN_PASSWORD);
  const ok = ADMIN_PASSWORD && given.length === real.length && crypto.timingSafeEqual(given, real);
  if (!ok) return res.redirect('/login?error=1');
  res.set('Set-Cookie',
    `sid=${encodeURIComponent(makeToken())}; Path=/; HttpOnly; SameSite=Lax; Max-Age=${30 * 24 * 3600}` +
    (req.secure ? '; Secure' : ''));
  res.redirect('/');
});

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

// Wrap async handlers so a DB error returns 500 instead of crashing the process
const h = (fn) => (req, res) => fn(req, res).catch((e) => {
  console.error('[API]', req.method, req.originalUrl, e.message);
  res.status(500).json({ error: e.message });
});

function rangeOk(from, to, maxDays = 366) {
  return isDate(from) && isDate(to) && from <= to &&
    (Date.parse(to) - Date.parse(from)) / 86400000 <= maxDays;
}

api.get('/meta', h(async (req, res) => {
  const [shifts, departments] = await Promise.all([
    pool.query('SELECT * FROM shifts ORDER BY is_default DESC, id'),
    pool.query('SELECT * FROM departments ORDER BY name'),
  ]);
  res.json({ today: today(), leaveTypes: LEAVE_TYPES, shifts: shifts.rows, departments: departments.rows });
}));

api.get('/summary', h(async (req, res) => {
  const date = req.query.date;
  if (!isDate(date)) return res.status(400).json({ error: 'bad date' });
  const { days } = await compute(date, date);
  res.json(days);
}));

api.get('/report', h(async (req, res) => {
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

api.post('/shifts', h(async (req, res) => {
  const v = shiftBody(req.body);
  if (!v) return res.status(400).json({ error: 'بيانات الوردية ناقصة' });
  const { rows } = await pool.query(
    `INSERT INTO shifts (name, start_time, end_time, grace_in, grace_out, weekend)
     VALUES ($1, $2, $3, $4, $5, $6) RETURNING *`, v);
  res.json(rows[0]);
}));

api.put('/shifts/:id', h(async (req, res) => {
  const v = shiftBody(req.body);
  if (!v) return res.status(400).json({ error: 'بيانات الوردية ناقصة' });
  await pool.query(
    `UPDATE shifts SET name = $2, start_time = $3, end_time = $4, grace_in = $5, grace_out = $6, weekend = $7
     WHERE id = $1`, [req.params.id, ...v]);
  if (req.body.is_default) {
    await pool.query('UPDATE shifts SET is_default = (id = $1)', [req.params.id]);
  }
  res.json({ ok: true });
}));

api.delete('/shifts/:id', h(async (req, res) => {
  const { rowCount } = await pool.query('DELETE FROM shifts WHERE id = $1 AND NOT is_default', [req.params.id]);
  if (!rowCount) return res.status(400).json({ error: 'لا يمكن حذف الوردية الافتراضية' });
  res.json({ ok: true });
}));

/* Departments */
api.post('/departments', h(async (req, res) => {
  if (!text(req.body.name)) return res.status(400).json({ error: 'اكتب اسم القسم' });
  const { rows } = await pool.query(
    'INSERT INTO departments (name, shift_id) VALUES ($1, $2) RETURNING *',
    [text(req.body.name), intOrNull(req.body.shift_id)]);
  res.json(rows[0]);
}));

api.put('/departments/:id', h(async (req, res) => {
  if (!text(req.body.name)) return res.status(400).json({ error: 'اكتب اسم القسم' });
  await pool.query('UPDATE departments SET name = $2, shift_id = $3 WHERE id = $1',
    [req.params.id, text(req.body.name), intOrNull(req.body.shift_id)]);
  res.json({ ok: true });
}));

api.delete('/departments/:id', h(async (req, res) => {
  await pool.query('DELETE FROM departments WHERE id = $1', [req.params.id]);
  res.json({ ok: true });
}));

/* Holidays */
api.get('/holidays', h(async (req, res) => {
  const { rows } = await pool.query('SELECT * FROM holidays ORDER BY start_date DESC LIMIT 300');
  res.json(rows);
}));

api.post('/holidays', h(async (req, res) => {
  const { name, start_date, end_date } = req.body;
  if (!text(name) || !rangeOk(start_date, end_date)) return res.status(400).json({ error: 'تحقق من الاسم والتواريخ' });
  await pool.query('INSERT INTO holidays (name, start_date, end_date) VALUES ($1, $2, $3)',
    [text(name), start_date, end_date]);
  res.json({ ok: true });
}));

api.delete('/holidays/:id', h(async (req, res) => {
  await pool.query('DELETE FROM holidays WHERE id = $1', [req.params.id]);
  res.json({ ok: true });
}));

/* Leaves */
api.get('/leaves', h(async (req, res) => {
  const { rows } = await pool.query(
    `SELECT l.*, e.name FROM leaves l LEFT JOIN employees e ON e.pin = l.pin
     WHERE l.end_date >= CURRENT_DATE - 120 ORDER BY l.start_date DESC LIMIT 500`);
  res.json(rows);
}));

api.post('/leaves', h(async (req, res) => {
  const { pin, type, start_date, end_date, note } = req.body;
  if (!text(pin) || !LEAVE_TYPES.includes(type) || !rangeOk(start_date, end_date)) {
    return res.status(400).json({ error: 'تحقق من الموظف والنوع والتواريخ' });
  }
  await pool.query(
    'INSERT INTO leaves (pin, type, start_date, end_date, note) VALUES ($1, $2, $3, $4, $5)',
    [text(pin), type, start_date, end_date, text(note)]);
  res.json({ ok: true });
}));

api.delete('/leaves/:id', h(async (req, res) => {
  await pool.query('DELETE FROM leaves WHERE id = $1', [req.params.id]);
  res.json({ ok: true });
}));

api.get('/punches', h(async (req, res) => {
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

api.get('/employees', h(async (req, res) => {
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
api.put('/employees/:pin', h(async (req, res) => {
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
  res.json({ ok: true });
}));

api.get('/devices', h(async (req, res) => {
  const { rows } = await pool.query(
    `SELECT d.*, (SELECT COUNT(*)::int FROM attendance a WHERE a.sn = d.sn) AS total,
            (SELECT COUNT(*)::int FROM commands c WHERE c.sn = d.sn AND c.status IN ('pending','sent')) AS pending
     FROM devices d ORDER BY last_seen DESC`
  );
  res.json(rows);
}));

api.put('/devices/:sn', h(async (req, res) => {
  const name = String(req.body.name || '').trim() || null;
  await pool.query(`UPDATE devices SET name = $2 WHERE sn = $1`, [req.params.sn, name]);
  res.json({ ok: true });
}));

// Queue commands for the device; it picks them up on its next /iclock/getrequest
const COMMANDS = {
  'sync-users': 'DATA QUERY USERINFO',
  'resend-logs': 'DATA QUERY ATTLOG StartTime=2000-01-01 00:00:00\tEndTime=2099-12-31 23:59:59',
  'check': 'CHECK',
};

api.post('/devices/:sn/:action', h(async (req, res) => {
  const command = COMMANDS[req.params.action];
  if (!command) return res.status(400).json({ error: 'unknown action' });
  await pool.query(`INSERT INTO commands (sn, command) VALUES ($1, $2)`, [req.params.sn, command]);
  res.json({ ok: true });
}));

app.use('/api', api);

app.get('/export.xlsx', requireAuth, h(async (req, res) => {
  const { from, to } = req.query;
  if (!rangeOk(from, to)) return res.status(400).send('bad date range');
  const wb = await buildWorkbook(from, to, intOrNull(req.query.department_id));
  res.set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  res.set('Content-Disposition', `attachment; filename="attendance_${from}_${to}.xlsx"`);
  await wb.xlsx.write(res);
  res.end();
}));

app.use('/static', express.static(path.join(__dirname, 'public')));

/* ------------------------------------------------------------------ */

init()
  .then(() => {
    app.listen(PORT, () => console.log(`Attendance server listening on port ${PORT}`));
  })
  .catch((e) => {
    console.error('Database init failed', e);
    process.exit(1);
  });
