const { pool } = require('./db');

// Device times are naive local times. All math here treats them as UTC milliseconds,
// so nothing is shifted by the server's timezone.
const TZ_OFFSET_H = Number(process.env.DEVICE_TZ_OFFSET || 3);
const MIN = 60 * 1000;
const DAY = 24 * 60 * MIN;
// A shift's day starts this long before the shift start (covers early arrivals and night shifts).
const WINDOW_BEFORE = 4 * 60 * MIN;
// Two punches closer than this count as one (double tap on the device).
const MIN_GAP = 5 * MIN;

const STATUS = {
  present: 'Present',
  late: 'Late',
  absent: 'Absent',
  leave: 'On leave',
  holiday: 'Public holiday',
  weekend: 'Weekend',
  offday_work: 'Worked day off',
  not_yet: 'Not in yet',
};

const LEAVE_TYPES = ['Annual', 'Sick', 'Emergency', 'Unpaid', 'Business trip', 'Other'];

const DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

const dayMs = (date) => Date.parse(date + 'T00:00:00Z');
const tsMs = (ts) => Date.parse(String(ts).slice(0, 19).replace(' ', 'T') + 'Z');
const fmtTs = (ms) => new Date(ms).toISOString().slice(0, 19).replace('T', ' ');
const fmtDate = (ms) => new Date(ms).toISOString().slice(0, 10);
const timeMin = (t) => {
  const [h, m] = String(t).split(':').map(Number);
  return h * 60 + m;
};

function localNow() {
  return Date.now() + TZ_OFFSET_H * 3600 * 1000;
}

function today() {
  return fmtDate(localNow());
}

/**
 * Compute attendance for every active employee for each day in [from, to].
 * Returns { days, summary } where days has one row per employee per day.
 */
async function compute(from, to) {
  const [shiftsRes, empRes, holRes, leaveRes, punchRes] = await Promise.all([
    pool.query('SELECT * FROM shifts ORDER BY id'),
    pool.query(
      `SELECT e.pin, e.name, e.department_id, d.name AS department,
              -- Without a start date, days before the employee's first punch are not counted
              COALESCE(e.start_date, (SELECT MIN(punch_time)::date FROM attendance a WHERE a.pin = e.pin)) AS start_date,
              COALESCE(e.shift_id, d.shift_id) AS shift_id
       FROM employees e LEFT JOIN departments d ON d.id = e.department_id
       WHERE e.active
       ORDER BY CASE WHEN e.pin ~ '^[0-9]+$' THEN e.pin::bigint END NULLS LAST, e.pin`
    ),
    pool.query('SELECT * FROM holidays WHERE end_date >= $1 AND start_date <= $2', [from, to]),
    pool.query('SELECT * FROM leaves WHERE end_date >= $1 AND start_date <= $2', [from, to]),
    pool.query(
      `SELECT pin, punch_time FROM attendance
       WHERE punch_time >= $1::date - 1 AND punch_time < $2::date + 2
       ORDER BY punch_time`,
      [from, to]
    ),
  ]);

  const shifts = new Map(shiftsRes.rows.map((s) => [s.id, s]));
  const defaultShift = shiftsRes.rows.find((s) => s.is_default) || shiftsRes.rows[0];

  const punchesByPin = new Map();
  for (const p of punchRes.rows) {
    if (!punchesByPin.has(p.pin)) punchesByPin.set(p.pin, []);
    punchesByPin.get(p.pin).push(tsMs(p.punch_time));
  }

  const now = localNow();
  const lastDay = Math.min(dayMs(to), dayMs(today()));
  const days = [];
  const summary = [];

  for (const emp of empRes.rows) {
    const shift = shifts.get(emp.shift_id) || defaultShift;
    const punches = punchesByPin.get(emp.pin) || [];
    const sum = {
      pin: emp.pin, name: emp.name, department_id: emp.department_id, department: emp.department, shift: shift.name,
      work_days: 0, present: 0, absent: 0, leave: 0, late_count: 0, late_minutes: 0,
      early_count: 0, early_minutes: 0, missing_out: 0, offday_work: 0, worked_minutes: 0,
    };

    for (let d = dayMs(from); d <= lastDay; d += DAY) {
      const date = fmtDate(d);
      if (emp.start_date && date < emp.start_date) continue;

      const startMin = timeMin(shift.start_time);
      const lengthMin = (timeMin(shift.end_time) - startMin + 1440) % 1440 || 1440;
      const start = d + startMin * MIN;
      const end = start + lengthMin * MIN;
      const winStart = start - WINDOW_BEFORE;
      const ps = punches.filter((p) => p >= winStart && p < winStart + DAY);

      const holiday = holRes.rows.find((h) => h.start_date <= date && date <= h.end_date);
      const leave = leaveRes.rows.find((l) => l.pin === emp.pin && l.start_date <= date && date <= l.end_date);
      const dow = new Date(d).getUTCDay();
      const off = holiday ? 'holiday' : (shift.weekend || []).includes(dow) ? 'weekend' : null;

      const row = {
        date, day_name: DAY_NAMES[dow], pin: emp.pin, name: emp.name,
        department_id: emp.department_id, department: emp.department,
        shift: shift.name, shift_start: shift.start_time.slice(0, 5), shift_end: shift.end_time.slice(0, 5),
        first_in: null, last_out: null, punches: ps.length, worked_minutes: 0,
        late_minutes: 0, early_minutes: 0, missing_out: false, status: null, note: null,
      };

      if (ps.length) {
        const first = ps[0];
        const last = ps[ps.length - 1];
        const hasOut = last - first >= MIN_GAP;
        row.first_in = fmtTs(first);
        if (hasOut) {
          row.last_out = fmtTs(last);
          row.worked_minutes = Math.floor((last - first) / MIN);
        }
        if (off) {
          row.status = 'offday_work';
          row.note = holiday ? holiday.name : null;
        } else {
          const late = Math.floor((first - start) / MIN);
          if (late > shift.grace_in) row.late_minutes = late;
          if (hasOut) {
            const early = Math.floor((end - last) / MIN);
            if (early > shift.grace_out) row.early_minutes = early;
          } else if (now > end) {
            row.missing_out = true;
          }
          row.status = row.late_minutes ? 'late' : 'present';
        }
      } else if (off) {
        row.status = off;
        row.note = holiday ? holiday.name : null;
      } else if (leave) {
        row.status = 'leave';
        row.leave_type = leave.type;
        row.note = leave.type + (leave.note ? ' - ' + leave.note : '');
      } else if (now < start + shift.grace_in * MIN) {
        row.status = 'not_yet';
      } else {
        row.status = 'absent';
      }

      days.push(row);

      if (row.status === 'not_yet') continue;
      if (!off) sum.work_days++;
      if (row.status === 'present' || row.status === 'late') sum.present++;
      if (row.status === 'absent') sum.absent++;
      if (row.status === 'leave') sum.leave++;
      if (row.status === 'offday_work') sum.offday_work++;
      if (row.late_minutes) { sum.late_count++; sum.late_minutes += row.late_minutes; }
      if (row.early_minutes) { sum.early_count++; sum.early_minutes += row.early_minutes; }
      if (row.missing_out) sum.missing_out++;
      sum.worked_minutes += row.worked_minutes;
    }
    summary.push(sum);
  }

  return { days, summary };
}

// Current device-local time as "YYYY-MM-DD HH:MM:SS"
function nowTs() {
  return fmtTs(localNow());
}

module.exports = { compute, today, nowTs, STATUS, LEAVE_TYPES };
