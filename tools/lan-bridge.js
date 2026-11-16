#!/usr/bin/env node
// LAN bridge: run this on any PC on the same network as the device.
// It reads punches from the device over the LAN (port 4370) and sends them to the server.
//
//   node tools/lan-bridge.js --device 192.168.1.201 --server https://your-app.up.railway.app --user admin --password YOUR_PASSWORD
//
// The dashboard user needs the "Import punches from USB, files or the LAN" permission.
//
// Options:
//   --device   Device IP address on the LAN (required)
//   --port     Device port (default 4370)
//   --server   Dashboard URL (required)
//   --user     Dashboard username (default: admin)
//   --password Dashboard password (or set the BRIDGE_PASSWORD environment variable)
//   --every    Repeat every N minutes (default: run once and exit)

const { pullFromDevice } = require('../lib/zk-lan');

function args() {
  const out = {};
  const a = process.argv.slice(2);
  for (let i = 0; i < a.length; i++) {
    if (a[i].startsWith('--')) {
      const key = a[i].slice(2);
      const next = a[i + 1];
      out[key] = next && !next.startsWith('--') ? (i++, next) : true;
    }
  }
  return out;
}

const opt = args();
const server = String(opt.server || '').replace(/\/+$/, '');
const username = opt.user || process.env.BRIDGE_USER || 'admin';
const password = opt.password || process.env.BRIDGE_PASSWORD;
const every = parseFloat(opt.every) || 0;

if (!opt.device || !server || !password) {
  console.log('Usage: node tools/lan-bridge.js --device <ip> --server <url> [--user admin] --password <password> [--port 4370] [--every 5]');
  process.exit(1);
}

let cookie = null;
let lastSent = null; // newest punch time already sent

async function login() {
  const res = await fetch(server + '/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'username=' + encodeURIComponent(username) + '&password=' + encodeURIComponent(password),
    redirect: 'manual',
  });
  const set = (res.headers.getSetCookie ? res.headers.getSetCookie() : [res.headers.get('set-cookie')]).join(';');
  const m = set.match(/sid=([^;]+)/);
  if (!m || !m[1]) throw new Error('Login failed: wrong username/password or wrong server URL');
  cookie = 'sid=' + m[1];
}

async function send(payload) {
  if (!cookie) await login();
  let res = await fetch(server + '/api/import/records', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Cookie: cookie },
    body: JSON.stringify(payload),
  });
  if (res.status === 401) {
    await login();
    res = await fetch(server + '/api/import/records', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Cookie: cookie },
      body: JSON.stringify(payload),
    });
  }
  if (res.status === 403) throw new Error(`The user "${username}" does not have the import permission`);
  if (!res.ok) throw new Error(`Server replied ${res.status}: ${await res.text()}`);
  return res.json();
}

async function runOnce() {
  const started = new Date().toLocaleString('en-GB');
  const data = await pullFromDevice({ ip: opt.device, port: opt.port || 4370, timeout: 20000 });
  // After the first run only send the last day of punches again (duplicates are ignored anyway)
  let rows = data.rows;
  if (lastSent) {
    const since = new Date(new Date(lastSent.replace(' ', 'T')).getTime() - 86400000);
    rows = rows.filter((r) => new Date(r.time.replace(' ', 'T')) >= since);
  }
  const result = await send({ sn: data.sn, ip: opt.device, records: rows, users: data.users, log: !lastSent });
  for (const r of data.rows) if (!lastSent || r.time > lastSent) lastSent = r.time;
  console.log(`[${started}] device ${data.sn || opt.device}: ${data.rows.length} punches on device, ` +
    `${rows.length} sent, ${result.inserted} new, ${result.names} names added`);
}

(async function loop() {
  try {
    await runOnce();
  } catch (e) {
    console.error(`[${new Date().toLocaleString('en-GB')}] ${e.message}`);
    if (!every) process.exit(1);
  }
  if (every) setTimeout(loop, every * 60000);
})();
