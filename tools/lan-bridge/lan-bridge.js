#!/usr/bin/env node
// LAN bridge: run this on any PC on the same network as the device.
// It reads punches from the device over the LAN (port 4370) and sends them to the server.
//
//   cd tools/lan-bridge && npm install          (once)
//   node tools/lan-bridge/lan-bridge.js --device 192.168.1.201 --server https://your-app.up.railway.app --token YOUR_TOKEN --every 5
//
// The token: sign in, My account -> API tokens. Its owner needs the
// "Import punches from USB, files or the LAN" permission (and "Create API tokens").
//
// Options:
//   --device   Device IP address on the LAN (required)
//   --port     Device port (default 4370)
//   --server   Dashboard URL (required)
//   --token    API token (or set the BRIDGE_TOKEN environment variable)
//   --every    Repeat every N minutes (default: run once and exit)

const { pullFromDevice } = require('./zk-lan');

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
const token = opt.token || process.env.BRIDGE_TOKEN;
const every = parseFloat(opt.every) || 0;

if (!opt.device || !server || !token) {
  console.log('Usage: node tools/lan-bridge/lan-bridge.js --device <ip> --server <url> --token <api token> [--port 4370] [--every 5]');
  process.exit(1);
}

let lastSent = null; // newest punch time already sent

async function send(payload) {
  const res = await fetch(server + '/api/v1/punches/import', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: 'Bearer ' + token },
    body: JSON.stringify(payload),
  });
  if (res.status === 401) throw new Error('The token was refused: check it, or create a new one under My account -> API tokens');
  if (res.status === 403) throw new Error('The token owner does not have the import permission');
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
