/* One employee: every day in a period with status, in/out, worked time and all punch times */

const ed = { pin: '', from: '', to: '', data: null };

function edPreset(p) {
  const t = meta.today, d = ovParse(t);
  const y = d.getUTCFullYear(), m = d.getUTCMonth();
  const iso = (dt) => dt.toISOString().slice(0, 10);
  switch (p) {
    case 'month': return [iso(new Date(Date.UTC(y, m, 1))), iso(new Date(Date.UTC(y, m + 1, 0)))];
    case 'lastmonth': return [iso(new Date(Date.UTC(y, m - 1, 1))), iso(new Date(Date.UTC(y, m, 0)))];
    case 'last30': return [addDays(t, -29), t];
    case 'last90': return [addDays(t, -89), t];
    case 'timesheet': {
      // Company timesheet: 16th of the previous month to the 15th
      const end = d.getUTCDate() <= 15 ? new Date(Date.UTC(y, m, 15)) : new Date(Date.UTC(y, m + 1, 15));
      const start = new Date(Date.UTC(end.getUTCFullYear(), end.getUTCMonth() - 1, 16));
      return [iso(start), iso(end)];
    }
  }
}

function openEmployee(pin) {
  ed.pin = pin;
  if (!ed.from) [ed.from, ed.to] = edPreset('month');
  $('empListView').hidden = true;
  $('empDetailView').hidden = false;
  window.scrollTo(0, 0);
  loadEmployeeDays();
}

$('empBack').onclick = () => {
  $('empDetailView').hidden = true;
  $('empListView').hidden = false;
};

function edSetRange(from, to) {
  if (to < from) [from, to] = [to, from];
  if (ovDiff(from, to) > 365) from = addDays(to, -365);
  ed.from = from; ed.to = to;
  loadEmployeeDays();
}
document.querySelectorAll('#edPresets button').forEach((b) => {
  b.onclick = () => { const [f, t] = edPreset(b.dataset.preset); edSetRange(f, t); };
});
$('edFrom').onchange = () => $('edFrom').value && edSetRange($('edFrom').value, ed.to);
$('edTo').onchange = () => $('edTo').value && edSetRange(ed.from, $('edTo').value);
function edShift(dir) {
  const wholeMonth = ed.from.slice(8) === '01' && addDays(ed.to, 1).slice(8) === '01' && ed.from.slice(0, 7) === ed.to.slice(0, 7);
  if (wholeMonth) {
    const d = ovParse(ed.from);
    const s = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + dir, 1));
    return edSetRange(s.toISOString().slice(0, 10), new Date(Date.UTC(s.getUTCFullYear(), s.getUTCMonth() + 1, 0)).toISOString().slice(0, 10));
  }
  const len = ovDiff(ed.from, ed.to) + 1;
  edSetRange(addDays(ed.from, dir * len), addDays(ed.to, dir * len));
}
$('edPrev').onclick = () => edShift(-1);
$('edNext').onclick = () => edShift(1);
$('edHideOff').onchange = () => edRender();
$('edOverview').onclick = () => {
  ov.pin = ed.pin; ov.dept = ''; ov.from = ed.from; ov.to = ed.to < meta.today ? ed.to : meta.today;
  if (ov.from > ov.to) ov.from = ov.to;
  ov.calMonth = ov.to.slice(0, 7);
  calCache.clear();
  showTab('overview');
};

async function loadEmployeeDays() {
  $('edFrom').value = ed.from; $('edTo').value = ed.to;
  document.querySelectorAll('#edPresets button').forEach((b) => {
    const [f, t] = edPreset(b.dataset.preset);
    b.classList.toggle('active', f === ed.from && t === ed.to);
  });
  const pin = ed.pin;
  let data;
  try { data = await api(`/api/employees/${encodeURIComponent(pin)}/days?from=${ed.from}&to=${ed.to}`); }
  catch (_) { return; }
  if (pin !== ed.pin) return;
  ed.data = data;
  edRender();
}

const isOff = (r) => r.status === 'weekend' || r.status === 'holiday';

function edRender() {
  const { employee: e, days } = ed.data;
  $('edName').textContent = e.name || 'No name';
  $('edInfo').textContent = [`ID ${e.pin}`, e.department || 'No department',
    e.shift ? `${e.shift} (${e.shift_start}–${e.shift_end})` : null, e.active ? null : 'Inactive'].filter(Boolean).join(' · ');

  const counted = days.filter((r) => r.status !== 'not_yet');
  const c = (st) => counted.filter((r) => r.status === st).length;
  const present = c('present') + c('late');
  const work = present + c('absent') + c('leave');
  const ins = counted.filter((r) => r.first_in && (r.status === 'present' || r.status === 'late' || r.status === null));
  const outs = counted.filter((r) => r.last_out);
  const avg = (a, f) => a.reduce((s, r) => s + f(r), 0) / a.length;
  const minOf = (ts) => toMin(ts.slice(11, 16));
  const lateMin = counted.reduce((s, r) => s + (r.late_minutes || 0), 0);
  const earlyMin = counted.reduce((s, r) => s + (r.early_minutes || 0), 0);
  const workedMin = counted.reduce((s, r) => s + (r.worked_minutes || 0), 0);
  const punches = counted.reduce((s, r) => s + (r.punches || 0), 0);

  $('edStats').innerHTML = (e.active
    ? stat('Attendance', work ? Math.round((present / work) * 100) + '%' : '—', `${present} of ${work} work days`) +
      stat('Late', c('late'), lateMin ? `${fmtDur(lateMin)} in total` : '', 'warn') +
      stat('Absent', c('absent'), '', 'danger') +
      stat('On leave', c('leave'), '', 'info')
    : stat('Days with punches', counted.length)) +
    stat('Avg first in', ins.length ? fmtMin(avg(ins, (r) => minOf(r.first_in))) : '—') +
    stat('Avg last out', outs.length ? fmtMin(avg(outs, (r) => minOf(r.last_out))) : '—') +
    stat('Total worked', fmtDur(workedMin), `${punches} punches`);

  const list = $('edHideOff').checked ? days.filter((r) => !isOff(r) || r.punches) : days;
  $('edRows').innerHTML = list.length ? list.map((r) => {
    const times = r.times || [];
    const manual = r.manual_times || [];
    const chips = times.map((t, i) => `<span class="time-chip ${i === 0 ? 'in' : i === times.length - 1 ? 'out' : ''} ${manual.includes(t) ? 'manual' : ''}"
      ${manual.includes(t) ? 'title="Added or corrected by hand"' : ''}>${t}</span>`).join('');
    return `<tr class="${isOff(r) && !r.punches ? 'off' : ''} ${r.date === meta.today ? 'today' : ''}">
      <td class="num">${niceDate(r.date, { year: true })}</td>
      <td>${esc((r.day_name || '').slice(0, 3))}</td>
      <td>${r.status ? pill(r.status) : '—'}</td>
      <td class="num">${hm(r.first_in)}</td>
      <td class="num">${r.missing_out ? '<span class="pill late">No check-out</span>' : hm(r.last_out)}</td>
      <td class="num">${hhmm(r.worked_minutes)}</td>
      <td class="num ${r.late_minutes ? 'warn' : ''}">${mins(r.late_minutes)}</td>
      <td class="num ${r.early_minutes ? 'warn' : ''}">${mins(r.early_minutes)}</td>
      <td class="wrap-cell"><div class="times">${chips || '<span class="muted">—</span>'}</div></td>
      <td class="muted">${esc(r.note) || ''}</td>
      ${can('punches.edit') ? `<td><button class="btn small" data-edit-day="${r.date}">${r.punches ? 'Edit' : 'Add punch'}</button></td>` : ''}</tr>`;
  }).join('') : '<tr><td colspan="11" class="empty">No days in this period</td></tr>';
  $('edRows').querySelectorAll('[data-edit-day]').forEach((b) => {
    b.onclick = () => openPunchEditor(e.pin, b.dataset.editDay, e.name, loadEmployeeDays);
  });
  $('edFoot').innerHTML = list.length ? `<tr>
    <td colspan="5">Total · ${counted.length} days</td>
    <td class="num">${fmtDur(workedMin)}</td>
    <td class="num">${lateMin ? fmtDur(lateMin) : '—'}</td>
    <td class="num">${earlyMin ? fmtDur(earlyMin) : '—'}</td>
    <td class="num">${punches} punches</td><td></td>${can('punches.edit') ? '<td></td>' : ''}</tr>` : '';
}

// Excel opens UTF-8 CSV correctly with the BOM
$('edExport').onclick = () => {
  if (!ed.data) return;
  const { employee: e, days } = ed.data;
  const q = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`;
  const lines = [['Employee ID', 'Name', 'Date', 'Day', 'Status', 'In', 'Out', 'Worked', 'Late (min)', 'Early leave (min)', 'Punches', 'Note'].map(q).join(',')];
  for (const r of days) {
    lines.push([e.pin, e.name, r.date, r.day_name, r.status ? STATUS[r.status][0] : '',
      r.first_in ? r.first_in.slice(11, 16) : '', r.last_out ? r.last_out.slice(11, 16) : '',
      r.worked_minutes ? hhmm(r.worked_minutes) : '', r.late_minutes || '', r.early_minutes || '',
      (r.times || []).join(' '), r.note || ''].map(q).join(','));
  }
  const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = `punches_${e.pin}_${ed.from}_${ed.to}.csv`;
  a.click();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
};
