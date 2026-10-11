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
  const ws = wb.addWorksheet(name, { views: [{ state: 'frozen', ySplit: 1 }] });
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

  addSheet(wb, 'Summary', [
    ['ID', 'pin', 10], ['Name', 'name', 24], ['Department', 'department', 16], ['Shift', 'shift', 16],
    ['Work days', 'work_days'], ['Present', 'present', 10], ['Absent', 'absent', 10], ['Leave', 'leave', 10],
    ['Times late', 'late_count'], ['Total late', 'late'], ['Times left early', 'early_count', 18],
    ['Total early leave', 'early', 18], ['No check-out', 'missing_out', 16],
    ['Worked days off', 'offday_work', 16], ['Worked hours', 'worked'],
  ], summary.filter(inDept).map((s) => ({
    ...s,
    late: hhmm(s.late_minutes),
    early: hhmm(s.early_minutes),
    worked: hhmm(s.worked_minutes),
  })));

  const detail = addSheet(wb, 'Daily', [
    ['Date', 'date', 12], ['Day', 'day_name', 11], ['ID', 'pin', 10], ['Name', 'name', 24],
    ['Department', 'department', 16], ['Shift', 'shift', 16], ['In', 'in', 10], ['Out', 'out', 10],
    ['Worked', 'worked'], ['Late (min)', 'late_minutes', 15], ['Early leave (min)', 'early_minutes', 19],
    ['Status', 'status_label', 16], ['Note', 'note', 24],
  ], days.filter(inDept).map((d) => ({
    ...d,
    in: time(d.first_in),
    out: d.missing_out ? 'None' : time(d.last_out),
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
            to_char(a.punch_time, 'HH24:MI:SS') AS time
     FROM attendance a
     LEFT JOIN employees e ON e.pin = a.pin
     WHERE ${where} ORDER BY a.punch_time`,
    params
  );
  addSheet(wb, 'Punches', [
    ['ID', 'pin', 10], ['Name', 'name', 24], ['Date', 'day', 12], ['Time', 'time', 10],
  ], punches);

  return wb;
}

module.exports = { buildWorkbook };
