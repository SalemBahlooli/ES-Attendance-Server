/* Import data: USB / file upload, direct LAN pull, LAN bridge instructions and history */

let impText = null, impName = '';
let impDevices = [];

// Files from the device or Excel can be UTF-8, UTF-16 or Windows Arabic
function impDecode(buf) {
  const b = new Uint8Array(buf);
  if (b[0] === 0xff && b[1] === 0xfe) return new TextDecoder('utf-16le').decode(buf);
  if (b[0] === 0xfe && b[1] === 0xff) return new TextDecoder('utf-16be').decode(buf);
  let zeros = 0;
  const n = Math.min(b.length, 400);
  for (let i = 1; i < n; i += 2) if (b[i] === 0) zeros++;
  if (b.length > 20 && zeros > (n / 2) * 0.6) return new TextDecoder('utf-16le').decode(buf);
  try { return new TextDecoder('utf-8', { fatal: true }).decode(buf); }
  catch (_) { return new TextDecoder('windows-1256').decode(buf); }
}

const impPreview = (html) => { $('impPreview').innerHTML = html; };
const impDay = (ts) => niceDate(ts.slice(0, 10), { year: true });

async function impChoose(file) {
  if (!file) return;
  if (file.size > 60 * 1024 * 1024) return impPreview('<div class="result err">The file is larger than 60 MB.</div>');
  impName = file.name;
  impText = impDecode(await file.arrayBuffer());
  impCheck();
}

function impPost(extra) {
  const p = new URLSearchParams({ filename: impName, dateOrder: $('impDateOrder').value, ...extra });
  return api('/api/import/file?' + p, { method: 'POST', headers: { 'Content-Type': 'text/plain; charset=utf-8' }, body: impText });
}

async function impCheck() {
  if (!impText) return;
  impPreview('<div class="result muted">Reading the file…</div>');
  let r;
  try { r = await impPost({ dry: '1' }); }
  catch (e) { return impPreview(`<div class="result err">${esc(e.message || 'Could not read this file')}</div>`); }
  if (r.kind === 'branch') return impBranchPreview(r);
  const fresh = Math.max(0, r.count - r.existing);
  impPreview(`<div class="result">
    <strong>${esc(impName)}</strong>
    <dl class="kv">
      <dt>Format</dt><dd>${esc(r.format)}</dd>
      <dt>Punches found</dt><dd>${r.count}</dd>
      <dt>Employees</dt><dd>${r.employees}${r.names ? ` (${r.names} with names)` : ''}</dd>
      <dt>Period</dt><dd>${impDay(r.first)} – ${impDay(r.last)}</dd>
      <dt>Already saved</dt><dd>${r.existing}</dd>
      <dt>New punches</dt><dd><b>${fresh}</b></dd>
      ${r.skipped ? `<dt>Lines skipped</dt><dd>${r.skipped}</dd>` : ''}
    </dl>
    <table><thead><tr><th>ID</th><th>Date and time</th></tr></thead><tbody>
      ${r.sample.map((s) => `<tr><td class="num">${esc(s.pin)}</td><td class="num">${esc(s.time)}</td></tr>`).join('')}
    </tbody></table>
    <div class="actions">
      <button class="btn primary" id="impGo" ${fresh ? '' : 'disabled'}>${fresh ? `Import ${fresh} new punches` : 'Nothing new to import'}</button>
      <button class="btn" id="impCancel">Cancel</button>
    </div></div>`);
  $('impGo').onclick = impCommit;
  $('impCancel').onclick = impReset;
}

function impBranchPreview(r) {
  const fresh = r.new_punches;
  const newEmps = r.employees.filter((e) => !e.exists).length;
  const anything = fresh || newEmps || r.removed || r.leaves;
  impPreview(`<div class="result">
    <strong>File from ${esc(r.branch && r.branch.name || 'another branch')}</strong>
    <dl class="kv">
      <dt>Period</dt><dd>${niceDate(r.period.from, { year: true })} – ${niceDate(r.period.to, { year: true })}</dd>
      <dt>Made by</dt><dd>${esc(r.exported_by || '—')} · ${dateTime(r.exported_at)}</dd>
      <dt>Employees</dt><dd>${r.employees.length}${newEmps ? ` (${newEmps} new here)` : ' (all already here)'}</dd>
      <dt>Punches</dt><dd>${r.punches}</dd>
      <dt>New punches</dt><dd><b>${fresh}</b></dd>
      ${r.removed ? `<dt>Removed there</dt><dd>${r.removed} (removed here too, if present)</dd>` : ''}
      ${r.leaves ? `<dt>Leaves</dt><dd>${r.leaves}</dd>` : ''}
    </dl>
    <details><summary>Employees in this file</summary>
      <table><thead><tr><th>ID</th><th>Name</th><th>Department</th><th></th></tr></thead><tbody>
        ${r.employees.map((e) => `<tr><td class="num">${esc(e.pin)}</td><td>${nameCell(e.name)}</td><td>${esc(e.department) || '—'}</td>
          <td>${e.exists ? '<span class="pill off">Already here</span>' : '<span class="pill info">New</span>'}</td></tr>`).join('')}
      </tbody></table>
    </details>
    ${r.leaves ? '<label class="check"><input type="checkbox" id="impLeaves" checked> Also import their leaves</label>' : ''}
    <div class="actions">
      <button class="btn primary" id="impGo">${anything ? 'Import' : 'Import anyway'}</button>
      <button class="btn" id="impCancel">Cancel</button>
    </div></div>`);
  $('impGo').onclick = impCommit;
  $('impCancel').onclick = impReset;
}

async function impCommit() {
  $('impGo').disabled = true;
  $('impGo').textContent = 'Importing…';
  let r;
  const extra = $('impLeaves') && !$('impLeaves').checked ? { leaves: '0' } : {};
  try { r = await impPost({ sn: $('impSn').value || 'USB', ...extra }); }
  catch (e) { return impPreview(`<div class="result err">${esc(e.message || 'Import failed')}</div>`); }
  if (r.kind === 'branch') {
    impText = null;
    $('impFile').value = '';
    impPreview(`<div class="result ok"><strong>Imported from ${esc(r.branchName)}</strong>
      <span>${r.inserted} new punches${r.duplicates ? ` (${r.duplicates} were already here)` : ''}, ${r.newEmployees} new employees${r.removed ? `, ${r.removed} punches removed` : ''}${r.leaves ? `, ${r.leaves} leaves` : ''}.</span>
      ${canTab('overview') ? '<div class="actions"><button class="btn small" id="impView">View these days</button></div>' : ''}</div>`);
    if ($('impView')) $('impView').onclick = () => {
      const last = r.period.to < meta.today ? r.period.to : meta.today;
      ov.from = r.period.from; ov.to = last; ov.pin = ''; ov.calMonth = last.slice(0, 7);
      showTab('overview');
    };
    return impAfterChange();
  }
  impText = null;
  $('impFile').value = '';
  impPreview(`<div class="result ok"><strong>Import complete</strong>
    <span>${r.inserted} new punches saved, ${r.duplicates} were already saved${r.names ? `, ${r.names} names added` : ''}.</span>
    ${canTab('overview') ? '<div class="actions"><button class="btn small" id="impView">View these days</button></div>' : ''}</div>`);
  if ($('impView')) $('impView').onclick = () => {
    const last = r.last.slice(0, 10);
    const first = r.first.slice(0, 10) < addDays(last, -365) ? addDays(last, -365) : r.first.slice(0, 10);
    ov.from = first; ov.to = last; ov.calMonth = last.slice(0, 7);
    showTab('overview');
  };
  impAfterChange();
}

function impReset() { impText = null; $('impFile').value = ''; impPreview(''); }
function impAfterChange() {
  calCache.clear();
  ovBounds = null;
  ov.data = null;
  report = null;
  loadImports();
  loadEmployeeList().catch(() => {});
}

$('impFile').onchange = () => impChoose($('impFile').files[0]);
$('impDateOrder').onchange = impCheck;
const impDrop = $('impDrop');
['dragenter', 'dragover'].forEach((ev) => impDrop.addEventListener(ev, (e) => { e.preventDefault(); impDrop.classList.add('over'); }));
['dragleave', 'drop'].forEach((ev) => impDrop.addEventListener(ev, (e) => { e.preventDefault(); impDrop.classList.remove('over'); }));
impDrop.addEventListener('drop', (e) => impChoose(e.dataTransfer.files[0]));

/* LAN */
const lanBody = () => JSON.stringify({ ip: $('lanIp').value.trim(), port: $('lanPort').value.trim() || 4370 });
const lanResult = (html) => { $('lanResult').innerHTML = html; };
function bridgeCmd() {
  const ip = $('lanIp').value.trim() || '192.168.1.201';
  const port = $('lanPort').value.trim();
  const user = meta.me.username || 'admin';
  $('bridgeCmd').textContent = `node tools/lan-bridge.js --device ${ip}${port && port !== '4370' ? ` --port ${port}` : ''} ` +
    `--server ${location.origin} --user ${user} --password YOUR_PASSWORD --every 5`;
}
$('lanIp').oninput = bridgeCmd;
$('lanPort').oninput = bridgeCmd;
$('copyBridge').onclick = async () => {
  try { await navigator.clipboard.writeText($('bridgeCmd').textContent); toast('Copied'); }
  catch (_) { toast('Select the text and copy it'); }
};

$('lanTest').onclick = async () => {
  if (!$('lanIp').value.trim()) return toast('Enter the device IP address');
  lanResult('<div class="result muted">Connecting…</div>');
  try {
    const r = await api('/api/lan/test', { method: 'POST', body: lanBody() });
    lanResult(`<div class="result ok"><strong>Connected</strong><dl class="kv">
      <dt>Serial number</dt><dd>${esc(r.sn || '—')}</dd><dt>Device name</dt><dd>${esc(r.name || '—')}</dd>
      <dt>Users</dt><dd>${r.users ?? '—'}</dd><dt>Punches stored</dt><dd>${r.logs ?? '—'}</dd></dl></div>`);
  } catch (e) { lanResult(`<div class="result err">${esc(e.message || 'Could not connect')}</div>`); }
};

async function lanPull(ip, port) {
  const r = await api('/api/lan/pull', { method: 'POST', body: JSON.stringify({ ip, port }) });
  impAfterChange();
  return r;
}

$('lanPull').onclick = async () => {
  if (!$('lanIp').value.trim()) return toast('Enter the device IP address');
  $('lanPull').disabled = true;
  lanResult('<div class="result muted">Reading punches from the device. This can take a minute for large logs…</div>');
  try {
    const r = await lanPull($('lanIp').value.trim(), $('lanPort').value.trim() || 4370);
    lanResult(`<div class="result ok"><strong>Done</strong>
      <span>${r.total} punches on the device, ${r.inserted} new saved, ${r.duplicates} already saved${r.names ? `, ${r.names} names added` : ''}.</span></div>`);
  } catch (e) { lanResult(`<div class="result err">${esc(e.message || 'Could not read the device')}</div>`); }
  $('lanPull').disabled = false;
};

/* History */
const IMP_SRC = { file: 'File / USB', lan: 'LAN', 'lan-bridge': 'LAN bridge', branch: 'Other branch' };
async function loadImports() {
  const rows = await api('/api/imports');
  $('impRows').innerHTML = rows.length ? rows.map((r) => `<tr>
    <td class="num">${dateTime(r.created_at)}</td>
    <td>${IMP_SRC[r.source] || esc(r.source)}</td><td>${esc(r.label || '—')}</td><td>${esc(r.sn || '—')}</td>
    <td class="num">${r.total}</td><td class="num">${r.inserted}</td><td class="num">${r.duplicates}</td>
    <td class="num">${r.names || 0}</td><td>${esc(r.username || '—')}</td></tr>`).join('')
    : '<tr><td colspan="9" class="empty">No imports yet</td></tr>';
}

async function loadImport() {
  if (can('report.export')) brLoad();
  if (!can('data.import')) return;
  bridgeCmd();
  if (can('devices.view')) {
    try { impDevices = await api('/api/devices'); } catch (_) { impDevices = []; }
  }
  $('impSn').innerHTML = impDevices.map((d) => `<option value="${esc(d.sn)}">${esc(d.name || 'Device')} (${esc(d.sn)})</option>`).join('') +
    '<option value="USB">USB import (no device)</option>';
  const lan = impDevices.find((d) => d.lan_ip);
  if (lan && !$('lanIp').value) { $('lanIp').value = lan.lan_ip; $('lanPort').value = lan.lan_port || 4370; bridgeCmd(); }
  loadImports();
}

/* Send to another branch */
let brReady = false;
async function brLoad() {
  $('brDept').innerHTML = options(meta.departments, $('brDept').value, 'All departments');
  if (brReady) return;
  brReady = true;
  const [f, t] = edPreset('lastmonth');
  $('brFrom').value = f; $('brTo').value = t;
  try { $('brName').value = (await api('/api/branch')).name || ''; } catch (_) {}
}
document.querySelectorAll('#brPresets button').forEach((b) => {
  b.onclick = () => { const [f, t] = edPreset(b.dataset.preset); $('brFrom').value = f; $('brTo').value = t; };
});
$('brExport').onclick = async () => {
  const name = $('brName').value.trim();
  if (!name) { $('brName').focus(); return toast('Enter the name of this branch'); }
  const from = $('brFrom').value, to = $('brTo').value;
  if (!from || !to || from > to) return toast('Check the dates');
  const p = new URLSearchParams({ from, to, branch: name });
  if ($('brDept').value) p.set('department_id', $('brDept').value);
  // fetch first so an error shows as a message instead of opening a blank page
  $('brExport').disabled = true;
  try {
    const res = await fetch('/api/branch/export?' + p);
    if (!res.ok) { const e = await res.json().catch(() => ({})); return toast(e.error || 'Could not make the file'); }
    const blob = await res.blob();
    const m = (res.headers.get('Content-Disposition') || '').match(/filename="([^"]+)"/);
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = m ? m[1] : 'Attendance.json';
    a.click();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
    toast('File ready. Send it to the other branch');
  } finally {
    $('brExport').disabled = false;
  }
};
