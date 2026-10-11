const crypto = require('crypto');
const { pool } = require('./db');

const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || '';
const SESSION_SECRET = process.env.SESSION_SECRET || crypto.randomBytes(32).toString('hex');
const SESSION_MAX_AGE = 30 * 24 * 3600; // seconds

const PERMISSIONS = [
  { key: 'attendance.view', group: 'Attendance', label: 'View daily attendance' },
  { key: 'report.view', group: 'Attendance', label: 'View reports' },
  { key: 'report.export', group: 'Attendance', label: 'Export Excel and timesheet' },
  { key: 'punches.view', group: 'Attendance', label: 'View punch log' },
  { key: 'leaves.view', group: 'Leaves', label: 'View leaves and holidays' },
  { key: 'leaves.edit', group: 'Leaves', label: 'Add and delete employee leaves' },
  { key: 'holidays.edit', group: 'Leaves', label: 'Add and delete public holidays' },
  { key: 'employees.view', group: 'Employees', label: 'View employees' },
  { key: 'employees.edit', group: 'Employees', label: 'Edit employee details' },
  { key: 'settings.edit', group: 'Settings', label: 'Manage shifts and departments' },
  { key: 'devices.view', group: 'Settings', label: 'View devices' },
  { key: 'devices.manage', group: 'Settings', label: 'Send device commands and rename devices' },
  { key: 'users.manage', group: 'Settings', label: 'Manage users, permissions and the audit log' },
];
const ALL = PERMISSIONS.map((p) => p.key);

// Built-in owner account: username "admin", password from ADMIN_PASSWORD. Always has every permission,
// so there is no way to lock yourself out. Its sessions end when ADMIN_PASSWORD changes.
const OWNER_USERNAME = 'admin';
const ownerVersion = () => crypto.createHash('sha256').update(ADMIN_PASSWORD).digest('hex').slice(0, 8);
const OWNER = { id: 0, username: OWNER_USERNAME, name: 'System owner', role: 'Owner', builtin: true, permissions: ALL };

/* Passwords */
function hashPassword(password) {
  const salt = crypto.randomBytes(16);
  return salt.toString('hex') + ':' + crypto.scryptSync(password, salt, 64).toString('hex');
}

function checkPassword(password, stored) {
  const [salt, hash] = String(stored || '').split(':');
  if (!salt || !hash) return false;
  const a = crypto.scryptSync(String(password), Buffer.from(salt, 'hex'), 64);
  const b = Buffer.from(hash, 'hex');
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}

function safeEqual(a, b) {
  const x = Buffer.from(String(a));
  const y = Buffer.from(String(b));
  return x.length === y.length && crypto.timingSafeEqual(x, y);
}

/* Session tokens: "<userId>.<version>.<expiry>.<signature>" */
const sign = (value) => crypto.createHmac('sha256', SESSION_SECRET).update(value).digest('hex');

function makeToken(userId, version) {
  const payload = `${userId}.${version}.${Date.now() + SESSION_MAX_AGE * 1000}`;
  return `${payload}.${sign(payload)}`;
}

function parseToken(token) {
  const parts = String(token || '').split('.');
  if (parts.length !== 4) return null;
  const [id, version, exp, sig] = parts;
  if (Number(exp) < Date.now() || !safeEqual(sig, sign(`${id}.${version}.${exp}`))) return null;
  return { id: Number(id), version };
}

function getCookie(req, name) {
  for (const part of (req.headers.cookie || '').split(';')) {
    const [k, ...v] = part.trim().split('=');
    if (k === name) return decodeURIComponent(v.join('='));
  }
  return null;
}

async function loadUser(token) {
  const t = parseToken(token);
  if (!t) return null;
  if (t.id === 0) return ADMIN_PASSWORD && t.version === ownerVersion() ? OWNER : null;
  const { rows } = await pool.query(
    `SELECT u.id, u.username, u.name, u.active, u.token_version, r.name AS role, r.permissions, r.is_system
     FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = $1`,
    [t.id]
  );
  const u = rows[0];
  if (!u || !u.active || String(u.token_version) !== t.version) return null;
  return {
    id: u.id, username: u.username, name: u.name, role: u.role, builtin: false,
    permissions: u.is_system ? ALL : (u.permissions || []).filter((p) => ALL.includes(p)),
  };
}

/* Middleware */
async function requireAuth(req, res, next) {
  try {
    req.user = await loadUser(getCookie(req, 'sid'));
  } catch (e) {
    console.error('[AUTH]', e.message);
    req.user = null;
  }
  if (req.user) return next();
  if (req.originalUrl.startsWith('/api/')) return res.status(401).json({ error: 'unauthorized' });
  res.redirect('/login');
}

// Allow the request if the user has any of the given permissions
const need = (...perms) => (req, res, next) =>
  perms.some((p) => req.user.permissions.includes(p))
    ? next()
    : res.status(403).json({ error: 'You do not have permission to do this' });

/* Audit log */
async function audit(req, action, details) {
  try {
    await pool.query(
      'INSERT INTO audit_log (user_id, username, action, details, ip) VALUES ($1, $2, $3, $4, $5)',
      [req.user ? req.user.id : null, req.user ? req.user.username : null, action, details || null, req.ip]
    );
  } catch (e) {
    console.error('[AUDIT]', e.message);
  }
}

/* Login with simple brute-force protection: 10 failures per IP locks for 15 minutes */
const failures = new Map();

async function login(req, res) {
  const ip = req.ip;
  const f = failures.get(ip);
  if (f && f.count >= 10 && f.until > Date.now()) return res.redirect('/login?error=locked');

  const username = String(req.body.username || '').trim().toLowerCase();
  const password = String(req.body.password || '');
  let user = null;
  let version = null;

  if (username === OWNER_USERNAME) {
    if (ADMIN_PASSWORD && safeEqual(password, ADMIN_PASSWORD)) {
      user = OWNER;
      version = ownerVersion();
    }
  } else if (username) {
    const { rows } = await pool.query(
      'SELECT id, username, name, password_hash, token_version, active FROM users WHERE lower(username) = $1',
      [username]
    );
    const u = rows[0];
    if (u && u.active && checkPassword(password, u.password_hash)) {
      user = u;
      version = String(u.token_version);
      await pool.query('UPDATE users SET last_login = now() WHERE id = $1', [u.id]);
    }
  }

  if (!user) {
    const cur = failures.get(ip) || { count: 0, until: 0 };
    cur.count = cur.until < Date.now() ? 1 : cur.count + 1;
    cur.until = Date.now() + 15 * 60 * 1000;
    failures.set(ip, cur);
    return res.redirect('/login?error=1');
  }

  failures.delete(ip);
  req.user = user;
  await audit(req, 'Sign in');
  res.set('Set-Cookie',
    `sid=${encodeURIComponent(makeToken(user.id, version))}; Path=/; HttpOnly; SameSite=Lax; Max-Age=${SESSION_MAX_AGE}` +
    (req.secure ? '; Secure' : ''));
  res.redirect('/');
}

/* Seed default roles on first run */
async function initAuth() {
  // Rename the default roles created while the dashboard was Arabic
  await pool.query(`
    UPDATE roles SET name = CASE name WHEN 'مدير النظام' THEN 'System admin' WHEN 'مشرف' THEN 'Supervisor' ELSE 'Viewer' END
    WHERE name IN ('مدير النظام', 'مشرف', 'مشاهد')`);
  const { rows } = await pool.query('SELECT COUNT(*)::int AS n FROM roles');
  if (rows[0].n) return;
  const viewAll = ['attendance.view', 'report.view', 'punches.view', 'leaves.view', 'employees.view', 'devices.view'];
  await pool.query(
    `INSERT INTO roles (name, permissions, is_system) VALUES
       ('System admin', $1, true),
       ('Supervisor', $2, false),
       ('Viewer', $3, false)`,
    [ALL, [...viewAll, 'report.export', 'leaves.edit', 'employees.edit'], viewAll]
  );
}

module.exports = {
  PERMISSIONS, ALL, OWNER_USERNAME, hashPassword, checkPassword,
  requireAuth, need, audit, login, initAuth,
};
