/* Overview: any period, a calendar of days with punches, charts and an attendance map.
   Uses the same attendance rules as the rest of the dashboard (/api/report -> compute()). */

const OV_MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const SVGNS = 'http://www.w3.org/2000/svg';
// Chart groups for each attendance status
const OV_GROUP = { present: 'ok', offday_work: 'ok', late: 'late', leave: 'leave', absent: 'absent', weekend: 'off', holiday: 'off' };
const OV_LABEL = { ok: 'Present', late: 'Late', leave: 'On leave', absent: 'Absent', off: 'Day off' };

const ov = { from: '', to: '', pin: '', dept: '', view: 'employee', mode: 'day', calMonth: '', search: '', data: null, hours: [], pending: null };

/* ---------- helpers ---------- */
const ovParse = (s) => new Date(s + 'T12:00:00Z');
const ovIso = (d) => d.toISOString().slice(0, 10);
const ovDaysIn = (from, to) => { const out = []; for (let d = from; d <= to; d = addDays(d, 1)) out.push(d); return out; };
const ovDiff = (a, b) => Math.round((Date.parse(b) - Date.parse(a)) / 86400000);
const toMin = (t) => { const [h, m] = t.split(':').map(Number); return h * 60 + m; };
const fmtMin = (m) => `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(Math.round(m % 60)).padStart(2, '0')}`;
const fmtDur = (m) => (m == null || isNaN(m) ? '—' : `${Math.floor(m / 60)}:${String(Math.round(m % 60)).padStart(2, '0')}`);
const WD3 = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
function niceDate(s, o = {}) {
  return ovParse(s).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', timeZone: 'UTC',
    ...(o.year ? { year: 'numeric' } : {}), ...(o.wd ? { weekday: 'short' } : {}) });
}

const tip = document.createElement('div');
tip.className = 'tooltip';
tip.setAttribute('role', 'tooltip');
document.body.appendChild(tip);
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

/* ---------- period ---------- */
function ovPreset(p) {
  const t = meta.today, d = ovParse(t);
  const y = d.getUTCFullYear(), m = d.getUTCMonth();
  switch (p) {
    case 'today': return [t, t];
    case 'yesterday': return [addDays(t, -1), addDays(t, -1)];
    case 'week': return [addDays(t, -d.getUTCDay()), t]; // week starts Sunday
    case 'last7': return [addDays(t, -6), t];
    case 'month': return [ovIso(new Date(Date.UTC(y, m, 1))), t];
    case 'lastmonth': return [ovIso(new Date(Date.UTC(y, m - 1, 1))), ovIso(new Date(Date.UTC(y, m, 0)))];
    case 'last30': return [addDays(t, -29), t];
  }
}

function ovSetRange(from, to, { moveCal = true } = {}) {
  if (to < from) [from, to] = [to, from];
  if (ovDiff(from, to) > 365) from = addDays(to, -365);
  ov.from = from; ov.to = to; ov.pending = null;
  if (moveCal) ov.calMonth = to.slice(0, 7);
  loadOverview();
}

document.querySelectorAll('#ovPresets button').forEach((b) => {
  b.onclick = () => { const [f, t] = ovPreset(b.dataset.preset); ovSetRange(f, t); };
});
$('ovFrom').onchange = () => $('ovFrom').value && ovSetRange($('ovFrom').value, ov.to < $('ovFrom').value ? $('ovFrom').value : ov.to);
$('ovTo').onchange = () => $('ovTo').value && ovSetRange(ov.from > $('ovTo').value ? $('ovTo').value : ov.from, $('ovTo').value);

function ovShift(dir) {
  const { from, to } = ov;
  const wholeMonth = from.slice(8) === '01' && addDays(to, 1).slice(8) === '01' && from.slice(0, 7) === to.slice(0, 7);
  if (wholeMonth) {
    const d = ovParse(from);
    const s = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + dir, 1));
    return ovSetRange(ovIso(s), ovIso(new Date(Date.UTC(s.getUTCFullYear(), s.getUTCMonth() + 1, 0))));
  }
  const len = ovDiff(from, to) + 1;
  ovSetRange(addDays(from, dir * len), addDays(to, dir * len));
}
$('ovPrev').onclick = () => ovShift(-1);
$('ovNext').onclick = () => ovShift(1);
$('ovDept').onchange = () => { ov.dept = $('ovDept').value; ov.pin = ''; calCache.clear(); loadOverview(); };
$('ovPin').onchange = () => { ov.pin = $('ovPin').value; calCache.clear(); loadOverview({ refetch: false }); };
$('ovExport').onclick = () => {
  location.href = `/export.xlsx?from=${ov.from}&to=${ov.to}${ov.dept ? '&department_id=' + ov.dept : ''}`;
};

function ovQuery(from, to) {
  const p = new URLSearchParams({ from, to });
  if (ov.pin) p.set('pin', ov.pin);
  if (ov.dept) p.set('department_id', ov.dept);
  return p.toString();
}

/* ---------- calendar ---------- */
const calCache = new Map();
let ovBounds = null;

async function monthDays(month) {
  const key = `${month}|${ov.pin}|${ov.dept}`;
  if (calCache.has(key)) return calCache.get(key);
  const [y, m] = month.split('-').map(Number);
  const from = ovIso(new Date(Date.UTC(y, m - 1, 1))), to = ovIso(new Date(Date.UTC(y, m, 0)));
  const rows = await api('/api/days?' + ovQuery(addDays(from, -7), addDays(to, 7)));
  const map = new Map(rows.map((r) => [r.day, r]));
  calCache.set(key, map);
  return map;
}

async function renderCalendar() {
  const [y, m] = ov.calMonth.split('-').map(Number);
  $('ovCalTitle').textContent = `${OV_MONTHS[m - 1]} ${y}`;
  const map = await monthDays(ov.calMonth);
  const first = new Date(Date.UTC(y, m - 1, 1));
  const start = addDays(ovIso(first), -first.getUTCDay());
  const maxEmp = Math.max(1, ...[...map.values()].map((r) => r.employees));
  let html = WD3.map((w) => `<div class="wd">${w.slice(0, 2)}</div>`).join('');
  let withData = 0, punches = 0;
  for (let i = 0; i < 42; i++) {
    const day = addDays(start, i);
    const r = map.get(day);
    const inMonth = day.slice(0, 7) === ov.calMonth;
    if (r && inMonth) { withData++; punches += r.punches; }
    const lv = r ? Math.min(5, Math.max(1, Math.ceil((r.employees / maxEmp) * 5))) : 0;
    const cls = ['cal-day', r ? 'has lv' + lv : '', inMonth ? '' : 'out', day === meta.today ? 'today' : '',
      day > meta.today ? 'future' : '',
      day >= ov.from && day <= ov.to ? (ov.from === ov.to ? 'sel' : 'inrange') : '',
      ov.pending === day ? 'pending' : ''].join(' ');
    html += `<button class="${cls}" data-day="${day}" data-info="${r ? `${r.employees}|${r.punches}` : ''}">${+day.slice(8)}</button>`;
  }
  $('ovCalGrid').innerHTML = html;
  $('ovCalGrid').querySelectorAll('.cal-day').forEach((b) => {
    b.onclick = () => ovPickDay(b.dataset.day);
    b.onmousemove = (e) => {
      const [emp, pun] = (b.dataset.info || '').split('|');
      showTip(e, `<div class="t-title">${niceDate(b.dataset.day, { wd: true, year: true })}</div>` +
        (emp ? tRow('Employees', emp) + tRow('Punches', pun) : '<span class="muted">No punches</span>'));
    };
    b.onmouseleave = hideTip;
  });
  $('ovCalHint').textContent = ov.mode === 'range'
    ? (ov.pending ? `Start: ${niceDate(ov.pending)}. Now click the last day.` : 'Click the first day, then the last day.')
    : 'Shaded days have punches. Click a day to open it.';
  if (!ovBounds) ovBounds = await api('/api/bounds');
  const b = ovBounds;
  $('ovCalFoot').innerHTML =
    `<span class="muted">${withData ? `${withData} days with punches this month · ${punches} punches` : 'No punches this month'}</span>` +
    (b.last ? `<button class="link" id="ovGoLast">Latest day with punches: ${niceDate(b.last, { year: true })}</button>` : '');
  if ($('ovGoLast')) $('ovGoLast').onclick = () => ovSetRange(b.last, b.last);
}

function ovPickDay(day) {
  if (ov.mode === 'day') return ovSetRange(day, day, { moveCal: false });
  if (!ov.pending) { ov.pending = day; return renderCalendar(); }
  const s = ov.pending;
  ov.pending = null;
  ovSetRange(s, day, { moveCal: false });
}
document.querySelectorAll('#ovPickMode button').forEach((b) => {
  b.onclick = () => {
    ov.mode = b.dataset.mode;
    ov.pending = null;
    document.querySelectorAll('#ovPickMode button').forEach((x) => x.classList.toggle('active', x === b));
    renderCalendar();
  };
});
function ovShiftMonth(dir) {
  const [y, m] = ov.calMonth.split('-').map(Number);
  ov.calMonth = ovIso(new Date(Date.UTC(y, m - 1 + dir, 1))).slice(0, 7);
  renderCalendar();
}
$('ovCalPrev').onclick = () => ovShiftMonth(-1);
$('ovCalNext').onclick = () => ovShiftMonth(1);

/* ---------- loading ---------- */
let ovSeq = 0;
async function loadOverview({ refetch = true } = {}) {
  if (!ov.from) {
    ov.from = ov.to = meta.today;
    ov.calMonth = meta.today.slice(0, 7);
    // nothing today yet (or an old database): open the latest day with punches
    try {
      ovBounds = await api('/api/bounds');
      if (ovBounds.last && ovBounds.last < meta.today) { ov.from = ov.to = ovBounds.last; ov.calMonth = ovBounds.last.slice(0, 7); }
    } catch (_) {}
  }
  $('ovFrom').value = ov.from; $('ovTo').value = ov.to;
  $('ovDept').innerHTML = options(meta.departments, ov.dept, 'All departments');
  document.querySelectorAll('#ovPresets button').forEach((b) => {
    const [f, t] = ovPreset(b.dataset.preset);
    b.classList.toggle('active', f === ov.from && t === ov.to);
  });
  renderCalendar().catch(() => {});
  const seq = ++ovSeq;
  try {
    const [data, hours] = await Promise.all([
      refetch || !ov.data ? api(`/api/report?from=${ov.from}&to=${ov.to}`) : ov.data,
      api('/api/hours?' + ovQuery(ov.from, ov.to)),
    ]);
    if (seq !== ovSeq) return;
    ov.data = data;
    ov.hours = hours;
  } catch (_) { return; }
  ovRender();
}

/* ---------- shaping ---------- */
function ovRows() {
  let rows = ov.data.days;
  if (ov.dept) rows = rows.filter((r) => String(r.department_id) === ov.dept);
  if (ov.pin) rows = rows.filter((r) => r.pin === ov.pin);
  return rows.map((r) => ({
    ...r,
    g: OV_GROUP[r.status] || null,
    inMin: r.first_in ? toMin(r.first_in.slice(11, 16)) : null,
    outMin: r.last_out ? toMin(r.last_out.slice(11, 16)) : null,
  }));
}
function ovEmployees() {
  let list = ov.data.summary;
  if (ov.dept) list = list.filter((s) => String(s.department_id) === ov.dept);
  return list;
}

function ovRender() {
  const emps = ovEmployees();
  $('ovPin').innerHTML = '<option value="">All employees</option>' +
    emps.map((e) => `<option value="${esc(e.pin)}"${e.pin === ov.pin ? ' selected' : ''}>${esc(e.pin)} - ${esc(e.name || 'No name')}</option>`).join('');
  if (ov.pin && !emps.some((e) => e.pin === ov.pin)) ov.pin = '';

  const rows = ovRows();
  const single = ov.from === ov.to;
  const lastDay = ov.to < meta.today ? ov.to : meta.today;
  const days = ov.from <= lastDay ? ovDaysIn(ov.from, lastDay) : [];

  $('ovTitle').textContent = single
    ? ovParse(ov.from).toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' })
    : `${niceDate(ov.from, { year: ov.from.slice(0, 4) !== ov.to.slice(0, 4) })} – ${niceDate(ov.to, { year: true })}`;
  const emp = ov.pin && emps.find((e) => e.pin === ov.pin);
  const dept = ov.dept && meta.departments.find((d) => String(d.id) === ov.dept);
  $('ovSub').textContent = [!single && `${ovDiff(ov.from, ov.to) + 1} days`, dept && dept.name,
    emp && `${emp.name || 'No name'} (${emp.pin})`].filter(Boolean).join(' · ');

  ovStats(rows, single);
  if (single) ovTimeline(rows); else ovDaily(rows, days);
  ovArrival(rows);
  ovHours();
  $('ovMapCard').hidden = single;
  if (!single) ovMap(rows, days);
  ovTable();
}

function stat(label, value, sub = '', tone = '') {
  return `<div class="stat"><div class="label">${label}</div><div class="value num ${tone}">${value}</div>${sub ? `<div class="sub">${sub}</div>` : ''}</div>`;
}

function ovStats(rows, single) {
  const n = (g) => rows.filter((r) => r.g === g).length;
  const arrived = rows.filter((r) => r.inMin != null && (r.status === 'present' || r.status === 'late'));
  const avgIn = arrived.length ? fmtMin(arrived.reduce((s, r) => s + r.inMin, 0) / arrived.length) : '—';
  const worked = rows.filter((r) => r.worked_minutes);
  const avgWork = worked.length ? fmtDur(worked.reduce((s, r) => s + r.worked_minutes, 0) / worked.length) : '—';
  const lateMin = rows.reduce((s, r) => s + (r.late_minutes || 0), 0);
  if (single) {
    $('ovStats').innerHTML =
      stat('Present', n('ok') + n('late')) +
      stat('Late', n('late'), lateMin ? `${fmtDur(lateMin)} in total` : '', 'warn') +
      stat('Absent', n('absent'), '', 'danger') +
      stat('On leave', n('leave'), '', 'info') +
      stat('Not in yet', rows.filter((r) => r.status === 'not_yet').length, '', 'muted') +
      stat('Avg first in', avgIn);
    return;
  }
  const workDays = rows.filter((r) => ['present', 'late', 'absent', 'leave'].includes(r.status)).length;
  const present = rows.filter((r) => r.status === 'present' || r.status === 'late').length;
  $('ovStats').innerHTML =
    stat('Attendance', workDays ? Math.round((present / workDays) * 100) + '%' : '—', `${present} of ${workDays} work days`) +
    stat('Late arrivals', n('late'), lateMin ? `${fmtDur(lateMin)} in total` : '', 'warn') +
    stat('Absences', n('absent'), 'work days with no punch', 'danger') +
    stat('Leave days', n('leave'), '', 'info') +
    stat('Avg first in', avgIn) +
    stat('Avg worked', avgWork, 'first in to last out');
}

/* ---------- SVG helpers ---------- */
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
// rounded top corners (data end), square at the baseline
function barPath(x, y, w, h, r = 4) {
  if (h <= 0 || w <= 0) return '';
  r = Math.min(r, w / 2, h);
  return `M${x},${y + h}V${y + r}Q${x},${y} ${x + r},${y}H${x + w - r}Q${x + w},${y} ${x + w},${y + r}V${y + h}Z`;
}
const rectPath = (x, y, w, h) => (h <= 0 ? '' : `M${x},${y + h}V${y}H${x + w}V${y + h}Z`);
function niceStep(v) {
  const p = Math.pow(10, Math.floor(Math.log10(Math.max(1, v))));
  for (const m of [1, 2, 5, 10]) if (m * p >= v) return m * p;
  return 10 * p;
}
function niceMax(v) { if (v <= 5) return Math.max(1, Math.ceil(v)); const st = niceStep(v / 4); return Math.ceil(v / st) * st; }
function yTicks(max) { const st = max <= 5 ? 1 : niceStep(max / 4); const o = []; for (let t = 0; t <= max; t += st) o.push(t); return o; }
function axes(s, m, W, ih, max) {
  for (const t of yTicks(max)) {
    const y = m.t + ih - (t / max) * ih;
    el('line', { x1: m.l, x2: W - m.r, y1: y, y2: y, class: t === 0 ? 'axis-line' : 'grid-line' }, s);
    txt(s, m.l - 6, y + 4, String(t), { 'text-anchor': 'end' });
  }
}
const emptyChart = (id, msg) => { $(id).innerHTML = `<div class="empty-chart">${msg}</div>`; };
const legend = (items) => items.map(([cls, label]) => `<span><i class="sw ${cls}"></i>${label}</span>`).join('');

/* ---------- chart: attendance per day ---------- */
const STACK = ['ok', 'late', 'leave', 'absent'];

function ovDaily(rows, days) {
  $('ovMainTitle').textContent = 'Attendance per day';
  $('ovMainSub').textContent = 'Employees on each work day by status. Click a day to open it.';
  $('ovMainLegend').innerHTML = legend([['ok', 'Present'], ['late', 'Late'], ['leave', 'On leave'], ['absent', 'Absent'], ['offday', 'Day off']]);
  if (!days.length) return emptyChart('ovMain', 'This period is in the future.');
  const by = new Map(days.map((d) => [d, { ok: 0, late: 0, leave: 0, absent: 0, off: 0, total: 0, note: null }]));
  for (const r of rows) {
    const b = by.get(r.date);
    if (!b || !r.g) continue;
    b[r.g]++; b.total++;
    if (r.status === 'holiday' && r.note) b.note = r.note;
  }
  if (!rows.some((r) => r.g && r.g !== 'off')) return emptyChart('ovMain', 'No attendance in this period. Pick a shaded day in the calendar.');

  const box = $('ovMain');
  const W = Math.max(300, box.clientWidth), H = 250;
  const m = { l: 34, r: 8, t: 10, b: 28 };
  const iw = W - m.l - m.r, ih = H - m.t - m.b;
  const max = niceMax(Math.max(...[...by.values()].map((b) => b.ok + b.late + b.leave + b.absent)));
  const s = svg(W, H);
  axes(s, m, W, ih, max);
  const step = iw / days.length;
  const bw = Math.max(1, Math.min(36, step - (step > 6 ? 3 : 1)));
  const every = Math.ceil(days.length / Math.max(1, Math.floor(iw / 56)));
  const base = m.t + ih;
  days.forEach((d, i) => {
    const b = by.get(d);
    const x = m.l + i * step + (step - bw) / 2;
    const counted = b.ok + b.late + b.leave + b.absent;
    // a day off for everyone: light band instead of a bar
    if (!counted && b.off) el('rect', { x: m.l + i * step + 1, y: m.t, width: Math.max(1, step - 2), height: ih, class: 'f-offday', rx: 3 }, s);
    let y = base;
    const segs = STACK.filter((g) => b[g]);
    segs.forEach((g, k) => {
      const h = (b[g] / max) * ih;
      const gap = k < segs.length - 1 ? 2 : 0;
      const top = k === segs.length - 1;
      el('path', { d: top ? barPath(x, y - h, bw, h - gap) : rectPath(x, y - h + gap, bw, h - gap), class: 'f-' + g }, s);
      y -= h;
    });
    if (i % every === 0) {
      txt(s, m.l + i * step + step / 2, H - 8, days.length > 14 ? niceDate(d) : `${WD3[ovParse(d).getUTCDay()]} ${+d.slice(8)}`, { 'text-anchor': 'middle' });
    }
    const hit = el('rect', { x: m.l + i * step, y: m.t, width: step, height: ih, class: 'hit' }, s);
    hit.onmousemove = (e) => showTip(e, `<div class="t-title">${niceDate(d, { wd: true, year: true })}</div>` +
      (counted ? STACK.map((g) => tRow(OV_LABEL[g], b[g], g)).join('') : '') +
      (b.off ? tRow(b.note || 'Day off', b.off, 'offday') : '') +
      '<div class="muted small">Click to open this day</div>');
    hit.onmouseleave = hideTip;
    hit.onclick = () => { hideTip(); ovSetRange(d, d); };
  });
  box.replaceChildren(s);
}

/* ---------- chart: one day timeline ---------- */
function ovTimeline(rows) {
  $('ovMainTitle').textContent = 'Day timeline';
  $('ovMainSub').textContent = 'Each bar runs from first to last punch. White marks are punches.';
  $('ovMainLegend').innerHTML = legend([['ok', 'Present'], ['late', 'Late'], ['line', 'Shift start']]);
  const box = $('ovMain');
  const present = rows.filter((r) => r.first_in).sort((a, b) => a.inMin - b.inMin);
  const groups = (st) => rows.filter((r) => r.status === st && !r.first_in);
  const lists = [['Absent', groups('absent')], ['On leave', groups('leave')], ['Not in yet', groups('not_yet')],
    ['Day off', rows.filter((r) => r.g === 'off' && !r.first_in)]].filter(([, l]) => l.length);
  const listHtml = lists.map(([label, l]) => `<div><b>${label} (${l.length}):</b> ${l.map((r) => esc(r.name || 'ID ' + r.pin)).join(', ')}</div>`).join('');
  if (!present.length) {
    box.innerHTML = `<div class="empty-chart">No punches on this day.</div><div class="muted small list-under">${listHtml}</div>`;
    return;
  }
  const mins = present.flatMap((r) => (r.times || []).map(toMin)).concat(present.map((r) => toMin(r.shift_start)));
  const h0 = Math.max(0, Math.floor(Math.min(...mins) / 60) - 1);
  const h1 = Math.min(24, Math.ceil(Math.max(...mins) / 60) + 1);
  const W = Math.max(320, box.clientWidth);
  const labelW = Math.min(170, W * 0.3);
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
    const label = r.name || `ID ${r.pin}`;
    txt(s, 0, y + row / 2 + 4, label.length > 22 ? label.slice(0, 21) + '…' : label, { class: 'name' });
    const x1 = x(r.inMin), x2 = r.outMin != null ? x(r.outMin) : x1;
    el('rect', { x: x1 - 4, y: y + 6, width: Math.max(8, x2 - x1 + 8), height: row - 12, rx: 6, class: r.status === 'late' ? 'f-late' : 'f-ok' }, s);
    for (const tm of r.times || []) el('line', { x1: x(toMin(tm)), x2: x(toMin(tm)), y1: y + 8, y2: y + row - 8, class: 'tick' }, s);
    const sx = x(toMin(r.shift_start));
    el('line', { x1: sx, x2: sx, y1: y + 2, y2: y + row - 2, class: 'work-line' }, s);
    const hit = el('rect', { x: 0, y, width: W, height: row, class: 'hit' }, s);
    hit.onmousemove = (e) => showTip(e, `<div class="t-title">${esc(r.name || 'No name')} <span class="muted">· ID ${esc(r.pin)}</span></div>` +
      tRow('Shift', `${r.shift_start}–${r.shift_end}`) +
      tRow('First in', hm(r.first_in), r.status === 'late' ? 'late' : 'ok') +
      tRow('Last out', r.missing_out ? 'No check-out' : hm(r.last_out)) +
      (r.late_minutes ? tRow('Late', `${r.late_minutes} min`) : '') +
      (r.early_minutes ? tRow('Early leave', `${r.early_minutes} min`) : '') +
      tRow('Worked', fmtDur(r.worked_minutes || null)) +
      `<div class="muted small">Punches: ${(r.times || []).join(', ')}</div>`);
    hit.onmouseleave = hideTip;
    hit.onclick = () => { hideTip(); ov.pin = r.pin; ovRender(); };
  });
  const wrap = document.createElement('div');
  wrap.appendChild(s);
  if (listHtml) {
    const p = document.createElement('div');
    p.className = 'muted small list-under';
    p.innerHTML = listHtml;
    wrap.appendChild(p);
  }
  box.replaceChildren(wrap);
}

/* ---------- chart: arrival times ---------- */
function ovArrival(rows) {
  const arr = rows.filter((r) => r.inMin != null && (r.status === 'present' || r.status === 'late'));
  if (!arr.length) return emptyChart('ovArrival', 'No arrivals in this period');
  const box = $('ovArrival');
  const starts = [...new Set(arr.map((r) => r.shift_start))];
  const ws = starts.length === 1 ? toMin(starts[0]) : null;
  const ref = ws ?? Math.round(arr.reduce((s, r) => s + r.inMin, 0) / arr.length);
  const slot = 30;
  let lo = Math.floor(Math.min(ref - 120, ...arr.map((r) => r.inMin)) / slot) * slot;
  let hi = Math.floor(Math.max(ref + 90, ...arr.map((r) => r.inMin)) / slot) * slot;
  if ((hi - lo) / slot > 24) { lo = Math.max(lo, ref - 360); hi = Math.min(hi, ref + 330); }
  const bins = [];
  for (let t = lo; t <= hi; t += slot) bins.push({ t, ok: 0, late: 0 });
  let outside = 0;
  for (const r of arr) {
    const i = Math.floor((r.inMin - lo) / slot);
    if (i < 0 || i >= bins.length) { outside++; continue; }
    bins[i][r.status === 'late' ? 'late' : 'ok']++;
  }
  const W = Math.max(280, box.clientWidth), H = 230;
  const m = { l: 30, r: 8, t: 14, b: 28 };
  const iw = W - m.l - m.r, ih = H - m.t - m.b;
  const peak = Math.max(...bins.map((b) => b.ok + b.late));
  const max = niceMax(peak);
  const s = svg(W, H);
  axes(s, m, W, ih, max);
  const step = iw / bins.length, bw = Math.max(2, Math.min(40, step - 3));
  const every = Math.ceil(bins.length / Math.max(1, Math.floor(iw / 48)));
  bins.forEach((b, i) => {
    const x = m.l + i * step + (step - bw) / 2;
    const base = m.t + ih;
    const hOk = (b.ok / max) * ih, hLate = (b.late / max) * ih;
    if (b.ok) el('path', { d: b.late ? rectPath(x, base - hOk, bw, hOk) : barPath(x, base - hOk, bw, hOk), class: 'f-ok' }, s);
    if (b.late) el('path', { d: barPath(x, base - hOk - hLate, bw, hLate - (b.ok ? 2 : 0)), class: 'f-late' }, s);
    const n = b.ok + b.late;
    if (n && n === peak) txt(s, x + bw / 2, base - hOk - hLate - 5, String(n), { 'text-anchor': 'middle', class: 'peak-label' });
    if (i % every === 0) txt(s, m.l + i * step + step / 2, H - 8, fmtMin(b.t), { 'text-anchor': 'middle' });
    const hit = el('rect', { x: m.l + i * step, y: m.t, width: step, height: ih, class: 'hit' }, s);
    hit.onmousemove = (e) => showTip(e, `<div class="t-title">${fmtMin(b.t)} – ${fmtMin(b.t + slot)}</div>` +
      tRow('On time', b.ok, 'ok') + tRow('Late', b.late, 'late'));
    hit.onmouseleave = hideTip;
  });
  if (ws != null) {
    const wx = m.l + ((ws - lo) / slot) * step;
    el('line', { x1: wx, x2: wx, y1: m.t, y2: m.t + ih, class: 'work-line' }, s);
    txt(s, wx + 4, m.t + 2, `Shift start ${fmtMin(ws)}`, { class: 'peak-label' });
  }
  const wrap = document.createElement('div');
  wrap.appendChild(s);
  const notes = [];
  if (ws == null) notes.push('Employees here have different shift start times; late is judged by each one\'s own shift.');
  if (outside) notes.push(`${outside} arrivals outside the time shown.`);
  if (notes.length) { const p = document.createElement('div'); p.className = 'muted small'; p.textContent = notes.join(' '); wrap.appendChild(p); }
  box.replaceChildren(wrap);
}

/* ---------- chart: punches by hour ---------- */
function ovHours() {
  const rows = ov.hours;
  if (!rows.length) return emptyChart('ovHours', 'No punches in this period');
  const box = $('ovHours');
  const byH = new Map(rows.map((r) => [r.hour, r.punches]));
  const lo = Math.max(0, Math.min(6, ...rows.map((r) => r.hour)));
  const hi = Math.min(23, Math.max(18, ...rows.map((r) => r.hour)));
  const hours = []; for (let h = lo; h <= hi; h++) hours.push(h);
  const W = Math.max(280, box.clientWidth), H = 230;
  const m = { l: 34, r: 8, t: 14, b: 28 };
  const iw = W - m.l - m.r, ih = H - m.t - m.b;
  const peak = Math.max(...rows.map((r) => r.punches));
  const max = niceMax(peak);
  const total = rows.reduce((s2, r) => s2 + r.punches, 0);
  const s = svg(W, H);
  axes(s, m, W, ih, max);
  const step = iw / hours.length, bw = Math.max(2, Math.min(28, step - 3));
  const every = Math.ceil(hours.length / Math.max(1, Math.floor(iw / 40)));
  hours.forEach((h, i) => {
    const n = byH.get(h) || 0;
    const x = m.l + i * step + (step - bw) / 2;
    const bh = (n / max) * ih;
    if (n) el('path', { d: barPath(x, m.t + ih - bh, bw, bh), class: 'f-mag' }, s);
    if (n && n === peak) txt(s, x + bw / 2, m.t + ih - bh - 5, String(n), { 'text-anchor': 'middle', class: 'peak-label' });
    if (i % every === 0) txt(s, m.l + i * step + step / 2, H - 8, String(h).padStart(2, '0'), { 'text-anchor': 'middle' });
    const hit = el('rect', { x: m.l + i * step, y: m.t, width: step, height: ih, class: 'hit' }, s);
    hit.onmousemove = (e) => showTip(e, `<div class="t-title">${String(h).padStart(2, '0')}:00 – ${String(h).padStart(2, '0')}:59</div>` +
      tRow('Punches', n) + tRow('Share', total ? Math.round((n / total) * 100) + '%' : '—'));
    hit.onmouseleave = hideTip;
  });
  box.replaceChildren(s);
}

/* ---------- chart: attendance map ---------- */
function ovMap(rows, days) {
  const box = $('ovMap');
  if (!days.length) { box.innerHTML = '<div class="empty-chart">This period is in the future.</div>'; return; }
  if (days.length > 93) { box.innerHTML = '<div class="empty-chart">Pick a period of 3 months or less to see the attendance map.</div>'; return; }
  const emps = ovEmployees().filter((e) => !ov.pin || e.pin === ov.pin);
  if (!emps.length) { box.innerHTML = '<div class="empty-chart">No employees.</div>'; return; }
  const key = new Map(rows.map((r) => [r.pin + '|' + r.date, r]));
  const labelW = 150, gap = 2;
  const cell = Math.max(12, Math.min(26, Math.floor((box.clientWidth - labelW - 70) / days.length) - gap));
  const top = 30;
  const W = labelW + days.length * (cell + gap) + 64, H = top + emps.length * (cell + gap) + 4;
  const s = svg(W, H);
  // hatch for absent cells so they read apart from present cells without relying on color
  const defs = el('defs', {}, s);
  const pat = el('pattern', { id: 'hatchAbsent', width: 5, height: 5, patternUnits: 'userSpaceOnUse', patternTransform: 'rotate(45)' }, defs);
  el('rect', { width: 5, height: 5, class: 'f-absent' }, pat);
  el('line', { x1: 0, y1: 0, x2: 0, y2: 5, class: 'hatch-line' }, pat);
  const every = cell >= 18 ? 1 : cell >= 14 ? 2 : 3;
  days.forEach((d, i) => {
    const x = labelW + i * (cell + gap) + cell / 2;
    if (i % every === 0 || d.slice(8) === '01') txt(s, x, 12, +d.slice(8), { 'text-anchor': 'middle' });
    txt(s, x, 25, WD3[ovParse(d).getUTCDay()][0], { 'text-anchor': 'middle', class: 'tiny' });
  });
  emps.forEach((e, j) => {
    const y = top + j * (cell + gap);
    const nm = e.name || `ID ${e.pin}`;
    const t = txt(s, 0, y + cell / 2 + 4, nm.length > 20 ? nm.slice(0, 19) + '…' : nm, { class: 'name clickable-text' });
    t.onclick = () => { ov.pin = e.pin; ovRender(); };
    let present = 0, late = 0, absent = 0;
    days.forEach((d, i) => {
      const r = key.get(e.pin + '|' + d);
      const g = r ? r.g : null;
      if (g === 'ok' || g === 'late') present++;
      if (g === 'late') late++;
      if (g === 'absent') absent++;
      const attrs = { x: labelW + i * (cell + gap), y, width: cell, height: cell, rx: 3 };
      if (!r || !g) attrs.class = 'cell f-empty';
      else if (g === 'absent') { attrs.class = 'cell'; attrs.fill = 'url(#hatchAbsent)'; }
      else attrs.class = 'cell f-' + (g === 'off' ? 'offday' : g);
      const c = el('rect', attrs, s);
      c.onmousemove = (ev) => showTip(ev, `<div class="t-title">${esc(nm)}</div><div class="muted small">${niceDate(d, { wd: true, year: true })}</div>` +
        (r ? tRow('Status', STATUS[r.status][0], g === 'off' ? 'offday' : g) +
          (r.first_in ? tRow('First in', hm(r.first_in)) + tRow('Last out', r.missing_out ? 'No check-out' : hm(r.last_out)) : '') +
          (r.late_minutes ? tRow('Late', `${r.late_minutes} min`) : '') +
          (r.note ? `<div class="muted small">${esc(r.note)}</div>` : '')
          : '<span class="muted">Not counted (before start date)</span>'));
      c.onmouseleave = hideTip;
      c.onclick = () => { hideTip(); ovSetRange(d, d); };
    });
    txt(s, labelW + days.length * (cell + gap) + 6, y + cell / 2 + 4, `${present}d${late ? ` · ${late}L` : ''}${absent ? ` · ${absent}A` : ''}`);
  });
  box.replaceChildren(s);
}

/* ---------- table ---------- */
document.querySelectorAll('#ovView button').forEach((b) => (b.onclick = () => { ov.view = b.dataset.view; ovTable(); }));
$('ovSearch').oninput = () => { ov.search = $('ovSearch').value.trim().toLowerCase(); ovTable(); };
const ovMatch = (pin, name) => !ov.search || String(pin).toLowerCase().includes(ov.search) || String(name || '').toLowerCase().includes(ov.search);

function ovTable() {
  document.querySelectorAll('#ovView button').forEach((x) => x.classList.toggle('active', x.dataset.view === ov.view));
  const head = $('ovHead'), body = $('ovBody');
  const rows = ovRows();
  if (ov.view === 'employee') {
    head.innerHTML = '<tr><th>ID</th><th>Name</th><th>Department</th><th>Attendance</th><th>Late</th><th>Total late</th><th>Absent</th><th>Leave</th><th>Avg in</th><th>Avg out</th><th>Avg worked</th></tr>';
    const emps = ovEmployees().filter((e) => (!ov.pin || e.pin === ov.pin) && ovMatch(e.pin, e.name));
    body.innerHTML = emps.length ? emps.map((e) => {
      const mine = rows.filter((r) => r.pin === e.pin);
      const c = (g) => mine.filter((r) => r.g === g).length;
      const work = c('ok') + c('late') + c('absent') + c('leave') - mine.filter((r) => r.status === 'offday_work').length;
      const ins = mine.filter((r) => r.inMin != null && (r.status === 'present' || r.status === 'late'));
      const outs = mine.filter((r) => r.outMin != null);
      const wk = mine.filter((r) => r.worked_minutes);
      const avg = (a, f) => a.reduce((s, r) => s + f(r), 0) / a.length;
      const present = mine.filter((r) => r.status === 'present' || r.status === 'late').length;
      const w = (n) => (work ? (n / work) * 90 : 0);
      const lateMin = mine.reduce((s, r) => s + (r.late_minutes || 0), 0);
      return `<tr class="clickable" data-pin="${esc(e.pin)}">
        <td class="num">${esc(e.pin)}</td><td>${nameCell(e.name)}</td><td>${esc(e.department) || '—'}</td>
        <td><div class="bar-cell"><span class="mini-track">
          <span class="mseg ok" style="width:${w(mine.filter((r) => r.status === 'present').length)}px"></span><span class="mseg late" style="width:${w(c('late'))}px"></span><span class="mseg leave" style="width:${w(c('leave'))}px"></span><span class="mseg absent" style="width:${w(c('absent'))}px"></span>
        </span><span class="num">${present}/${work}</span></div></td>
        <td class="num ${c('late') ? 'warn' : ''}">${c('late') || '—'}</td>
        <td class="num">${lateMin ? fmtDur(lateMin) : '—'}</td>
        <td class="num ${c('absent') ? 'danger' : ''}">${c('absent') || '—'}</td>
        <td class="num">${c('leave') || '—'}</td>
        <td class="num">${ins.length ? fmtMin(avg(ins, (r) => r.inMin)) : '—'}</td>
        <td class="num">${outs.length ? fmtMin(avg(outs, (r) => r.outMin)) : '—'}</td>
        <td class="num">${wk.length ? fmtDur(avg(wk, (r) => r.worked_minutes)) : '—'}</td></tr>`;
    }).join('') : '<tr><td colspan="11" class="empty">No employees match</td></tr>';
    body.querySelectorAll('tr[data-pin]').forEach((tr) => {
      tr.title = 'Show only this employee';
      tr.onclick = () => { ov.pin = ov.pin === tr.dataset.pin ? '' : tr.dataset.pin; ovRender(); };
    });
    return;
  }
  head.innerHTML = '<tr><th>Date</th><th>ID</th><th>Name</th><th>In</th><th>Out</th><th>Worked</th><th>Late</th><th>Status</th><th>Note</th></tr>';
  const order = { late: 0, present: 1, offday_work: 2, absent: 3, not_yet: 4, leave: 5, holiday: 6, weekend: 7 };
  const list = rows.filter((r) => ovMatch(r.pin, r.name) && (ov.from === ov.to || r.g !== 'off'))
    .sort((a, b) => (a.date === b.date ? order[a.status] - order[b.status] || (a.inMin ?? 9999) - (b.inMin ?? 9999) : a.date < b.date ? 1 : -1));
  body.innerHTML = list.length ? list.map((r) => `<tr>
    <td class="num">${niceDate(r.date, { wd: true })}</td><td class="num">${esc(r.pin)}</td><td>${nameCell(r.name)}</td>
    <td class="num">${hm(r.first_in)}</td>
    <td class="num">${r.missing_out ? '<span class="pill late">No check-out</span>' : hm(r.last_out)}</td>
    <td class="num">${hhmm(r.worked_minutes)}</td>
    <td class="num ${r.late_minutes ? 'warn' : ''}">${mins(r.late_minutes)}</td>
    <td>${pill(r.status)}</td><td class="muted">${esc(r.note) || ''}</td></tr>`).join('')
    : '<tr><td colspan="9" class="empty">No records in this period</td></tr>';
}

let ovResize;
addEventListener('resize', () => {
  clearTimeout(ovResize);
  ovResize = setTimeout(() => { if (ov.data && !$('tab-overview').hidden) ovRender(); }, 150);
});
