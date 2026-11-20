// Read punches and users straight from a ZKTeco device over the network
// (ZK protocol, TCP/UDP port 4370). Used by the dashboard and by tools/lan-bridge.js.
const Zkteco = require('zkteco-js');

const pad = (n) => String(n).padStart(2, '0');
function fmt(d) {
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ` +
    `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

const VALID_IP = /^(?:(?:25[0-5]|2[0-4]\d|1?\d?\d)\.){3}(?:25[0-5]|2[0-4]\d|1?\d?\d)$|^[a-z0-9.-]+$/i;

async function withDevice({ ip, port = 4370, timeout = 10000 }, fn) {
  if (!ip || !VALID_IP.test(ip)) throw new Error('Invalid device IP address');
  port = parseInt(port, 10) || 4370;
  const dev = new Zkteco(ip, port, timeout, 5200);
  try {
    await dev.createSocket();
  } catch (e) {
    throw new Error(`Could not connect to ${ip}:${port}. Check the IP, that port ${port} is open, ` +
      `and that this server is on the same network as the device.`);
  }
  try {
    return await fn(dev);
  } finally {
    try { await dev.disconnect(); } catch (_) {}
  }
}

const safe = async (p) => { try { return await p; } catch (_) { return null; } };
const text = (v) => (v == null ? null : String(v).replace(/\0/g, '').replace(/^[^=]*=/, '').trim() || null);

async function deviceInfo(opts) {
  return withDevice(opts, async (dev) => {
    const info = (await safe(dev.getInfo())) || {};
    return {
      sn: text(await safe(dev.getSerialNumber())),
      name: text(await safe(dev.getDeviceName())),
      users: info.userCounts ?? null,
      logs: info.logCounts ?? null,
      capacity: info.logCapacity ?? null,
    };
  });
}

async function pullFromDevice(opts) {
  return withDevice(opts, async (dev) => {
    const sn = text(await safe(dev.getSerialNumber()));
    const usersRes = await safe(dev.getUsers());
    const users = ((usersRes && usersRes.data) || [])
      .map((u) => ({ pin: String(u.userId || u.uid || '').trim(), name: String(u.name || '').trim() }))
      .filter((u) => u.pin);
    const logs = await dev.getAttendances();
    const rows = ((logs && logs.data) || []).map((r) => {
      const d = new Date(r.record_time);
      return {
        pin: String(r.user_id || '').trim(),
        time: isNaN(d) ? null : fmt(d),
        status: Number.isFinite(r.state) ? r.state : null,
        verify: Number.isFinite(r.type) ? r.type : null,
      };
    }).filter((r) => r.pin && r.time);
    return { sn, users, rows };
  });
}

module.exports = { deviceInfo, pullFromDevice };
