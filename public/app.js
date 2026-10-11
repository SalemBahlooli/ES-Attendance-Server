const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const VERIFY = { 0: 'كلمة مرور', 1: 'بصمة إصبع', 2: 'بطاقة', 3: 'كلمة مرور', 4: 'بطاقة', 15: 'الوجه', 25: 'راحة اليد' };
const STATUS = {
  present: ['حاضر', 'ok'],
  late: ['متأخر', 'late'],
  absent: ['غائب', 'absent'],
  leave: ['إجازة', 'info'],
  holiday: ['عطلة رسمية', 'off'],
  weekend: ['عطلة أسبوعية', 'off'],
  offday_work: ['دوام في يوم عطلة', 'info'],
  not_yet: ['لم يبصم بعد', 'off'],
};
const DAYS = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

let meta = { today: '', leaveTypes: [], shifts: [], departments: [] };
let employees = [];
let report = null;

function toast(msg) {
  const t = $('toast');
  t.textContent = msg;
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 2200);
}

async function api(url, opts = {}) {
  const res = await fetch(url, { headers: { 'Content-Type': 'application/json' }, ...opts });
  if (res.status === 401) { location.href = '/login'; throw new Error('unauthorized'); }
  const body = await res.json().catch(() => ({}));
  if (!res.ok) { toast(body.error || 'صار خطأ، حاول مرة ثانية'); throw new Error(body.error); }
  return body;
}
const send = (url, method, data) => api(url, { method, body: data ? JSON.stringify(data) : undefined });

function addDays(date, n) {
  const d = new Date(date + 'T12:00:00Z');
  d.setUTCDate(d.getUTCDate() + n);
  return d.toISOString().slice(0, 10);
}
const daysBetween = (a, b) => Math.round((Date.parse(b) - Date.parse(a)) / 86400000) + 1;
const hm = (ts) => (ts ? ts.slice(11, 16) : '—');
const hhmm = (mins) => (mins ? `${Math.floor(mins / 60)}:${String(mins % 60).padStart(2, '0')}` : '—');
const mins = (m) => (m ? `${m} د` : '—');
const pill = (status) => `<span class="pill ${STATUS[status][1]}">${STATUS[status][0]}</span>`;
const nameCell = (name) => esc(name) || '<span class="muted">بدون اسم</span>';
const formData = (form) => Object.fromEntries(new FormData(form).entries());

function options(list, selected, emptyLabel) {
  return (emptyLabel != null ? `<option value="">${esc(emptyLabel)}</option>` : '') +
    list.map((x) => `<option value="${esc(x.id)}"${String(x.id) === String(selected ?? '') ? ' selected' : ''}>${esc(x.name)}</option>`).join('');
}

function deptFilter(rows, selectId) {
  const v = $(selectId).value;
  return v ? rows.filter((r) => String(r.department_id) === v) : rows;
}

async function loadMeta() {
  meta = await api('/api/meta');
  document.querySelectorAll('.dept-filter').forEach((sel) => {
    const v = sel.value;
    sel.innerHTML = options(meta.departments, v, 'كل الأقسام');
  });
}

async function loadEmployeeList() {
  employees = await api('/api/employees');
  const active = employees.filter((e) => e.active);
  const label = (e) => `${e.pin} - ${e.name || 'بدون اسم'}`;
  $('pinFilter').innerHTML = '<option value="">كل الموظفين</option>' +
    active.map((e) => `<option value="${esc(e.pin)}">${esc(label(e))}</option>`).join('');
  $('leavePin').innerHTML = '<option value="">اختر الموظف</option>' +
    active.map((e) => `<option value="${esc(e.pin)}">${esc(label(e))}</option>`).join('');
}

/* Tabs */
const loaders = {
  today: loadToday, report: loadReport, leaves: loadLeaves, employees: loadEmployees,
  log: loadLog, settings: loadSettings, devices: loadDevices,
};
function showTab(name) {
  document.querySelectorAll('nav.tabs button').forEach((x) => x.classList.toggle('active', x.dataset.tab === name));
  document.querySelectorAll('main section').forEach((s) => (s.hidden = s.id !== 'tab-' + name));
  loaders[name]();
}
document.querySelectorAll('nav.tabs button').forEach((b) => (b.onclick = () => showTab(b.dataset.tab)));

/* ---------------- Daily ---------------- */
async function loadToday() {
  const all = await api('/api/summary?date=' + $('day').value);
  const rows = deptFilter(all, 'todayDept');
  const count = (s) => rows.filter((r) => r.status === s).length;
  $('sPresent').textContent = count('present') + count('late') + count('offday_work');
  $('sLate').textContent = count('late');
  $('sAbsent').textContent = count('absent');
  $('sLeave').textContent = count('leave');
  $('sNotYet').textContent = count('not_yet');

  const order = { late: 0, present: 1, offday_work: 2, absent: 3, not_yet: 4, leave: 5, holiday: 6, weekend: 7 };
  rows.sort((a, b) => order[a.status] - order[b.status] || String(a.first_in).localeCompare(String(b.first_in)));

  $('todayRows').innerHTML = rows.length ? rows.map((r) => `<tr>
    <td class="num">${esc(r.pin)}</td>
    <td>${nameCell(r.name)}</td>
    <td>${esc(r.department) || '—'}</td>
    <td class="num">${r.shift_start}–${r.shift_end}</td>
    <td class="num">${hm(r.first_in)}</td>
    <td class="num">${r.missing_out ? '<span class="pill late">بدون خروج</span>' : hm(r.last_out)}</td>
    <td class="num">${hhmm(r.worked_minutes)}</td>
    <td class="num ${r.late_minutes ? 'warn' : ''}">${mins(r.late_minutes)}</td>
    <td class="num ${r.early_minutes ? 'warn' : ''}">${mins(r.early_minutes)}</td>
    <td>${pill(r.status)}${r.note ? ` <span class="muted small">${esc(r.note)}</span>` : ''}</td>
    <td>${r.status === 'absent' ? `<button class="btn small" data-leave="${esc(r.pin)}" data-date="${r.date}">تسجيل إجازة</button>` : ''}</td>
  </tr>`).join('') : '<tr><td colspan="11" class="empty">لا يوجد موظفين. أول ما يبصم أحد على الجهاز بيظهر هنا.</td></tr>';

  document.querySelectorAll('[data-leave]').forEach((b) => (b.onclick = () => {
    showTab('leaves');
    $('leavePin').value = b.dataset.leave;
    $('leaveFrom').value = b.dataset.date;
    $('leaveTo').value = b.dataset.date;
    $('leaveType').focus();
  }));
}
$('day').onchange = loadToday;
$('todayDept').onchange = loadToday;
$('todayBtn').onclick = () => { $('day').value = meta.today; loadToday(); };
$('prevDay').onclick = () => { $('day').value = addDays($('day').value, -1); loadToday(); };

/* ---------------- Report ---------------- */
function setMonth(month) {
  const [y, m] = month.split('-').map(Number);
  const last = new Date(Date.UTC(y, m, 0)).getUTCDate();
  $('repFrom').value = `${month}-01`;
  $('repTo').value = `${month}-${String(last).padStart(2, '0')}`;
}
$('repMonth').onchange = () => { if ($('repMonth').value) { setMonth($('repMonth').value); loadReport(true); } };
$('loadReport').onclick = () => loadReport(true);
$('repDept').onchange = () => renderReport();
$('repExport').onclick = () => {
  const dept = $('repDept').value;
  location.href = `/export.xlsx?from=${$('repFrom').value}&to=${$('repTo').value}${dept ? '&department_id=' + dept : ''}`;
};

async function loadReport(force) {
  if (!report || force === true) {
    report = await api(`/api/report?from=${$('repFrom').value}&to=${$('repTo').value}`);
    $('repDetail').hidden = true;
  }
  renderReport();
}

function renderReport() {
  if (!report) return;
  const rows = deptFilter(report.summary, 'repDept');
  $('repRows').innerHTML = rows.length ? rows.map((s) => `<tr class="clickable" data-pin="${esc(s.pin)}">
    <td class="num">${esc(s.pin)}</td>
    <td>${nameCell(s.name)}</td>
    <td>${esc(s.department) || '—'}</td>
    <td class="num">${s.work_days}</td>
    <td class="num">${s.present}</td>
    <td class="num ${s.absent ? 'danger' : ''}">${s.absent}</td>
    <td class="num">${s.leave}</td>
    <td class="num ${s.late_count ? 'warn' : ''}">${s.late_count}</td>
    <td class="num">${hhmm(s.late_minutes)}</td>
    <td class="num">${s.early_count ? `${s.early_count} (${hhmm(s.early_minutes)})` : '0'}</td>
    <td class="num">${s.missing_out}</td>
    <td class="num">${hhmm(s.worked_minutes)}</td>
  </tr>`).join('') : '<tr><td colspan="12" class="empty">لا توجد بيانات</td></tr>';
  document.querySelectorAll('#repRows tr[data-pin]').forEach((tr) => (tr.onclick = () => showDetail(tr.dataset.pin)));
}

function showDetail(pin) {
  const days = report.days.filter((d) => d.pin === pin);
  const emp = report.summary.find((s) => s.pin === pin);
  $('repDetailTitle').textContent = `تفاصيل: ${emp.name || emp.pin}`;
  $('repDetailRows').innerHTML = days.map((r) => `<tr>
    <td class="num">${r.date}</td>
    <td>${r.day_name}</td>
    <td class="num">${hm(r.first_in)}</td>
    <td class="num">${r.missing_out ? '<span class="pill late">بدون خروج</span>' : hm(r.last_out)}</td>
    <td class="num">${hhmm(r.worked_minutes)}</td>
    <td class="num">${mins(r.late_minutes)}</td>
    <td class="num">${mins(r.early_minutes)}</td>
    <td>${pill(r.status)}</td>
    <td class="muted">${esc(r.note) || ''}</td>
  </tr>`).join('');
  $('repDetail').hidden = false;
  $('repDetail').scrollIntoView({ behavior: 'smooth' });
}

/* ---------------- Leaves & holidays ---------------- */
async function loadLeaves() {
  const [leaves, holidays] = await Promise.all([api('/api/leaves'), api('/api/holidays')]);
  $('leaveRows').innerHTML = leaves.length ? leaves.map((l) => `<tr>
    <td>${nameCell(l.name)} <span class="muted num">(${esc(l.pin)})</span></td>
    <td>${esc(l.type)}</td>
    <td class="num">${l.start_date}</td>
    <td class="num">${l.end_date}</td>
    <td class="num">${daysBetween(l.start_date, l.end_date)}</td>
    <td>${esc(l.note) || ''}</td>
    <td><button class="btn small danger" data-del-leave="${l.id}">حذف</button></td>
  </tr>`).join('') : '<tr><td colspan="7" class="empty">لا توجد إجازات مسجلة</td></tr>';
  $('holidayRows').innerHTML = holidays.length ? holidays.map((x) => `<tr>
    <td>${esc(x.name)}</td>
    <td class="num">${x.start_date}</td>
    <td class="num">${x.end_date}</td>
    <td class="num">${daysBetween(x.start_date, x.end_date)}</td>
    <td><button class="btn small danger" data-del-holiday="${x.id}">حذف</button></td>
  </tr>`).join('') : '<tr><td colspan="5" class="empty">لا توجد عطل مسجلة</td></tr>';

  document.querySelectorAll('[data-del-leave]').forEach((b) => (b.onclick = async () => {
    if (!confirm('حذف هذي الإجازة؟')) return;
    await send('/api/leaves/' + b.dataset.delLeave, 'DELETE');
    loadLeaves();
  }));
  document.querySelectorAll('[data-del-holiday]').forEach((b) => (b.onclick = async () => {
    if (!confirm('حذف هذي العطلة؟')) return;
    await send('/api/holidays/' + b.dataset.delHoliday, 'DELETE');
    loadLeaves();
  }));
}
$('leaveFrom').onchange = () => { if (!$('leaveTo').value || $('leaveTo').value < $('leaveFrom').value) $('leaveTo').value = $('leaveFrom').value; };
$('leaveForm').onsubmit = async (e) => {
  e.preventDefault();
  await send('/api/leaves', 'POST', formData(e.target));
  e.target.reset();
  toast('تم حفظ الإجازة');
  report = null;
  loadLeaves();
};
$('holidayForm').onsubmit = async (e) => {
  e.preventDefault();
  await send('/api/holidays', 'POST', formData(e.target));
  e.target.reset();
  toast('تم حفظ العطلة');
  report = null;
  loadLeaves();
};

/* ---------------- Employees ---------------- */
async function loadEmployees() {
  await loadEmployeeList();
  renderEmployees();
}

function renderEmployees() {
  const q = $('empSearch').value.trim();
  const rows = employees.filter((e) => ($('empShowInactive').checked || e.active) &&
    (!q || e.pin.includes(q) || (e.name || '').includes(q)));
  $('empRows').innerHTML = rows.length ? rows.map((e) => `<tr class="${e.active ? '' : 'inactive'}" data-pin="${esc(e.pin)}">
    <td class="num">${esc(e.pin)}</td>
    <td><input class="name-input" data-field="name" value="${esc(e.name)}" placeholder="اكتب الاسم"></td>
    <td><select data-field="department_id">${options(meta.departments, e.department_id, 'بدون قسم')}</select></td>
    <td><select data-field="shift_id">${options(meta.shifts, e.shift_id, 'حسب القسم')}</select></td>
    <td><input type="date" data-field="start_date" value="${e.start_date || ''}"></td>
    <td><input type="checkbox" data-field="active" ${e.active ? 'checked' : ''}></td>
    <td class="num">${e.last_punch ? esc(e.last_punch.slice(0, 16)) : '—'}</td>
  </tr>`).join('') : '<tr><td colspan="7" class="empty">لا يوجد موظفين</td></tr>';

  document.querySelectorAll('#empRows [data-field]').forEach((inp) => (inp.onchange = async () => {
    const pin = inp.closest('tr').dataset.pin;
    const field = inp.dataset.field;
    const value = inp.type === 'checkbox' ? inp.checked : inp.value;
    await send('/api/employees/' + encodeURIComponent(pin), 'PUT', { [field]: value });
    const emp = employees.find((x) => x.pin === pin);
    emp[field] = value;
    if (field === 'active') { inp.closest('tr').classList.toggle('inactive', !value); loadEmployeeList(); }
    if (field === 'name') loadEmployeeList();
    report = null;
    toast('تم الحفظ');
  }));
}
$('empSearch').oninput = renderEmployees;
$('empShowInactive').onchange = renderEmployees;

/* ---------------- Punch log ---------------- */
async function loadLog() {
  const pin = $('pinFilter').value;
  const rows = await api(`/api/punches?from=${$('from').value}&to=${$('to').value}${pin ? '&pin=' + encodeURIComponent(pin) : ''}`);
  $('logRows').innerHTML = rows.length ? rows.map((r) => `<tr>
    <td class="num">${esc(r.pin)}</td>
    <td>${nameCell(r.name)}</td>
    <td class="num">${r.punch_time.slice(0, 10)}</td>
    <td class="num">${r.punch_time.slice(11, 19)}</td>
    <td>${VERIFY[r.verify] || r.verify}</td>
    <td>${esc(r.device_name || r.sn)}</td></tr>`).join('')
    : '<tr><td colspan="6" class="empty">لا توجد بصمات في هذه الفترة</td></tr>';
}
$('loadLog').onclick = loadLog;

/* ---------------- Shifts & departments ---------------- */
function shiftCard(s) {
  const t = (v) => (v || '').slice(0, 5);
  return `<form class="card pad shift" data-id="${s.id || ''}">
    <div class="shift-grid">
      <label>اسم الوردية <input name="name" value="${esc(s.name)}" required></label>
      <label>بداية الدوام <input type="time" name="start_time" value="${t(s.start_time)}" required></label>
      <label>نهاية الدوام <input type="time" name="end_time" value="${t(s.end_time)}" required></label>
      <label>مهلة التأخير (دقيقة) <input type="number" min="0" name="grace_in" value="${s.grace_in ?? 15}"></label>
      <label>مهلة الخروج المبكر (دقيقة) <input type="number" min="0" name="grace_out" value="${s.grace_out ?? 0}"></label>
    </div>
    <div class="weekdays"><span class="muted">العطلة الأسبوعية:</span>
      ${DAYS.map((d, i) => `<label class="check"><input type="checkbox" name="weekend" value="${i}" ${(s.weekend || []).includes(i) ? 'checked' : ''}> ${d}</label>`).join('')}
    </div>
    <div class="actions">
      <button class="btn primary small">حفظ</button>
      ${s.is_default ? '<span class="pill ok">الوردية الافتراضية</span>'
        : s.id ? `<button type="button" class="btn small" data-default>جعلها الافتراضية</button>
                  <button type="button" class="btn small danger" data-delete>حذف</button>` : ''}
    </div>
  </form>`;
}

function shiftData(form) {
  const fd = new FormData(form);
  return {
    name: fd.get('name'), start_time: fd.get('start_time'), end_time: fd.get('end_time'),
    grace_in: fd.get('grace_in'), grace_out: fd.get('grace_out'), weekend: fd.getAll('weekend').map(Number),
  };
}

async function loadSettings() {
  await loadMeta();
  if (!employees.length) await loadEmployeeList();
  $('shiftList').innerHTML = meta.shifts.map(shiftCard).join('');
  bindShiftForms();

  const counts = {};
  employees.forEach((e) => { if (e.active && e.department_id) counts[e.department_id] = (counts[e.department_id] || 0) + 1; });
  $('deptRows').innerHTML = meta.departments.length ? meta.departments.map((d) => `<tr data-id="${d.id}">
    <td><input class="name-input" data-dept="name" value="${esc(d.name)}"></td>
    <td><select data-dept="shift_id">${options(meta.shifts, d.shift_id, 'الوردية الافتراضية')}</select></td>
    <td class="num">${counts[d.id] || 0}</td>
    <td><button class="btn small danger" data-del-dept>حذف</button></td>
  </tr>`).join('') : '<tr><td colspan="4" class="empty">لا توجد أقسام. أضف قسم من الأسفل.</td></tr>';

  document.querySelectorAll('#deptRows tr[data-id]').forEach((tr) => {
    const save = async () => {
      await send('/api/departments/' + tr.dataset.id, 'PUT', {
        name: tr.querySelector('[data-dept=name]').value,
        shift_id: tr.querySelector('[data-dept=shift_id]').value,
      });
      report = null;
      await loadMeta();
      toast('تم حفظ القسم');
    };
    tr.querySelectorAll('[data-dept]').forEach((el) => (el.onchange = save));
    tr.querySelector('[data-del-dept]').onclick = async () => {
      if (!confirm('حذف القسم؟ الموظفين اللي فيه يصيرون بدون قسم.')) return;
      await send('/api/departments/' + tr.dataset.id, 'DELETE');
      report = null;
      loadSettings();
    };
  });
}

function bindShiftForms() {
  document.querySelectorAll('form.shift').forEach((form) => {
    const id = form.dataset.id;
    form.onsubmit = async (e) => {
      e.preventDefault();
      if (id) await send('/api/shifts/' + id, 'PUT', shiftData(form));
      else await send('/api/shifts', 'POST', shiftData(form));
      report = null;
      toast('تم حفظ الوردية');
      loadSettings();
    };
    const def = form.querySelector('[data-default]');
    if (def) def.onclick = async () => {
      await send('/api/shifts/' + id, 'PUT', { ...shiftData(form), is_default: true });
      report = null;
      loadSettings();
    };
    const del = form.querySelector('[data-delete]');
    if (del) del.onclick = async () => {
      if (!confirm('حذف الوردية؟ الموظفين عليها يرجعون لوردية القسم أو الافتراضية.')) return;
      await send('/api/shifts/' + id, 'DELETE');
      report = null;
      loadSettings();
    };
  });
}

$('addShift').onclick = () => {
  if (document.querySelector('form.shift[data-id=""]')) return;
  $('shiftList').insertAdjacentHTML('beforeend',
    shiftCard({ name: '', start_time: '08:00', end_time: '16:00', grace_in: 15, grace_out: 0, weekend: [5, 6] }));
  bindShiftForms();
  $('shiftList').lastElementChild.querySelector('input').focus();
};

$('deptForm').onsubmit = async (e) => {
  e.preventDefault();
  await send('/api/departments', 'POST', formData(e.target));
  e.target.reset();
  loadSettings();
};

/* ---------------- Devices ---------------- */
async function loadDevices() {
  const rows = await api('/api/devices');
  $('deviceList').innerHTML = rows.length ? rows.map((d) => {
    const online = Date.now() - new Date(d.last_seen).getTime() < 3 * 60 * 1000;
    return `<div class="card device">
      <div><span class="dot ${online ? 'on' : 'off'}"></span><strong>${esc(d.name || 'جهاز البصمة')}</strong>
        <span class="muted"> · ${online ? 'متصل' : 'غير متصل'}</span></div>
      <div class="row">
        <span>الرقم التسلسلي: <span class="num">${esc(d.sn)}</span></span>
        <span>آخر اتصال: <span class="num">${new Date(d.last_seen).toLocaleString('ar-SA-u-nu-latn')}</span></span>
        <span>إجمالي البصمات: <span class="num">${d.total}</span></span>
        ${d.pending ? `<span>أوامر بالانتظار: <span class="num">${d.pending}</span></span>` : ''}
      </div>
      <div class="actions">
        <button class="btn small" data-sn="${esc(d.sn)}" data-act="sync-users">سحب أسماء الموظفين من الجهاز</button>
        <button class="btn small" data-sn="${esc(d.sn)}" data-act="resend-logs">إعادة سحب كل البصمات</button>
        <button class="btn small" data-sn="${esc(d.sn)}" data-act="rename">تسمية الجهاز</button>
      </div></div>`;
  }).join('') : '<div class="card empty">لم يتصل أي جهاز بعد. اضبط إعدادات Cloud Server في الجهاز، وبيظهر هنا خلال دقيقة.</div>';
  document.querySelectorAll('[data-act]').forEach((b) => {
    b.onclick = async () => {
      const sn = b.dataset.sn;
      if (b.dataset.act === 'rename') {
        const name = prompt('اسم الجهاز (مثلاً: المدخل الرئيسي)');
        if (name === null) return;
        await send('/api/devices/' + encodeURIComponent(sn), 'PUT', { name });
        return loadDevices();
      }
      await send(`/api/devices/${encodeURIComponent(sn)}/${b.dataset.act}`, 'POST');
      toast('تم إرسال الأمر، الجهاز بينفذه خلال ثواني');
      loadDevices();
    };
  });
}

/* ---------------- Init ---------------- */
(async () => {
  await loadMeta();
  const today = meta.today;
  $('day').value = today;
  $('to').value = today;
  $('from').value = addDays(today, -7);
  $('repMonth').value = today.slice(0, 7);
  setMonth(today.slice(0, 7));
  $('leaveType').innerHTML = meta.leaveTypes.map((t) => `<option>${esc(t)}</option>`).join('');
  loadToday();
  loadEmployeeList();
  setInterval(() => { if (!$('tab-today').hidden) loadToday(); }, 30000);
})();
