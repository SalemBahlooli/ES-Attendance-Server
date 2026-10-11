const ExcelJS = require('exceljs');
const { pool } = require('./db');
const { compute, STATUS } = require('./attendance');

const hhmm = (mins) => (mins ? `${Math.floor(mins / 60)}:${String(mins % 60).padStart(2, '0')}` : '');
const time = (ts) => (ts ? ts.slice(11, 16) : '');

const STATUS_FILL = {
  present: 'FFE1F5EE',
  late: 'FFFAEEDA',
  absent: 'FFFCEBEB',
  leave: 'FFE6F1FB',
  offday_work: 'FFE6F1FB',
};

function addSheet(wb, name, columns, rows) {
  const ws = wb.addWorksheet(name, { views: [{ rightToLeft: true, state: 'frozen', ySplit: 1 }] });
  ws.columns = columns.map(([header, key, width]) => ({ header, key, width: width || 14 }));
  const head = ws.getRow(1);
  head.font = { bold: true, color: { argb: 'FFFFFFFF' } };
  head.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF0F6E56' } };
  head.alignment = { vertical: 'middle', horizontal: 'center' };
  head.height = 22;
  ws.addRows(rows);
  ws.autoFilter = { from: { row: 1, column: 1 }, to: { row: 1, column: columns.length } };
  return ws;
}

async function buildWorkbook(from, to, departmentId) {
  const { days, summary } = await compute(from, to);
  const inDept = (r) => !departmentId || r.department_id === departmentId;

  const wb = new ExcelJS.Workbook();
  wb.creator = 'ES Attendance';

  addSheet(wb, 'الملخص', [
    ['الرقم', 'pin', 10], ['الاسم', 'name', 24], ['القسم', 'department', 16], ['الوردية', 'shift', 16],
    ['أيام العمل', 'work_days'], ['حضور', 'present', 10], ['غياب', 'absent', 10], ['إجازة', 'leave', 10],
    ['مرات التأخير', 'late_count'], ['مجموع التأخير', 'late'], ['مرات الخروج المبكر', 'early_count', 18],
    ['مجموع الخروج المبكر', 'early', 18], ['بدون بصمة خروج', 'missing_out', 16],
    ['دوام في يوم عطلة', 'offday_work', 16], ['ساعات العمل', 'worked'],
  ], summary.filter(inDept).map((s) => ({
    ...s,
    late: hhmm(s.late_minutes),
    early: hhmm(s.early_minutes),
    worked: hhmm(s.worked_minutes),
  })));

  const detail = addSheet(wb, 'التفصيلي', [
    ['التاريخ', 'date', 12], ['اليوم', 'day_name', 11], ['الرقم', 'pin', 10], ['الاسم', 'name', 24],
    ['القسم', 'department', 16], ['الوردية', 'shift', 16], ['الدخول', 'in', 10], ['الخروج', 'out', 10],
    ['ساعات العمل', 'worked'], ['التأخير (دقيقة)', 'late_minutes', 15], ['الخروج المبكر (دقيقة)', 'early_minutes', 19],
    ['الحالة', 'status_label', 16], ['ملاحظة', 'note', 24],
  ], days.filter(inDept).map((d) => ({
    ...d,
    in: time(d.first_in),
    out: d.missing_out ? 'لا يوجد' : time(d.last_out),
    worked: hhmm(d.worked_minutes),
    late_minutes: d.late_minutes || '',
    early_minutes: d.early_minutes || '',
    status_label: STATUS[d.status],
  })));
  const statusCol = 12;
  days.filter(inDept).forEach((d, i) => {
    const color = STATUS_FILL[d.status];
    if (color) detail.getRow(i + 2).getCell(statusCol).fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: color } };
  });

  const params = [from, to];
  let where = 'a.punch_time >= $1::date AND a.punch_time < $2::date + 1';
  if (departmentId) { params.push(departmentId); where += ' AND e.department_id = $3'; }
  const { rows: punches } = await pool.query(
    `SELECT a.pin, e.name, to_char(a.punch_time, 'YYYY-MM-DD') AS day,
            to_char(a.punch_time, 'HH24:MI:SS') AS time, COALESCE(d.name, a.sn) AS device
     FROM attendance a
     LEFT JOIN employees e ON e.pin = a.pin
     LEFT JOIN devices d ON d.sn = a.sn
     WHERE ${where} ORDER BY a.punch_time`,
    params
  );
  addSheet(wb, 'البصمات', [
    ['الرقم', 'pin', 10], ['الاسم', 'name', 24], ['التاريخ', 'day', 12], ['الوقت', 'time', 10], ['الجهاز', 'device', 18],
  ], punches);

  return wb;
}

module.exports = { buildWorkbook };
