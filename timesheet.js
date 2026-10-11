const ExcelJS = require('exceljs');
const { compute } = require('./attendance');

// Monthly timesheet in the company's layout: period runs from the 16th of the previous month
// to the 15th of the chosen month, two rows per employee (attendance codes, then overtime hours).

const LEAVE_CODE = { Annual: 'V', Sick: 'SL', Unpaid: 'U', Emergency: 'E', 'Business trip': 'LB', Other: 'E' };
const CODES = ['D', 'A', 'F', 'V', 'E', 'SL', 'LB', 'U'];
const DAY_COLS = 31;
const FIRST_DAY_COL = 9; // column I
const TOTAL_COL = FIRST_DAY_COL + DAY_COLS; // column AN

const GREEN = 'FF4EA72E';
const ROW_FILLS = ['FFE4F2DA', 'FFBFF9EB'];
const FRIDAY = 'FFFFFF00';
const FONT = { name: 'Aptos Narrow', size: 11 };

function dayCode(day) {
  switch (day.status) {
    case 'present': case 'late': case 'offday_work': return 'D';
    case 'absent': return 'A';
    case 'weekend': case 'holiday': return 'F';
    case 'leave': return LEAVE_CODE[day.leave_type] || 'E';
    default: return null; // not_yet
  }
}

// "2026-09" -> 2026-08-16 .. 2026-09-15
function period(month) {
  const [y, m] = month.split('-').map(Number);
  const start = new Date(Date.UTC(y, m - 2, 16));
  const end = new Date(Date.UTC(y, m - 1, 15));
  const iso = (d) => d.toISOString().slice(0, 10);
  const days = [];
  for (let d = new Date(start); d <= end; d.setUTCDate(d.getUTCDate() + 1)) days.push(new Date(d));
  return { from: iso(start), to: iso(end), days };
}

const MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const fmt = (d) => `${String(d.getUTCDate()).padStart(2, '0')}/${MON[d.getUTCMonth()]}/${String(d.getUTCFullYear()).slice(2)}`;

async function buildTimesheet(month, departmentId) {
  const p = period(month);
  const { days, summary } = await compute(p.from, p.to);
  const employees = summary.filter((s) => !departmentId || s.department_id === departmentId);
  const byPinDate = new Map(days.map((d) => [`${d.pin}|${d.date}`, d]));

  const wb = new ExcelJS.Workbook();
  wb.creator = 'ES Attendance';
  wb.calcProperties.fullCalcOnLoad = true;
  const ws = wb.addWorksheet('Main', {
    views: [{ state: 'frozen', xSplit: 4, ySplit: 4 }],
    pageSetup: { paperSize: 9, orientation: 'landscape', fitToPage: true, fitToWidth: 1, fitToHeight: 0, printTitlesRow: '1:4' },
  });

  const widths = { 1: 9.1, 2: 46.4, 3: 11.7, 4: 15.3, 5: 16, 6: 14.1, 7: 13.6, 8: 49.9 };
  for (let c = 1; c <= TOTAL_COL; c++) ws.getColumn(c).width = widths[c] || 5.3;
  ws.getColumn(TOTAL_COL).width = 12.6;

  const medium = { style: 'medium' };
  const thin = { style: 'thin' };
  const center = { horizontal: 'center', vertical: 'middle' };

  // Header block: period and the dates of each column
  ws.mergeCells(1, 7, 3, 7);
  Object.assign(ws.getCell(1, 7), { value: 'Period', alignment: center, font: { ...FONT, bold: true } });
  Object.assign(ws.getCell(1, 8), {
    value: `${fmt(p.days[0])} To ${fmt(p.days[p.days.length - 1])}`,
    alignment: center, font: { ...FONT, bold: true },
  });
  ws.getCell(1, 7).border = ws.getCell(1, 8).border = { top: medium, left: medium, bottom: medium, right: medium };
  ws.getRow(1).height = 32;

  p.days.forEach((d, i) => {
    const col = FIRST_DAY_COL + i;
    const friday = d.getUTCDay() === 5;
    const name = ws.getCell(1, col);
    name.value = d;
    name.numFmt = 'ddd';
    name.alignment = { horizontal: 'center', vertical: 'middle', textRotation: 90 };
    const num = ws.getCell(3, col);
    num.value = d;
    num.numFmt = 'dd';
    num.alignment = center;
    for (const c of [name, num]) {
      c.font = FONT;
      c.border = { left: medium, right: medium, top: thin, bottom: thin };
      if (friday) c.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: FRIDAY } };
    }
  });

  // Column headers
  const headers = ['No', 'Name', 'Badge No', 'Company', 'Att./Over T', 'Availabilty', 'Delay (Hours)', 'Site/ Location/ Office/ Any'];
  for (let i = 1; i <= DAY_COLS; i++) headers.push(String(i).padStart(2, '0'));
  headers.push('Total Days');
  // row.values treats an array whose first item is empty as 1-based, so set cells explicitly
  const setRow = (r, values) => values.forEach((v, i) => { if (v != null) ws.getCell(r, i + 1).value = v; });
  setRow(4, headers);
  const head = ws.getRow(4);
  head.height = 18;
  head.eachCell((c) => {
    c.font = { ...FONT, bold: true, color: { argb: 'FFFFFFFF' } };
    c.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: GREEN } };
    c.alignment = { vertical: 'middle', horizontal: c.col >= FIRST_DAY_COL ? 'center' : 'left' };
    c.border = { top: medium, bottom: medium, left: thin, right: thin };
  });

  // Two rows per employee
  const colLetter = (c) => ws.getColumn(c).letter;
  const firstDay = colLetter(FIRST_DAY_COL);
  const lastDay = colLetter(TOTAL_COL - 1);

  employees.forEach((emp, i) => {
    const att = 5 + i * 2;
    const ot = att + 1;
    const fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: ROW_FILLS[i % 2] } };

    let present = 0;
    let leave = 0;
    let lateMinutes = 0;
    const codes = p.days.map((d) => {
      const day = byPinDate.get(`${emp.pin}|${d.toISOString().slice(0, 10)}`);
      if (!day) return null;
      const code = dayCode(day);
      if (code === 'D') present++;
      if (['V', 'SL', 'U', 'E', 'LB'].includes(code)) leave++;
      lateMinutes += day.late_minutes || 0;
      return code;
    });

    setRow(att, [
      i + 1, emp.name || `#${emp.pin}`, emp.pin, null, 'Att.',
      present ? 'Y' : leave ? 'V' : 'N',
      lateMinutes ? Math.round((lateMinutes / 60) * 100) / 100 : null,
      emp.department || null,
      ...codes,
    ]);
    setRow(ot, [null, emp.name || `#${emp.pin}`, emp.pin, null, 'OT']);
    ws.mergeCells(att, 1, ot, 1);

    ws.getCell(att, TOTAL_COL).value = {
      formula: `COUNTA(${firstDay}${att}:${lastDay}${att})-COUNTIF(${firstDay}${att}:${lastDay}${att},"A")-COUNTIF(${firstDay}${att}:${lastDay}${att},"V")`,
      result: codes.filter((c) => c && c !== 'A' && c !== 'V').length,
    };
    ws.getCell(ot, TOTAL_COL).value = { formula: `SUM(${firstDay}${ot}:${lastDay}${ot})`, result: 0 };

    for (const r of [att, ot]) {
      const row = ws.getRow(r);
      row.height = 15;
      for (let c = 1; c <= TOTAL_COL; c++) {
        const cell = row.getCell(c);
        const dayIdx = c - FIRST_DAY_COL;
        const friday = dayIdx >= 0 && dayIdx < p.days.length && p.days[dayIdx].getUTCDay() === 5;
        cell.font = FONT;
        cell.fill = friday ? { type: 'pattern', pattern: 'solid', fgColor: { argb: FRIDAY } } : fill;
        cell.alignment = { vertical: 'middle', horizontal: c === 1 || c >= FIRST_DAY_COL - 3 ? 'center' : 'left' };
        cell.border = {
          left: c === 1 || c >= FIRST_DAY_COL ? medium : undefined,
          right: c === 1 || c >= FIRST_DAY_COL - 1 ? medium : undefined,
          top: r === att ? medium : thin,
          bottom: r === ot ? medium : thin,
        };
      }
    }

    // Same input rules as the original sheet
    ws.getCell(att, 6).dataValidation = { type: 'list', allowBlank: true, formulae: ['"Y,N,V"'] };
    for (let d = 0; d < p.days.length; d++) {
      ws.getCell(att, FIRST_DAY_COL + d).dataValidation = {
        type: 'list', allowBlank: true, formulae: [`"${CODES.join(',')}"`],
        showErrorMessage: true, errorTitle: 'Code', error: 'Please Fill (D,A,F,V,SL,LB,U or E)',
      };
      ws.getCell(ot, FIRST_DAY_COL + d).dataValidation = {
        type: 'decimal', operator: 'between', allowBlank: true, formulae: [0, 24],
        showErrorMessage: true, errorTitle: 'OT', error: 'Fill Only Numbers',
      };
    }
  });

  // Legend
  const legend = wb.addWorksheet('Codes');
  legend.columns = [{ header: 'Code', width: 8 }, { header: 'Meaning', width: 34 }];
  legend.addRows([
    ['D', 'Present'], ['A', 'Absent'], ['F', 'Weekend / public holiday'], ['V', 'Annual leave'],
    ['SL', 'Sick leave'], ['U', 'Unpaid leave'], ['E', 'Emergency / other leave'], ['LB', 'Business trip'],
  ]);
  legend.getRow(1).font = { ...FONT, bold: true, color: { argb: 'FFFFFFFF' } };
  legend.getRow(1).eachCell((c) => (c.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: GREEN } }));

  return { wb, period: p };
}

module.exports = { buildTimesheet, period };
