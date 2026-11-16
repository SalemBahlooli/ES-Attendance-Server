/* Attendance dashboard */
const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const VERIFY = { 0: 'Password', 1: 'Fingerprint', 2: 'Card', 3: 'Password', 4: 'Card', 15: 'Face', 25: 'Palm' };
const SOURCE = { adms: 'Live', file: 'File', lan: 'LAN' };
const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const WD = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const SVGNS = 'http://www.w3.org/2000/svg';

let workStart = '08:00';
let employees = [];   // [{pin, name, ...}]
let devices = [];
const nameOf = (pin) => (employees.find((e) => e.pin === pin) || {}).name || '';

const state = { from: '', to: '', pin: '', sn: '', view: 'employee', mode: 'day', calMonth: '', search: '' };
let data = null;         // last /api/range response
let punches = null;      // last /api/punches response (All punches view)
let pendingStart = null; // first click in range mode

/* ---------------- helpers ---------------- */

function toast(msg) {
  const t = $('toast');
  t.textContent = msg;
  t.classList.add('show');
  clearTimeout(toast.t);
  toast.t = setTimeout(() => t.classList.remove('show'), 2600);
}

async function api(url, opts = {}) {
  const res = await fetch(url, { headers: { 'Content-Type': 'application/json' }, ...opts });
  if (res.status === 401) { location.href = '/login'; throw new Error('unauthorized'); }
  const body = await res.text();
  let json = null;
  try { json = JSON.parse(body); } catch (_) {}
  if (!res.ok) throw new Error((json && json.error) || body || res.statusText);
  return json;
}

const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const parse = (s) => new Date(s + 'T12:00:00');
const addDays = (s, n) => { const d = parse(s); d.setDate(d.getDate() + n); return iso(d); };
const daysBetween = (a, b) => Math.round((parse(b) - parse(a)) / 86400000);
const today = () => iso(new Date());
const hm = (ts) => (ts ? ts.slice(11, 16) : '—');
const toMin = (t) => { const [h, m] = t.split(':').map(Number); return h * 60 + m; };
const fmtMin = (m) => `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(Math.round(m % 60)).padStart(2, '0')}`;
const fmtDur = (m) => (m == null ? '—' : `${Math.floor(m / 60)}:${String(Math.round(m % 60)).padStart(2, '0')}`);
const minsBetween = (a, b) => Math.round((new Date(b.replace(' ', 'T')) - new Date(a.replace(' ', 'T'))) / 60000);
function niceDate(s, opts = {}) {
  const d = parse(s);
  return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', ...(opts.year ? { year: 'numeric' } : {}), ...(opts.wd ? { weekday: 'short' } : {}) });
}
const isLate = (firstIn) => hm(firstIn) > workStart;
const daysIn = (from, to) => { const out = []; for (let d = from; d <= to; d = addDays(d, 1)) out.push(d); return out; };

/* ---------------- tooltip ---------------- */

const tip = $('tip');
function showTip(e, html) {
  tip.innerHTML = html;
  tip.classList.add('show');
  const pad = 14, w = tip.offsetWidth, h = tip.offsetHeight;
  let x = e.clientX + pad, y = e.clientY + pad;
  if (x + w > innerWidth - 8) x = e.clientX - w - pad;
  if (y + h > innerHeight - 8) y = e.clientY - h - pad;
  tip.style.left = Math.max(8, x) + 'px';
  tip.style.top = Math.max(8, y) + 'px';
}
const hideTip = () => tip.classList.remove('show');
const tRow = (label, value, sw) => `<div class="t-row"><span>${sw ? `<i class="sw ${sw}"></i>` : ''}${label}</span><b class="num">${value}</b></div>`;

/* ---------------- tabs ---------------- */

document.querySelectorAll('nav.tabs button').forEach((b) => {
  b.onclick = () => {
    document.querySelectorAll('nav.tabs button').forEach((x) => x.classList.toggle('active', x === b));
    document.querySelectorAll('main section').forEach((s) => (s.hidden = s.id !== 'tab-' + b.dataset.tab));
    ({ attendance: loadAll, employees: loadEmployees, import: loadImport, devices: loadDevices })[b.dataset.tab]();
  };
});

/* ---------------- period / filters ---------------- */

function presetRange(p) {
  const t = today(), d = new Date();
  switch (p) {
    case 'today': return [t, t];
    case 'yesterday': return [addDays(t, -1), addDays(t, -1)];
    case 'week': return [addDays(t, -d.getDay()), t]; // week starts Sunday
    case 'last7': return [addDays(t, -6), t];
    case 'month': return [iso(new Date(d.getFullYear(), d.getMonth(), 1)), t];
    case 'lastmonth': return [iso(new Date(d.getFullYear(), d.getMonth() - 1, 1)), iso(new Date(d.getFullYear(), d.getMonth(), 0))];
    case 'last30': return [addDays(t, -29), t];
  }
}

function markPreset() {
  document.querySelectorAll('#presets button').forEach((b) => {
    const [f, t] = presetRange(b.dataset.preset);
    b.classList.toggle('active', f === state.from && t === state.to);
  });
}

function setRange(from, to, { moveCal = true } = {}) {
  if (to < from) [from, to] = [to, from];
  state.from = from; state.to = to;
  if (moveCal) state.calMonth = to.slice(0, 7);
  pendingStart = null;
  saveHash();
  loadAll();
}

document.querySelectorAll('#presets button').forEach((b) => {
  b.onclick = () => { const [f, t] = presetRange(b.dataset.preset); setRange(f, t); };
});
$('from').onchange = () => $('from').value && setRange($('from').value, state.to < $('from').value ? $('from').value : state.to);
$('to').onchange = () => $('to').value && setRange(state.from > $('to').value ? $('to').value : state.from, $('to').value);

function isWholeMonth(f, t) {
  return f.slice(8) === '01' && addDays(t, 1).slice(8) === '01' && f.slice(0, 7) === t.slice(0, 7);
}
function shiftRange(dir) {
  const { from, to } = state;
  if (isWholeMonth(from, to)) {
    const d = parse(from);
    const s = new Date(d.getFullYear(), d.getMonth() + dir, 1);
    return setRange(iso(s), iso(new Date(s.getFullYear(), s.getMonth() + 1, 0)));
  }
  const len = daysBetween(from, to) + 1;
  setRange(addDays(from, dir * len), addDays(to, dir * len));
}
$('rangePrev').onclick = () => shiftRange(-1);
$('rangeNext').onclick = () => shiftRange(1);
$('pinFilter').onchange = () => { state.pin = $('pinFilter').value; saveHash(); loadAll(); };
$('snFilter').onchange = () => { state.sn = $('snFilter').value; saveHash(); loadAll(); };
$('exportBtn').onclick = () => { location.href = '/export.csv?' + query(); };

function query(extra = {}) {
  const p = new URLSearchParams({ from: state.from, to: state.to });
  if (state.pin) p.set('pin', state.pin);
  if (state.sn) p.set('sn', state.sn);
  for (const [k, v] of Object.entries(extra)) p.set(k, v);
  return p.toString();
}

function saveHash() {
  const p = new URLSearchParams({ from: state.from, to: state.to, view: state.view });
  if (state.pin) p.set('pin', state.pin);
  if (state.sn) p.set('sn', state.sn);
  history.replaceState(null, '', '#' + p.toString());
}
function readHash() {
  const p = new URLSearchParams(location.hash.slice(1));
  const ok = (s) => /^\d{4}-\d{2}-\d{2}$/.test(s || '');
  if (ok(p.get('from')) && ok(p.get('to'))) { state.from = p.get('from'); state.to = p.get('to'); }
  state.pin = p.get('pin') || '';
  state.sn = p.get('sn') || '';
  if (['employee', 'day', 'punches'].includes(p.get('view'))) state.view = p.get('view');
}

/* ---------------- calendar ---------------- */

const calCache = new Map();
async function monthDays(month) {
  const key = `${month}|${state.pin}|${state.sn}`;
  if (calCache.has(key)) return calCache.get(key);
  const [y, m] = month.split('-').map(Number);
  const from = iso(new Date(y, m - 1, 1)), to = iso(new Date(y, m, 0));
  const p = new URLSearchParams({ from: addDays(from, -7), to: addDays(to, 7) });
  if (state.pin) p.set('pin', state.pin);
  if (state.sn) p.set('sn', state.sn);
  const rows = await api('/api/days?' + p);
  const map = new Map(rows.map((r) => [r.day, r]));
  calCache.set(key, map);
  return map;
}

async function renderCalendar() {
  const [y, m] = state.calMonth.split('-').map(Number);
  $('calTitle').textContent = `${MONTHS[m - 1]} ${y}`;
  const map = await monthDays(state.calMonth);
  const first = new Date(y, m - 1, 1);
  const start = addDays(iso(first), -first.getDay());
  const maxEmp = Math.max(1, ...[...map.values()].map((r) => r.employees));
  const t = today();
  let html = WD.map((w) => `<div class="wd">${w.slice(0, 2)}</div>`).join('');
  let monthWithData = 0, monthPunches = 0;
  for (let i = 0; i < 42; i++) {
    const day = addDays(start, i);
    const r = map.get(day);
    const inMonth = day.slice(0, 7) === state.calMonth;
    if (r && inMonth) { monthWithData++; monthPunches += r.punches; }
    const lv = r ? Math.min(5, Math.max(1, Math.ceil((r.employees / maxEmp) * 5))) : 0;
    const cls = ['cal-day', r ? 'has lv' + lv : '', inMonth ? '' : 'out', day === t ? 'today' : '',
      day >= state.from && day <= state.to ? (state.from === state.to ? 'sel' : 'inrange') : '',
      pendingStart === day ? 'pending' : ''].join(' ');
    html += `<button class="${cls}" data-day="${day}" data-info="${r ? `${r.employees}|${r.punches}` : ''}">${+day.slice(8)}</button>`;
  }
  $('calGrid').innerHTML = html;
  $('calGrid').querySelectorAll('.cal-day').forEach((b) => {
    b.onclick = () => pickDay(b.dataset.day);
    b.onmousemove = (e) => {
      const [emp, pun] = (b.dataset.info || '').split('|');
      showTip(e, `<div class="t-title">${niceDate(b.dataset.day, { wd: true, year: true })}</div>` +
        (emp ? tRow('Employees', emp) + tRow('Punches', pun) : '<span class="muted">No punches</span>'));
    };
    b.onmouseleave = hideTip;
  });
  $('calHint').textContent = state.mode === 'range'
    ? (pendingStart ? `Start: ${niceDate(pendingStart)}. Now click the last day.` : 'Click the first day, then the last day.')
    : 'Days with punches are shaded. Click a day to open it.';
  const bounds = await getBounds();
  $('calFoot').innerHTML =
    `<span class="muted">${monthWithData ? `${monthWithData} days with punches this month · ${monthPunches} punches` : 'No punches this month'}</span>` +
    (bounds.last ? `<button class="link" id="goLast">Latest day with punches: ${niceDate(bounds.last, { year: true })}</button>` : '') +
    (bounds.first ? `<button class="link" id="goAll">Show everything (${niceDate(bounds.first, { year: true })} – ${niceDate(bounds.last, { year: true })})</button>` : '');
  if ($('goLast')) $('goLast').onclick = () => setRange(bounds.last, bounds.last);
  if ($('goAll')) $('goAll').onclick = () => {
    const from = daysBetween(bounds.first, bounds.last) > 400 ? addDays(bounds.last, -399) : bounds.first;
    setRange(from, bounds.last);
  };
}

let boundsCache = null;
async function getBounds() {
  if (!boundsCache) boundsCache = await api('/api/bounds');
  return boundsCache;
}

function pickDay(day) {
  if (state.mode === 'day') return setRange(day, day, { moveCal: false });
  if (!pendingStart) { pendingStart = day; return renderCalendar(); }
  const s = pendingStart;
  pendingStart = null;
  setRange(s, day, { moveCal: false });
}
document.querySelectorAll('#pickMode button').forEach((b) => {
  b.onclick = () => {
    state.mode = b.dataset.mode;
    pendingStart = null;
    document.querySelectorAll('#pickMode button').forEach((x) => x.classList.toggle('active', x === b));
    renderCalendar();
  };
});
function shiftMonth(dir) {
  const [y, m] = state.calMonth.split('-').map(Number);
  state.calMonth = iso(new Date(y, m - 1 + dir, 1)).slice(0, 7);
  renderCalendar();
}
$('calPrev').onclick = () => shiftMonth(-1);
$('calNext').onclick = () => shiftMonth(1);

/* ---------------- data shaping ---------------- */

// one record per employee per day (present days only)
function records() {
  return data.daily.map((r) => {
    const dur = r.punches > 1 ? minsBetween(r.first_in, r.last_out) : null;
    return { ...r, name: nameOf(r.pin), late: isLate(r.first_in), inMin: toMin(hm(r.first_in)), outMin: r.punches > 1 ? toMin(hm(r.last_out)) : null, dur };
  });
}

function rosterPins(recs) {
  // employees to show: everyone (or the filtered one) + anyone who punched
  const pins = new Set(recs.map((r) => r.pin));
  if (!state.sn) for (const e of employees) if (!state.pin || e.pin === state.pin) pins.add(e.pin);
  return [...pins].sort((a, b) => (/^\d+$/.test(a) && /^\d+$/.test(b) ? a - b : a.localeCompare(b)));
}

/* ---------------- main load ---------------- */

let loadSeq = 0;
async function loadAll() {
  const seq = ++loadSeq;
  $('from').value = state.from; $('to').value = state.to;
  $('pinFilter').value = state.pin; $('snFilter').value = state.sn;
  markPreset();
  renderCalendar().catch(() => {});
  try {
    const d = await api('/api/range?' + query());
    if (seq !== loadSeq) return;
    data = d;
    punches = null;
  } catch (e) {
    toast(e.message);
    return;
  }
  render();
}

function render() {
  const recs = records();
  const single = state.from === state.to;
  const days = daysIn(state.from, state.to);
  const pins = rosterPins(recs);

  // title
  $('periodTitle').textContent = single
    ? parse(state.from).toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
    : `${niceDate(state.from, { year: state.from.slice(0, 4) !== state.to.slice(0, 4) })} – ${niceDate(state.to, { year: true })}`;
  const filt = [state.pin && `${state.pin} ${nameOf(state.pin)}`.trim(), state.sn && deviceName(state.sn)].filter(Boolean);
  $('periodSub').textContent = (single ? '' : `${days.length} days`) + (filt.length ? `${single ? '' : ' · '}${filt.join(' · ')}` : '');

  renderStats(recs, pins, days, single);
  if (single) renderTimeline(recs, pins);
  else renderDaily(recs, days, pins);
  renderArrival(recs);
  renderHours();
  $('matrixCard').hidden = single;
  if (!single) renderMatrix(recs, days, pins);
  renderTable();
}

function deviceName(sn) { const d = devices.find((x) => x.sn === sn); return (d && d.name) || sn; }

function stat(label, value, sub = '') {
  return `<div class="stat"><div class="label">${label}</div><div class="value num">${value}</div>${sub ? `<div class="sub">${sub}</div>` : ''}</div>`;
}

function renderStats(recs, pins, days, single) {
  const late = recs.filter((r) => r.late).length;
  const avgIn = recs.length ? fmtMin(recs.reduce((s, r) => s + r.inMin, 0) / recs.length) : '—';
  const durs = recs.filter((r) => r.dur != null);
  const avgDur = durs.length ? fmtDur(durs.reduce((s, r) => s + r.dur, 0) / durs.length) : '—';
  if (single) {
    const present = recs.length;
    $('stats').innerHTML =
      stat('Present', present, pins.length ? `${Math.round((present / pins.length) * 100)}% of employees` : '') +
      stat('Late', late, `after ${workStart}`) +
      stat('Absent', Math.max(0, pins.length - present)) +
      stat('Average first in', avgIn) +
      stat('Average hours', avgDur, 'first in to last out');
    return;
  }
  const activeDays = new Set(recs.map((r) => r.day)).size;
  const perDay = activeDays ? (recs.length / activeDays).toFixed(1) : '0';
  const totalMin = durs.reduce((s, r) => s + r.dur, 0);
  $('stats').innerHTML =
    stat('Days with punches', activeDays, `of ${days.length} days`) +
    stat('Present per day', perDay, `${new Set(recs.map((r) => r.pin)).size} different employees`) +
    stat('Late arrivals', late, recs.length ? `${Math.round((late / recs.length) * 100)}% of attendance` : '') +
    stat('Average first in', avgIn) +
    stat('Average hours', avgDur, `${fmtDur(totalMin)} total`);
}

/* ---------------- SVG helpers ---------------- */

function svg(w, h) {
  const s = document.createElementNS(SVGNS, 'svg');
  s.setAttribute('width', w); s.setAttribute('height', h); s.setAttribute('viewBox', `0 0 ${w} ${h}`);
  return s;
}
function el(tag, attrs = {}, parent) {
  const e = document.createElementNS(SVGNS, tag);
  for (const [k, v] of Object.entries(attrs)) e.setAttribute(k, v);
  if (parent) parent.appendChild(e);
  return e;
}
function txt(parent, x, y, s, attrs = {}) { const t = el('text', { x, y, ...attrs }, parent); t.textContent = s; return t; }
// bar with rounded top corners only (data end), square at baseline
function barPath(x, y, w, h, r = 4) {
  if (h <= 0 || w <= 0) return '';
  r = Math.min(r, w / 2, h);
  return `M${x},${y + h}V${y + r}Q${x},${y} ${x + r},${y}H${x + w - r}Q${x + w},${y} ${x + w},${y + r}V${y + h}Z`;
}
// round the axis top up to a whole-number step (1, 2, 5, 10, 20, 50...)
function niceStep(v) {
  const p = Math.pow(10, Math.floor(Math.log10(Math.max(1, v))));
  for (const m of [1, 2, 5, 10]) if (m * p >= v) return m * p;
  return 10 * p;
}
function niceMax(v) {
  if (v <= 5) return Math.max(1, Math.ceil(v));
  const step = niceStep(v / 4);
  return Math.ceil(v / step) * step;
}
function yTicks(max) {
  const step = max <= 5 ? 1 : niceStep(max / 4);
  const out = [];
  for (let t = 0; t <= max; t += step) out.push(t);
  return out;
}
function emptyChart(id, msg) { $(id).innerHTML = `<div class="empty-chart">${msg}</div>`; }

/* ---------------- chart: daily stacked bars ---------------- */

function renderDaily(recs, days, pins) {
  $('chartMainTitle').textContent = 'Attendance per day';
  $('chartMainSub').textContent = 'Employees who punched each day, split by on time and late';
  $('chartMainLegend').innerHTML = '<span><i class="sw ok"></i>On time</span><span><i class="sw late"></i>Late</span>';
  const byDay = new Map(days.map((d) => [d, { ok: 0, late: 0, punches: 0 }]));
  for (const r of recs) { const b = byDay.get(r.day); if (!b) continue; r.late ? b.late++ : b.ok++; b.punches += r.punches; }
  if (!recs.length) return emptyChart('chartMain', 'No punches in this period. Pick another period or a shaded day in the calendar.');

  const box = $('chartMain');
  const W = Math.max(300, box.clientWidth), H = 240;
  const m = { l: 34, r: 8, t: 10, b: 28 };
  const iw = W - m.l - m.r, ih = H - m.t - m.b;
  const max = niceMax(Math.max(...[...byDay.values()].map((b) => b.ok + b.late)));
  const s = svg(W, H);
  for (const t of yTicks(max)) {
    const y = m.t + ih - (t / max) * ih;
    el('line', { x1: m.l, x2: W - m.r, y1: y, y2: y, class: t === 0 ? 'axis-line' : 'grid-line' }, s);
    txt(s, m.l - 6, y + 4, String(t), { 'text-anchor': 'end' });
  }
  const step = iw / days.length;
  const bw = Math.max(1, Math.min(36, step - (step > 6 ? 3 : 1)));
  const labelEvery = Math.ceil(days.length / Math.max(1, Math.floor(iw / 56)));
  days.forEach((d, i) => {
    const b = byDay.get(d);
    const x = m.l + i * step + (step - bw) / 2;
    const hOk = (b.ok / max) * ih, hLate = (b.late / max) * ih;
    const base = m.t + ih;
    const gap = b.ok && b.late ? 2 : 0;
    if (b.ok) el('path', { d: b.late ? `M${x},${base}V${base - hOk}H${x + bw}V${base}Z` : barPath(x, base - hOk, bw, hOk), class: 'f-ok' }, s);
    if (b.late) el('path', { d: barPath(x, base - hOk - hLate, bw, Math.max(0, hLate - gap)), class: 'f-late' }, s);
    if (i % labelEvery === 0) {
      txt(s, m.l + i * step + step / 2, H - 8, days.length > 14 ? niceDate(d) : `${WD[parse(d).getDay()]} ${+d.slice(8)}`, { 'text-anchor': 'middle' });
    }
    const hit = el('rect', { x: m.l + i * step, y: m.t, width: step, height: ih, class: 'hit' }, s);
    hit.onmousemove = (e) => showTip(e, `<div class="t-title">${niceDate(d, { wd: true, year: true })}</div>` +
      tRow('On time', b.ok, 'ok') + tRow('Late', b.late, 'late') +
      (pins.length ? tRow('No punch', Math.max(0, pins.length - b.ok - b.late), 'none') : '') + tRow('Punches', b.punches) +
      '<div class="muted small">Click to open this day</div>');
    hit.onmouseleave = hideTip;
    hit.onclick = () => { hideTip(); setRange(d, d); };
  });
  box.replaceChildren(s);
}

/* ---------------- chart: single-day timeline ---------------- */

function renderTimeline(recs, pins) {
  $('chartMainTitle').textContent = 'Day timeline';
  $('chartMainSub').textContent = 'Each bar runs from first to last punch. Marks show every punch.';
  $('chartMainLegend').innerHTML = '<span><i class="sw ok"></i>On time</span><span><i class="sw late"></i>Late</span><span><i class="sw line"></i>Work start</span>';
  const box = $('chartMain');
  const present = [...recs].sort((a, b) => a.inMin - b.inMin);
  const absent = pins.filter((p) => !recs.some((r) => r.pin === p));
  if (!present.length) {
    return emptyChart('chartMain', absent.length ? `No one punched on this day. ${absent.length} employees without a punch.`
      : 'No punches on this day. Pick a shaded day in the calendar.');
  }
  const allMins = present.flatMap((r) => r.times.map(toMin)).concat([toMin(workStart)]);
  const h0 = Math.max(0, Math.floor(Math.min(...allMins) / 60) - 1);
  const h1 = Math.min(24, Math.ceil(Math.max(...allMins) / 60) + 1);
  const W = Math.max(320, box.clientWidth);
  const labelW = Math.min(170, W * 0.32);
  const row = 26;
  const m = { l: labelW, r: 12, t: 22, b: 8 };
  const H = m.t + present.length * row + m.b;
  const iw = W - m.l - m.r;
  const x = (min) => m.l + ((min - h0 * 60) / ((h1 - h0) * 60)) * iw;
  const s = svg(W, H);
  const stepH = [1, 2, 3, 4, 6].find((st) => iw / ((h1 - h0) / st) >= 46) || 6;
  for (let h = h0; h <= h1; h += stepH) {
    el('line', { x1: x(h * 60), x2: x(h * 60), y1: m.t - 4, y2: H - m.b, class: 'grid-line' }, s);
    txt(s, x(h * 60), 12, `${String(h).padStart(2, '0')}:00`, { 'text-anchor': 'middle' });
  }
  present.forEach((r, i) => {
    const y = m.t + i * row;
    const label = (r.name || `ID ${r.pin}`);
    const t = txt(s, 0, y + row / 2 + 4, label.length > 22 ? label.slice(0, 21) + '…' : label, { class: 'name' });
    t.style.fill = 'var(--text)'; t.style.fontSize = '12px';
    const x1 = x(r.inMin), x2 = r.outMin != null ? x(r.outMin) : x1;
    el('rect', { x: x1 - 4, y: y + 6, width: Math.max(8, x2 - x1 + 8), height: row - 12, rx: 6, class: r.late ? 'f-late' : 'f-ok' }, s);
    for (const tm of r.times) el('line', { x1: x(toMin(tm)), x2: x(toMin(tm)), y1: y + 8, y2: y + row - 8, class: 'tick' }, s);
    const hit = el('rect', { x: 0, y, width: W, height: row, class: 'hit' }, s);
    hit.onmousemove = (e) => showTip(e, `<div class="t-title">${esc(r.name || 'No name')} <span class="muted">· ID ${esc(r.pin)}</span></div>` +
      tRow('First in', hm(r.first_in), r.late ? 'late' : 'ok') + tRow('Last out', r.punches > 1 ? hm(r.last_out) : '—') +
      tRow('Duration', fmtDur(r.dur)) + `<div class="muted small">Punches: ${r.times.join(', ')}</div>`);
    hit.onmouseleave = hideTip;
    hit.onclick = () => { hideTip(); state.pin = r.pin; saveHash(); loadAll(); };
  });
  const wx = x(toMin(workStart));
  el('line', { x1: wx, x2: wx, y1: m.t - 6, y2: H - m.b, class: 'work-line' }, s);
  const wrap = document.createElement('div');
  wrap.appendChild(s);
  if (absent.length) {
    const p = document.createElement('div');
    p.className = 'muted small';
    p.style.marginTop = '10px';
    p.innerHTML = `<b>No punch (${absent.length}):</b> ` + absent.map((pin) => esc(nameOf(pin) || 'ID ' + pin)).join(', ');
    wrap.appendChild(p);
  }
  box.replaceChildren(wrap);
}

/* ---------------- chart: arrival histogram ---------------- */

function renderArrival(recs) {
  if (!recs.length) return emptyChart('chartArrival', 'No arrivals in this period');
  const box = $('chartArrival');
  const slot = 30;
  const ws = toMin(workStart);
  // always show at least 2 hours either side of work start
  let lo = Math.floor(Math.min(ws - 120, ...recs.map((r) => r.inMin)) / slot) * slot;
  let hi = Math.floor(Math.max(ws + 90, ...recs.map((r) => r.inMin)) / slot) * slot;
  // keep the chart readable: cap to 24 slots around the work start
  if ((hi - lo) / slot > 24) { lo = Math.max(lo, ws - 6 * 60); hi = Math.min(hi, ws + 6 * 60 - slot); }
  const bins = [];
  for (let t = lo; t <= hi; t += slot) bins.push({ t, n: 0, late: t + slot > ws && t >= ws });
  let before = 0, after = 0;
  for (const r of recs) {
    if (r.inMin < lo) { before++; continue; }
    if (r.inMin >= hi + slot) { after++; continue; }
    bins[Math.floor((r.inMin - lo) / slot)].n++;
  }
  // a slot that contains work start splits on-time/late; color by majority rule: on time if slot starts before work start
  for (const b of bins) b.late = b.t >= ws;
  const W = Math.max(280, box.clientWidth), H = 220;
  const m = { l: 30, r: 8, t: 10, b: 28 };
  const iw = W - m.l - m.r, ih = H - m.t - m.b;
  const max = niceMax(Math.max(...bins.map((b) => b.n)));
  const s = svg(W, H);
  for (const t of yTicks(max)) {
    const y = m.t + ih - (t / max) * ih;
    el('line', { x1: m.l, x2: W - m.r, y1: y, y2: y, class: t === 0 ? 'axis-line' : 'grid-line' }, s);
    txt(s, m.l - 6, y + 4, String(t), { 'text-anchor': 'end' });
  }
  const step = iw / bins.length, bw = Math.max(2, Math.min(40, step - 3));
  const every = Math.ceil(bins.length / Math.max(1, Math.floor(iw / 48)));
  bins.forEach((b, i) => {
    const x = m.l + i * step + (step - bw) / 2;
    const h = (b.n / max) * ih;
    if (b.n) el('path', { d: barPath(x, m.t + ih - h, bw, h), class: b.late ? 'f-late' : 'f-ok' }, s);
    if (i % every === 0) txt(s, m.l + i * step + step / 2, H - 8, fmtMin(b.t), { 'text-anchor': 'middle' });
    const hit = el('rect', { x: m.l + i * step, y: m.t, width: step, height: ih, class: 'hit' }, s);
    hit.onmousemove = (e) => showTip(e, `<div class="t-title">${fmtMin(b.t)} – ${fmtMin(b.t + slot)}</div>` +
      tRow(b.late ? 'Late' : 'On time', `${b.n} arrival${b.n === 1 ? '' : 's'}`, b.late ? 'late' : 'ok'));
    hit.onmouseleave = hideTip;
  });
  const wx = m.l + ((ws - lo) / slot) * step;
  el('line', { x1: wx, x2: wx, y1: m.t, y2: m.t + ih, class: 'work-line' }, s);
  txt(s, wx + 4, m.t + 10, `Work start ${workStart}`).style.fill = 'var(--text)';
  const wrap = document.createElement('div');
  wrap.appendChild(s);
  if (before || after) {
    const p = document.createElement('div');
    p.className = 'muted small';
    p.textContent = [before && `${before} earlier than ${fmtMin(lo)}`, after && `${after} later than ${fmtMin(hi + slot)}`].filter(Boolean).join(' · ');
    wrap.appendChild(p);
  }
  box.replaceChildren(wrap);
}

/* ---------------- chart: punches by hour ---------------- */

function renderHours() {
  const rows = data.hours;
  if (!rows.length) return emptyChart('chartHours', 'No punches in this period');
  const box = $('chartHours');
  const byH = new Map(rows.map((r) => [r.hour, r.punches]));
  const h0 = Math.min(...rows.map((r) => r.hour)), h1 = Math.max(...rows.map((r) => r.hour));
  const lo = Math.max(0, Math.min(h0, 6)), hi = Math.min(23, Math.max(h1, 18));
  const hours = []; for (let h = lo; h <= hi; h++) hours.push(h);
  const W = Math.max(280, box.clientWidth), H = 220;
  const m = { l: 34, r: 8, t: 10, b: 28 };
  const iw = W - m.l - m.r, ih = H - m.t - m.b;
  const max = niceMax(Math.max(...rows.map((r) => r.punches)));
  const s = svg(W, H);
  for (const t of yTicks(max)) {
    const y = m.t + ih - (t / max) * ih;
    el('line', { x1: m.l, x2: W - m.r, y1: y, y2: y, class: t === 0 ? 'axis-line' : 'grid-line' }, s);
    txt(s, m.l - 6, y + 4, String(t), { 'text-anchor': 'end' });
  }
  const step = iw / hours.length, bw = Math.max(2, Math.min(28, step - 3));
  const every = Math.ceil(hours.length / Math.max(1, Math.floor(iw / 40)));
  const total = rows.reduce((s2, r) => s2 + r.punches, 0);
  hours.forEach((h, i) => {
    const n = byH.get(h) || 0;
    const x = m.l + i * step + (step - bw) / 2;
    const bh = (n / max) * ih;
    if (n) el('path', { d: barPath(x, m.t + ih - bh, bw, bh), class: 'f-accent' }, s);
    if (i % every === 0) txt(s, m.l + i * step + step / 2, H - 8, String(h).padStart(2, '0'), { 'text-anchor': 'middle' });
    const hit = el('rect', { x: m.l + i * step, y: m.t, width: step, height: ih, class: 'hit' }, s);
    hit.onmousemove = (e) => showTip(e, `<div class="t-title">${String(h).padStart(2, '0')}:00 – ${String(h).padStart(2, '0')}:59</div>` +
      tRow('Punches', n) + tRow('Share', total ? Math.round((n / total) * 100) + '%' : '—'));
    hit.onmouseleave = hideTip;
  });
  box.replaceChildren(s);
}

/* ---------------- chart: attendance matrix ---------------- */

function renderMatrix(recs, days, pins) {
  const box = $('matrix');
  if (days.length > 93) { box.innerHTML = '<div class="empty-chart muted">Pick a period of 3 months or less to see the attendance map.</div>'; return; }
  if (!pins.length) { box.innerHTML = '<div class="empty-chart muted">No employees yet.</div>'; return; }
  const key = new Map(recs.map((r) => [r.pin + '|' + r.day, r]));
  const labelW = 150, cell = Math.max(12, Math.min(26, Math.floor((box.clientWidth - labelW - 70) / days.length) - 2)), gap = 2;
  const top = 30;
  const W = labelW + days.length * (cell + gap) + 60, H = top + pins.length * (cell + gap) + 4;
  const s = svg(W, H);
  const every = cell >= 18 ? 1 : cell >= 14 ? 2 : 3;
  days.forEach((d, i) => {
    const x = labelW + i * (cell + gap) + cell / 2;
    if (i % every === 0 || d.slice(8) === '01') {
      txt(s, x, 12, +d.slice(8), { 'text-anchor': 'middle' });
    }
    txt(s, x, 25, WD[parse(d).getDay()][0], { 'text-anchor': 'middle' }).style.fontSize = '9px';
  });
  pins.forEach((pin, j) => {
    const y = top + j * (cell + gap);
    const nm = nameOf(pin) || `ID ${pin}`;
    let n = 0, late = 0;
    const t = txt(s, 0, y + cell / 2 + 4, nm.length > 20 ? nm.slice(0, 19) + '…' : nm, { class: 'name' });
    t.style.cursor = 'pointer';
    t.onclick = () => { state.pin = pin; saveHash(); loadAll(); };
    days.forEach((d, i) => {
      const r = key.get(pin + '|' + d);
      if (r) { n++; if (r.late) late++; }
      const c = el('rect', { x: labelW + i * (cell + gap), y, width: cell, height: cell, rx: 3,
        class: 'cell ' + (r ? (r.late ? 'f-late' : 'f-ok') : 'f-none') }, s);
      c.onmousemove = (e) => showTip(e, `<div class="t-title">${esc(nm)}</div><div class="muted small">${niceDate(d, { wd: true, year: true })}</div>` +
        (r ? tRow('First in', hm(r.first_in), r.late ? 'late' : 'ok') + tRow('Last out', r.punches > 1 ? hm(r.last_out) : '—') + tRow('Duration', fmtDur(r.dur))
          : '<span class="muted">No punch</span>'));
      c.onmouseleave = hideTip;
      c.onclick = () => { hideTip(); setRange(d, d); };
    });
    txt(s, labelW + days.length * (cell + gap) + 6, y + cell / 2 + 4, `${n}d${late ? ` · ${late}L` : ''}`);
  });
  box.replaceChildren(s);
}

/* ---------------- table ---------------- */

document.querySelectorAll('#viewMode button').forEach((b) => {
  b.onclick = () => { state.view = b.dataset.view; saveHash(); renderTable(); };
});
$('tableSearch').oninput = () => { state.search = $('tableSearch').value.trim().toLowerCase(); renderTable(); };
const matches = (pin, name) => !state.search || String(pin).toLowerCase().includes(state.search) || String(name || '').toLowerCase().includes(state.search);
const noName = '<span class="muted">No name</span>';

async function renderTable() {
  document.querySelectorAll('#viewMode button').forEach((x) => x.classList.toggle('active', x.dataset.view === state.view));
  const head = $('tableHead'), body = $('tableBody');
  const recs = records();
  const single = state.from === state.to;

  if (state.view === 'employee') {
    const days = daysIn(state.from, state.to).length;
    const by = new Map(rosterPins(recs).map((p) => [p, []]));
    for (const r of recs) by.get(r.pin).push(r);
    const activeDays = Math.max(1, new Set(recs.map((r) => r.day)).size);
    head.innerHTML = `<tr><th>ID</th><th>Name</th><th>Days present</th><th>Late</th><th>Avg first in</th><th>Avg last out</th><th>Avg hours</th><th>Total hours</th></tr>`;
    const rows = [...by].filter(([pin]) => matches(pin, nameOf(pin)));
    body.innerHTML = rows.length ? rows.map(([pin, list]) => {
      const late = list.filter((r) => r.late).length;
      const outs = list.filter((r) => r.outMin != null), durs = list.filter((r) => r.dur != null);
      const avg = (a, f) => (a.length ? a.reduce((s, r) => s + f(r), 0) / a.length : null);
      const okW = (list.length - late) / activeDays * 80, lateW = late / activeDays * 80;
      return `<tr class="clickable" data-pin="${esc(pin)}">
        <td class="num">${esc(pin)}</td><td>${esc(nameOf(pin)) || noName}</td>
        <td><div class="bar-cell"><span class="mini-track"><span class="mini-bar" style="width:${okW}px"></span><span class="mini-bar late" style="width:${lateW}px"></span></span><span class="num">${list.length}${single ? '' : ` / ${activeDays}`}</span></div></td>
        <td class="num">${late || '—'}</td>
        <td class="num">${list.length ? fmtMin(avg(list, (r) => r.inMin)) : '—'}</td>
        <td class="num">${outs.length ? fmtMin(avg(outs, (r) => r.outMin)) : '—'}</td>
        <td class="num">${durs.length ? fmtDur(avg(durs, (r) => r.dur)) : '—'}</td>
        <td class="num">${durs.length ? fmtDur(durs.reduce((s, r) => s + r.dur, 0)) : '—'}</td></tr>`;
    }).join('') : `<tr><td colspan="8" class="empty">No employees match</td></tr>`;
    body.querySelectorAll('tr.clickable').forEach((tr) => {
      tr.title = 'Show only this employee';
      tr.onclick = () => { state.pin = state.pin === tr.dataset.pin ? '' : tr.dataset.pin; saveHash(); loadAll(); };
    });
    void days;
    return;
  }

  if (state.view === 'day') {
    head.innerHTML = '<tr><th>Date</th><th>ID</th><th>Name</th><th>First In</th><th>Last Out</th><th>Duration</th><th>Punches</th><th>Status</th></tr>';
    let list = [...recs].sort((a, b) => (a.day === b.day ? a.inMin - b.inMin : a.day < b.day ? 1 : -1));
    if (single) {
      // include employees with no punch
      const present = new Set(recs.map((r) => r.pin));
      for (const pin of rosterPins(recs)) if (!present.has(pin)) list.push({ day: state.from, pin, name: nameOf(pin), absent: true });
    }
    list = list.filter((r) => matches(r.pin, r.name));
    body.innerHTML = list.length ? list.map((r) => `<tr>
      <td class="num">${niceDate(r.day, { wd: true })}</td>
      <td class="num">${esc(r.pin)}</td><td>${esc(r.name) || noName}</td>
      <td class="num">${r.absent ? '—' : hm(r.first_in)}</td>
      <td class="num">${!r.absent && r.punches > 1 ? hm(r.last_out) : '—'}</td>
      <td class="num">${r.absent ? '—' : fmtDur(r.dur)}</td>
      <td class="num" title="${r.times ? r.times.join(', ') : ''}">${r.absent ? 0 : r.punches}</td>
      <td>${r.absent ? '<span class="pill absent">Absent</span>' : r.late ? '<span class="pill late">Late</span>' : '<span class="pill ok">On time</span>'}</td></tr>`).join('')
      : '<tr><td colspan="8" class="empty">No punches in this period</td></tr>';
    return;
  }

  // all punches
  head.innerHTML = '<tr><th>ID</th><th>Name</th><th>Date</th><th>Time</th><th>Verify Method</th><th>Device</th><th>Source</th></tr>';
  if (!punches) {
    body.innerHTML = '<tr><td colspan="7" class="empty">Loading…</td></tr>';
    const seq = loadSeq;
    const p = await api('/api/punches?' + query());
    if (seq !== loadSeq) return;
    punches = p;
  }
  const list = punches.filter((r) => matches(r.pin, r.name));
  body.innerHTML = list.length ? list.map((r) => `<tr>
    <td class="num">${esc(r.pin)}</td><td>${esc(r.name) || noName}</td>
    <td class="num">${niceDate(r.punch_time.slice(0, 10), { wd: true, year: true })}</td>
    <td class="num">${r.punch_time.slice(11, 19)}</td>
    <td>${VERIFY[r.verify] || (r.verify ?? '—')}</td>
    <td>${esc(r.device_name || r.sn)}</td>
    <td class="src">${SOURCE[r.source] || esc(r.source || 'Live')}</td></tr>`).join('') +
    (punches.length >= 5000 ? '<tr><td colspan="7" class="empty">Showing the latest 5000 punches. Use Export to Excel for all of them.</td></tr>' : '')
    : '<tr><td colspan="7" class="empty">No punches in this period</td></tr>';
}

/* ---------------- employees ---------------- */

async function loadEmployees() {
  employees = await api('/api/employees');
  $('pinFilter').innerHTML = '<option value="">All employees</option>' +
    employees.map((r) => `<option value="${esc(r.pin)}">${esc(r.pin)} - ${esc(r.name || 'No name')}</option>`).join('');
  $('pinFilter').value = state.pin;
  $('empRows').innerHTML = employees.length ? employees.map((r) => `<tr>
    <td class="num">${esc(r.pin)}</td>
    <td><input class="name-input" data-pin="${esc(r.pin)}" value="${esc(r.name)}" placeholder="Enter name"></td>
    <td class="num">${r.last_punch ? esc(r.last_punch.slice(0, 16)) : '—'}</td>
    <td class="num">${r.total}</td></tr>`).join('')
    : '<tr><td colspan="4" class="empty">No employees yet</td></tr>';
  document.querySelectorAll('.name-input').forEach((inp) => {
    inp.onchange = async () => {
      await api('/api/employees/' + encodeURIComponent(inp.dataset.pin), { method: 'PUT', body: JSON.stringify({ name: inp.value }) });
      const e = employees.find((x) => x.pin === inp.dataset.pin);
      if (e) e.name = inp.value;
      toast('Name saved');
    };
  });
}

/* ---------------- devices ---------------- */

async function fetchDevices() {
  devices = await api('/api/devices');
  $('snFilter').innerHTML = '<option value="">All devices</option>' +
    devices.map((d) => `<option value="${esc(d.sn)}">${esc(d.name || d.sn)}</option>`).join('');
  $('snFilter').value = state.sn;
  $('importSn').innerHTML = devices.map((d) => `<option value="${esc(d.sn)}">${esc(d.name || 'Device')} (${esc(d.sn)})</option>`).join('') +
    '<option value="USB">USB import (no device)</option>';
}

async function loadDevices() {
  await fetchDevices();
  $('deviceList').innerHTML = devices.length ? devices.map((d) => {
    const online = Date.now() - new Date(d.last_seen).getTime() < 3 * 60 * 1000;
    return `<div class="card device">
      <div><span class="dot ${online ? 'on' : 'off'}"></span><strong>${esc(d.name || 'Attendance device')}</strong>
        <span class="muted"> · ${online ? 'Online' : 'Offline'}</span></div>
      <div class="row">
        <span>Serial number: <span class="num">${esc(d.sn)}</span></span>
        <span>Last seen: <span class="num">${new Date(d.last_seen).toLocaleString('en-GB')}</span></span>
        <span>Total punches: <span class="num">${d.total}</span></span>
        ${d.lan_ip ? `<span>LAN address: <span class="num">${esc(d.lan_ip)}:${esc(d.lan_port || 4370)}</span></span>` : ''}
        ${d.pending ? `<span>Pending commands: <span class="num">${d.pending}</span></span>` : ''}
      </div>
      <div class="actions">
        <button class="btn small" data-sn="${esc(d.sn)}" data-act="sync-users">Pull employee names from device</button>
        <button class="btn small" data-sn="${esc(d.sn)}" data-act="resend-logs">Re-pull all punches</button>
        ${d.lan_ip ? `<button class="btn small" data-sn="${esc(d.sn)}" data-ip="${esc(d.lan_ip)}" data-port="${esc(d.lan_port || 4370)}" data-act="lan">Pull over LAN</button>` : ''}
        <button class="btn small" data-sn="${esc(d.sn)}" data-act="rename">Rename device</button>
      </div></div>`;
  }).join('') : '<div class="card empty">No device has connected yet. Configure the Cloud Server settings on the device, or import a USB file or pull over LAN from the Import Data tab.</div>';
  document.querySelectorAll('[data-act]').forEach((b) => {
    b.onclick = async () => {
      const sn = b.dataset.sn;
      try {
        if (b.dataset.act === 'rename') {
          const name = prompt('Device name (e.g. Main Entrance)');
          if (name === null) return;
          await api('/api/devices/' + encodeURIComponent(sn), { method: 'PUT', body: JSON.stringify({ name }) });
          return loadDevices();
        }
        if (b.dataset.act === 'lan') {
          b.disabled = true; b.textContent = 'Pulling…';
          const r = await api('/api/lan/pull', { method: 'POST', body: JSON.stringify({ ip: b.dataset.ip, port: b.dataset.port }) });
          toast(`${r.inserted} new punches saved (${r.total} on device)`);
          invalidate();
          return loadDevices();
        }
        await api(`/api/devices/${encodeURIComponent(sn)}/${b.dataset.act}`, { method: 'POST' });
        toast('Command sent. The device will run it within seconds.');
        loadDevices();
      } catch (e) { toast(e.message); loadDevices(); }
    };
  });
}

/* ---------------- import: file / USB ---------------- */

let fileText = null, fileName = '';

function decode(buf) {
  const b = new Uint8Array(buf);
  if (b[0] === 0xff && b[1] === 0xfe) return new TextDecoder('utf-16le').decode(buf);
  if (b[0] === 0xfe && b[1] === 0xff) return new TextDecoder('utf-16be').decode(buf);
  // UTF-16 without BOM: lots of zero bytes
  let zeros = 0; for (let i = 1; i < Math.min(b.length, 400); i += 2) if (b[i] === 0) zeros++;
  if (b.length > 20 && zeros > Math.min(b.length, 400) / 2 * 0.6) return new TextDecoder('utf-16le').decode(buf);
  try { return new TextDecoder('utf-8', { fatal: true }).decode(buf); }
  catch (_) { return new TextDecoder('windows-1256').decode(buf); }
}

async function chooseFile(file) {
  if (!file) return;
  if (file.size > 60 * 1024 * 1024) return showPreview(`<div class="result err">The file is larger than 60 MB.</div>`);
  fileName = file.name;
  fileText = decode(await file.arrayBuffer());
  await previewFile();
}

async function previewFile() {
  if (!fileText) return;
  showPreview('<div class="result muted">Reading the file…</div>');
  try {
    const p = new URLSearchParams({ filename: fileName, dateOrder: $('dateOrder').value, dry: '1' });
    const r = await api('/api/import/file?' + p, { method: 'POST', headers: { 'Content-Type': 'text/plain; charset=utf-8' }, body: fileText });
    const fresh = Math.max(0, r.count - r.existing);
    showPreview(`<div class="result">
      <strong>${esc(fileName)}</strong>
      <dl class="kv">
        <dt>Format</dt><dd>${esc(r.format)}</dd>
        <dt>Punches found</dt><dd>${r.count}</dd>
        <dt>Employees</dt><dd>${r.employees}${r.names ? ` (${r.names} with names)` : ''}</dd>
        <dt>Period</dt><dd>${niceDate(r.first.slice(0, 10), { year: true })} – ${niceDate(r.last.slice(0, 10), { year: true })}</dd>
        <dt>Already saved</dt><dd>${r.existing}</dd>
        <dt>New punches</dt><dd><b>${fresh}</b></dd>
        ${r.skipped ? `<dt>Lines skipped</dt><dd>${r.skipped}</dd>` : ''}
      </dl>
      <table><thead><tr><th>ID</th><th>Date and time</th></tr></thead><tbody>
        ${r.sample.map((s) => `<tr><td class="num">${esc(s.pin)}</td><td class="num">${esc(s.time)}</td></tr>`).join('')}
      </tbody></table>
      <div class="actions">
        <button class="btn primary" id="doImport" ${fresh ? '' : 'disabled'}>${fresh ? `Import ${fresh} new punches` : 'Nothing new to import'}</button>
        <button class="btn" id="cancelImport">Cancel</button>
      </div></div>`);
    $('doImport').onclick = commitFile;
    $('cancelImport').onclick = resetFile;
  } catch (e) {
    showPreview(`<div class="result err">${esc(e.message)}</div>`);
  }
}

async function commitFile() {
  $('doImport').disabled = true; $('doImport').textContent = 'Importing…';
  try {
    const p = new URLSearchParams({ filename: fileName, dateOrder: $('dateOrder').value, sn: $('importSn').value || 'USB' });
    const r = await api('/api/import/file?' + p, { method: 'POST', headers: { 'Content-Type': 'text/plain; charset=utf-8' }, body: fileText });
    fileText = null;
    $('fileInput').value = '';
    showPreview(`<div class="result ok"><strong>Import complete</strong>
      <span>${r.inserted} new punches saved, ${r.duplicates} were already saved${r.names ? `, ${r.names} names added` : ''}.</span>
      <div class="actions"><button class="btn small" id="viewImported">View these days</button></div></div>`);
    $('viewImported').onclick = () => {
      document.querySelector('nav.tabs button[data-tab="attendance"]').click();
      const from = daysBetween(r.first.slice(0, 10), r.last.slice(0, 10)) > 400 ? addDays(r.last.slice(0, 10), -399) : r.first.slice(0, 10);
      setRange(from, r.last.slice(0, 10));
    };
    invalidate();
    loadImports();
    loadEmployees();
  } catch (e) {
    showPreview(`<div class="result err">${esc(e.message)}</div>`);
  }
}

function resetFile() { fileText = null; $('fileInput').value = ''; showPreview(''); }
function showPreview(html) { $('preview').innerHTML = html; }

$('fileInput').onchange = () => chooseFile($('fileInput').files[0]);
$('dateOrder').onchange = previewFile;
const drop = $('drop');
['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('over'); }));
['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.remove('over'); }));
drop.addEventListener('drop', (e) => chooseFile(e.dataTransfer.files[0]));

/* ---------------- import: LAN ---------------- */

function lanBody() { return JSON.stringify({ ip: $('lanIp').value.trim(), port: $('lanPort').value.trim() || 4370 }); }
function lanResult(html) { $('lanResult').innerHTML = html; }
function bridgeCmd() {
  const ip = $('lanIp').value.trim() || '192.168.1.201';
  const port = $('lanPort').value.trim();
  $('bridgeCmd').textContent = `node tools/lan-bridge.js --device ${ip}${port && port !== '4370' ? ` --port ${port}` : ''} --server ${location.origin} --password YOUR_DASHBOARD_PASSWORD --every 5`;
}
$('lanIp').oninput = bridgeCmd;
$('lanPort').oninput = bridgeCmd;
$('copyBridge').onclick = async () => {
  try { await navigator.clipboard.writeText($('bridgeCmd').textContent); toast('Copied'); } catch (_) { toast('Select the text and copy it'); }
};

$('lanTest').onclick = async () => {
  if (!$('lanIp').value.trim()) return toast('Enter the device IP address');
  lanResult('<div class="result muted">Connecting…</div>');
  try {
    const r = await api('/api/lan/test', { method: 'POST', body: lanBody() });
    lanResult(`<div class="result ok"><strong>Connected</strong><dl class="kv">
      <dt>Serial number</dt><dd>${esc(r.sn || '—')}</dd><dt>Device name</dt><dd>${esc(r.name || '—')}</dd>
      <dt>Users</dt><dd>${r.users ?? '—'}</dd><dt>Punches stored</dt><dd>${r.logs ?? '—'}</dd></dl></div>`);
  } catch (e) { lanResult(`<div class="result err">${esc(e.message)}</div>`); }
};

$('lanPull').onclick = async () => {
  if (!$('lanIp').value.trim()) return toast('Enter the device IP address');
  $('lanPull').disabled = true;
  lanResult('<div class="result muted">Reading punches from the device. This can take a minute for large logs…</div>');
  try {
    const r = await api('/api/lan/pull', { method: 'POST', body: lanBody() });
    lanResult(`<div class="result ok"><strong>Done</strong>
      <span>${r.total} punches on the device, ${r.inserted} new saved, ${r.duplicates} already saved${r.names ? `, ${r.names} names added` : ''}.</span></div>`);
    invalidate();
    loadImports();
    loadEmployees();
    fetchDevices();
  } catch (e) { lanResult(`<div class="result err">${esc(e.message)}</div>`); }
  $('lanPull').disabled = false;
};

const SRC_LABEL = { file: 'File / USB', lan: 'LAN', 'lan-bridge': 'LAN bridge' };
async function loadImports() {
  const rows = await api('/api/imports');
  $('importRows').innerHTML = rows.length ? rows.map((r) => `<tr>
    <td class="num">${new Date(r.created_at).toLocaleString('en-GB')}</td>
    <td>${SRC_LABEL[r.source] || esc(r.source)}</td><td>${esc(r.label || '—')}</td><td>${esc(r.sn || '—')}</td>
    <td class="num">${r.total}</td><td class="num">${r.inserted}</td><td class="num">${r.duplicates}</td><td class="num">${r.names || 0}</td></tr>`).join('')
    : '<tr><td colspan="8" class="empty">No imports yet</td></tr>';
}

async function loadImport() {
  bridgeCmd();
  await fetchDevices();
  const lan = devices.find((d) => d.lan_ip);
  if (lan && !$('lanIp').value) { $('lanIp').value = lan.lan_ip; $('lanPort').value = lan.lan_port || 4370; bridgeCmd(); }
  loadImports();
}

function invalidate() { calCache.clear(); boundsCache = null; }

/* ---------------- init ---------------- */

addEventListener('hashchange', () => {
  const before = `${state.from}|${state.to}|${state.pin}|${state.sn}|${state.view}`;
  readHash();
  if (before !== `${state.from}|${state.to}|${state.pin}|${state.sn}|${state.view}`) { state.calMonth = state.to.slice(0, 7); loadAll(); }
});

let resizeT;
addEventListener('resize', () => {
  clearTimeout(resizeT);
  resizeT = setTimeout(() => { if (data && !$('tab-attendance').hidden) render(); }, 150);
});

(async () => {
  readHash();
  if (!state.from) { state.from = today(); state.to = today(); }
  state.calMonth = state.to.slice(0, 7);
  try { workStart = (await api('/api/config')).workStart; } catch (_) {}
  $('workStart').textContent = workStart;
  await Promise.all([loadEmployees().catch(() => {}), fetchDevices().catch(() => {})]);
  // first visit with no punches today: jump to the latest day that has data
  if (!location.hash) {
    try {
      const b = await getBounds();
      if (b.last && b.last < today()) { state.from = state.to = b.last; state.calMonth = b.last.slice(0, 7); }
    } catch (_) {}
  }
  saveHash();
  loadAll();
  setInterval(() => {
    if (!$('tab-attendance').hidden && state.to >= today() && !document.hidden) { invalidate(); loadAll(); }
  }, 60000);
})();
