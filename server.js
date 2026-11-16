const express = require('express');
const crypto = require('crypto');
const path = require('path');
const { pool, init } = require('./db');
const importer = require('./lib/importer');
const zkLan = require('./lib/zk-lan');

const PORT = process.env.PORT || 3000;
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || '';
const SESSION_SECRET = process.env.SESSION_SECRET || crypto.randomBytes(32).toString('hex');
const DEVICE_TZ_OFFSET = process.env.DEVICE_TZ_OFFSET || '3'; // Saudi Arabia = +3
const WORK_START = process.env.WORK_START || '08:00';

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
          `INSERT INTO attendance (sn, pin, punch_time, status, verify, raw, source)
           SELECT $1::text, $2::text, $3::timestamp, $4::int, $5::int, $6::text, 'adms'
           WHERE NOT EXISTS (SELECT 1 FROM attendance WHERE pin = $2::text AND punch_time = $3::timestamp)
           ON CONFLICT DO NOTHING`,
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
const jsonParser = express.json();
// /api/import/records has its own (larger) JSON limit
app.use((req, res, next) => (req.path === '/api/import/records' ? next() : jsonParser(req, res, next)));

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
// Pass async errors to Express instead of crashing the process
const wrap = (fn) => (req, res, next) => Promise.resolve(fn(req, res, next)).catch(next);
for (const m of ['get', 'post', 'put', 'delete']) {
  const orig = api[m].bind(api);
  api[m] = (route, ...handlers) => orig(route, ...handlers.map(wrap));
}

const isDate = (s) => /^\d{4}-\d{2}-\d{2}$/.test(s || '');

api.get('/config', (req, res) => res.json({ workStart: WORK_START }));

api.get('/summary', async (req, res) => {
  const date = req.query.date;
  if (!isDate(date)) return res.status(400).json({ error: 'bad date' });
  const { rows } = await pool.query(
    `WITH day AS (
       SELECT pin, MIN(punch_time) AS first_in, MAX(punch_time) AS last_out, COUNT(*)::int AS punches
       FROM attendance WHERE punch_time >= $1::date AND punch_time < $1::date + 1
       GROUP BY pin
     )
     SELECT p.pin, e.name, d.first_in, d.last_out, COALESCE(d.punches, 0) AS punches
     FROM (SELECT pin FROM employees UNION SELECT pin FROM day) p
     LEFT JOIN employees e ON e.pin = p.pin
     LEFT JOIN day d ON d.pin = p.pin
     ORDER BY d.first_in NULLS LAST, p.pin`,
    [date]
  );
  res.json(rows);
});

// Builds "WHERE ..." for a date range plus optional employee / device filters
function rangeFilter(q, alias = 'a') {
  const { from, to, pin, sn } = q;
  if (!isDate(from) || !isDate(to)) return null;
  const params = [from, to];
  let where = `${alias}.punch_time >= $1::date AND ${alias}.punch_time < $2::date + 1`;
  if (pin) { params.push(String(pin)); where += ` AND ${alias}.pin = $${params.length}`; }
  if (sn) { params.push(String(sn)); where += ` AND ${alias}.sn = $${params.length}`; }
  return { where, params };
}

api.get('/punches', async (req, res) => {
  const f = rangeFilter(req.query);
  if (!f) return res.status(400).json({ error: 'bad date' });
  const { rows } = await pool.query(
    `SELECT a.id, a.pin, e.name, a.punch_time, a.verify, a.status, a.sn, a.source, d.name AS device_name
     FROM attendance a
     LEFT JOIN employees e ON e.pin = a.pin
     LEFT JOIN devices d ON d.sn = a.sn
     WHERE ${f.where} ORDER BY a.punch_time DESC LIMIT 5000`,
    f.params
  );
  res.json(rows);
});

// Which days have punches (for the calendar)
api.get('/days', async (req, res) => {
  const f = rangeFilter(req.query);
  if (!f) return res.status(400).json({ error: 'bad date' });
  const { rows } = await pool.query(
    `SELECT to_char(a.punch_time, 'YYYY-MM-DD') AS day, COUNT(*)::int AS punches,
            COUNT(DISTINCT a.pin)::int AS employees
     FROM attendance a WHERE ${f.where} GROUP BY 1 ORDER BY 1`,
    f.params
  );
  res.json(rows);
});

// First and last day that have any punch
api.get('/bounds', async (req, res) => {
  const { rows } = await pool.query(
    `SELECT to_char(MIN(punch_time), 'YYYY-MM-DD') AS first, to_char(MAX(punch_time), 'YYYY-MM-DD') AS last,
            COUNT(*)::int AS total FROM attendance`
  );
  res.json(rows[0]);
});

// Everything the dashboard needs for a period: one row per employee per day + punches per hour
api.get('/range', async (req, res) => {
  const f = rangeFilter(req.query);
  if (!f) return res.status(400).json({ error: 'bad date' });
  const days = (new Date(req.query.to) - new Date(req.query.from)) / 86400000;
  if (days < 0 || days > 400) return res.status(400).json({ error: 'Pick a period of 400 days or less' });
  const [daily, hours, employees] = await Promise.all([
    pool.query(
      `SELECT to_char(a.punch_time, 'YYYY-MM-DD') AS day, a.pin,
              MIN(a.punch_time) AS first_in, MAX(a.punch_time) AS last_out, COUNT(*)::int AS punches,
              array_agg(to_char(a.punch_time, 'HH24:MI') ORDER BY a.punch_time) AS times
       FROM attendance a WHERE ${f.where} GROUP BY 1, 2 ORDER BY 1, 3`,
      f.params
    ),
    pool.query(
      `SELECT EXTRACT(HOUR FROM a.punch_time)::int AS hour, COUNT(*)::int AS punches
       FROM attendance a WHERE ${f.where} GROUP BY 1 ORDER BY 1`,
      f.params
    ),
    pool.query(`SELECT pin, name FROM employees`),
  ]);
  res.json({ daily: daily.rows, hours: hours.rows, employees: employees.rows });
});

api.get('/employees', async (req, res) => {
  const { rows } = await pool.query(
    `SELECT e.pin, e.name, MAX(a.punch_time) AS last_punch, COUNT(a.id)::int AS total
     FROM employees e LEFT JOIN attendance a ON a.pin = e.pin
     GROUP BY e.pin, e.name
     ORDER BY CASE WHEN e.pin ~ '^[0-9]+$' THEN e.pin::bigint END NULLS LAST, e.pin`
  );
  res.json(rows);
});

api.put('/employees/:pin', async (req, res) => {
  const name = String(req.body.name || '').trim() || null;
  await pool.query(
    `INSERT INTO employees (pin, name) VALUES ($1, $2)
     ON CONFLICT (pin) DO UPDATE SET name = EXCLUDED.name`,
    [req.params.pin, name]
  );
  res.json({ ok: true });
});

/* ---------- Import: USB / file ---------- */

const bigText = express.text({ type: () => true, limit: '60mb' });

// POST raw file text. ?filename=&sn=&dateOrder=dmy|mdy&dry=1
api.post('/import/file', bigText, async (req, res) => {
  const text = typeof req.body === 'string' ? req.body : '';
  const filename = String(req.query.filename || '').slice(0, 200);
  const parsed = importer.parseAttendanceText(text, { filename, dateOrder: req.query.dateOrder === 'mdy' ? 'mdy' : 'dmy' });
  if (!parsed.rows.length) {
    return res.status(400).json({
      error: 'No punches found in this file. Supported: attlog.dat from the device USB export, ' +
        'or a CSV/TXT with an employee ID column and a date/time column.',
      format: parsed.format, skipped: parsed.skipped,
    });
  }
  if (parsed.rows.length > importer.MAX_ROWS) return res.status(400).json({ error: 'File is too large' });
  const summary = importer.summarise(parsed.rows);

  if (req.query.dry) {
    const existing = await importer.countExisting(pool, parsed.rows);
    return res.json({
      format: parsed.format, skipped: parsed.skipped, ...summary, existing,
      names: parsed.users.length, sample: parsed.rows.slice(0, 8).map(({ pin, time }) => ({ pin, time })),
    });
  }

  const sn = String(req.query.sn || '').trim() || 'USB';
  const result = await importer.savePunches(pool, parsed.rows, { sn, source: 'file' });
  const names = await importer.saveUsers(pool, parsed.users);
  await importer.logImport(pool, { source: 'file', label: filename, sn, result, names });
  console.log(`[IMPORT] file ${filename}: ${result.inserted} new of ${result.total}`);
  res.json({ ...summary, ...result, names, skipped: parsed.skipped });
});

// Punches as JSON - used by tools/lan-bridge.js running on a PC next to the device
api.post('/import/records', express.json({ limit: '60mb' }), async (req, res) => {
  const { rows, skipped } = importer.cleanRecords(req.body.records);
  const sn = String(req.body.sn || '').trim() || 'LAN';
  if (rows.length > importer.MAX_ROWS) return res.status(400).json({ error: 'Too many records' });
  if (sn !== 'LAN') {
    await pool.query(
      `INSERT INTO devices (sn, name) VALUES ($1, $2) ON CONFLICT (sn) DO NOTHING`,
      [sn, req.body.deviceName || null]);
    if (req.body.ip) await pool.query(`UPDATE devices SET lan_ip = $2 WHERE sn = $1`, [sn, String(req.body.ip)]);
  }
  const result = await importer.savePunches(pool, rows, { sn, source: 'lan' });
  const names = await importer.saveUsers(pool, req.body.users);
  if (result.inserted || names || req.body.log !== false) {
    await importer.logImport(pool, { source: 'lan-bridge', label: req.body.ip || null, sn, result, names });
  }
  res.json({ ...result, names, skipped });
});

api.get('/imports', async (req, res) => {
  const { rows } = await pool.query(`SELECT * FROM imports ORDER BY id DESC LIMIT 30`);
  res.json(rows);
});

/* ---------- LAN: talk to the device directly on port 4370 ---------- */

api.post('/lan/test', async (req, res) => {
  try {
    res.json(await zkLan.deviceInfo({ ip: String(req.body.ip || '').trim(), port: req.body.port }));
  } catch (e) {
    res.status(400).json({ error: e.message });
  }
});

api.post('/lan/pull', async (req, res) => {
  const ip = String(req.body.ip || '').trim();
  const port = parseInt(req.body.port, 10) || 4370;
  let data;
  try {
    data = await zkLan.pullFromDevice({ ip, port, timeout: 20000 });
  } catch (e) {
    return res.status(400).json({ error: e.message });
  }
  const sn = data.sn || `LAN-${ip}`;
  await pool.query(
    `INSERT INTO devices (sn, lan_ip, lan_port) VALUES ($1, $2, $3)
     ON CONFLICT (sn) DO UPDATE SET lan_ip = EXCLUDED.lan_ip, lan_port = EXCLUDED.lan_port`,
    [sn, ip, port]);
  const result = await importer.savePunches(pool, data.rows, { sn, source: 'lan' });
  const names = await importer.saveUsers(pool, data.users);
  await importer.logImport(pool, { source: 'lan', label: `${ip}:${port}`, sn, result, names });
  console.log(`[LAN] ${ip}: ${result.inserted} new of ${result.total}`);
  res.json({ sn, ...result, names, ...importer.summarise(data.rows) });
});

api.get('/devices', async (req, res) => {
  const { rows } = await pool.query(
    `SELECT d.*, (SELECT COUNT(*)::int FROM attendance a WHERE a.sn = d.sn) AS total,
            (SELECT COUNT(*)::int FROM commands c WHERE c.sn = d.sn AND c.status IN ('pending','sent')) AS pending
     FROM devices d ORDER BY last_seen DESC`
  );
  res.json(rows);
});

api.put('/devices/:sn', async (req, res) => {
  const name = String(req.body.name || '').trim() || null;
  await pool.query(`UPDATE devices SET name = $2 WHERE sn = $1`, [req.params.sn, name]);
  res.json({ ok: true });
});

// Queue commands for the device; it picks them up on its next /iclock/getrequest
const COMMANDS = {
  'sync-users': 'DATA QUERY USERINFO',
  'resend-logs': 'DATA QUERY ATTLOG StartTime=2000-01-01 00:00:00\tEndTime=2099-12-31 23:59:59',
  'check': 'CHECK',
};

api.post('/devices/:sn/:action', async (req, res) => {
  const command = COMMANDS[req.params.action];
  if (!command) return res.status(400).json({ error: 'unknown action' });
  await pool.query(`INSERT INTO commands (sn, command) VALUES ($1, $2)`, [req.params.sn, command]);
  res.json({ ok: true });
});

app.use('/api', api);
app.use('/api', (err, req, res, next) => {
  console.error('[API]', req.method, req.originalUrl, err);
  res.status(err.status || 500).json({ error: err.type === 'entity.too.large' ? 'File is too large' : 'Server error' });
});

app.get('/export.csv', requireAuth, async (req, res, next) => {
  try {
    const f = rangeFilter(req.query);
    if (!f) return res.status(400).send('bad date');
    const { rows } = await pool.query(
      `SELECT a.pin, e.name, to_char(a.punch_time, 'YYYY-MM-DD') AS day,
              to_char(a.punch_time, 'HH24:MI:SS') AS time, COALESCE(d.name, a.sn) AS device
       FROM attendance a
       LEFT JOIN employees e ON e.pin = a.pin
       LEFT JOIN devices d ON d.sn = a.sn
       WHERE ${f.where}
       ORDER BY a.punch_time`,
      f.params
    );
    const esc = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`;
    const lines = [['Employee ID', 'Name', 'Date', 'Time', 'Device'].map(esc).join(',')];
    for (const r of rows) lines.push([r.pin, r.name, r.day, r.time, r.device].map(esc).join(','));
    res.set('Content-Type', 'text/csv; charset=utf-8');
    res.set('Content-Disposition', `attachment; filename="attendance_${req.query.from}_${req.query.to}.csv"`);
    res.send('\uFEFF' + lines.join('\r\n'));
  } catch (e) { next(e); }
});

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
