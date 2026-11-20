/*
 | The attendance pages' Alpine components:
 |   punchEditor        the "Edit punches" dialog (daily, employee, punch log)
 |   punchFileImport    USB / CSV / branch file: preview, then import
 |   lanPull            test and pull a device over the LAN
 |   attendanceOverview the Overview page: calendar, charts, attendance map, tables
 */
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content;

async function send(url, { method = 'GET', body = null, form = null } = {}) {
    const headers = { Accept: 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' };
    let payload = form;
    if (body) {
        headers['Content-Type'] = 'application/json';
        payload = JSON.stringify(body);
    }
    const res = await fetch(url, { method, headers, body: payload, credentials: 'same-origin' });
    if (res.status === 401 || res.status === 419) {
        window.location.reload();
        throw new Error('signed out');
    }
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
        const first = data.errors ? Object.values(data.errors)[0]?.[0] : null;
        throw new Error(first || data.message || `Error ${res.status}`);
    }
    return data;
}

const pad = (n) => String(n).padStart(2, '0');
const iso = (d) => `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}`;
const parse = (s) => new Date(s + 'T12:00:00Z');
const addDays = (s, n) => { const d = parse(s); d.setUTCDate(d.getUTCDate() + n); return iso(d); };
const diffDays = (a, b) => Math.round((parse(b) - parse(a)) / 86400000);
const dur = (m) => (m ? `${Math.floor(m / 60)}:${pad(Math.round(m % 60))}` : '');
const toMin = (t) => { const [h, m] = t.split(':').map(Number); return h * 60 + m; };
const clock = (m) => `${pad(Math.floor(m / 60) % 24)}:${pad(Math.round(m % 60))}`;

// One palette for the charts and the map (status → colour), read from theme.css so dark mode follows
const css = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
const COLORS = {};
function readColors() {
    Object.assign(COLORS, {
        ok: css('--color-att-ok'), late: css('--color-att-late'), leave: css('--color-att-leave'),
        absent: css('--color-att-absent'), off: css('--color-att-off'), hours: css('--color-heat-4'),
    });
    if (window.Chart) {
        window.Chart.defaults.color = css('--color-slate-500');
        window.Chart.defaults.borderColor = css('--line-soft');
        window.Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    }
}
const GROUP = { present: 'ok', offday_work: 'ok', late: 'late', leave: 'leave', absent: 'absent', weekend: 'off', holiday: 'off' };
const MAP_CLASS = { ok: 'bg-att-ok', late: 'bg-att-late', leave: 'bg-att-leave', absent: 'bg-att-absent', off: 'bg-att-off' };
const BADGE = {
    present: 'bg-green-50 text-green-700 ring-green-600/20', late: 'bg-amber-50 text-amber-800 ring-amber-600/20',
    absent: 'bg-red-50 text-red-700 ring-red-600/20', leave: 'bg-blue-50 text-blue-700 ring-blue-600/20',
    offday_work: 'bg-teal-50 text-teal-700 ring-teal-600/20',
};

export function registerAttendance(Alpine) {
    /* ------------------------------------------------------ punch editor */
    Alpine.data('punchEditor', (urls) => ({
        show: false, loading: false, changed: false, error: '',
        pin: '', date: '', employee: '', punches: [], newTime: '', note: '',

        async open({ pin, date }) {
            Object.assign(this, { show: true, pin, date, error: '', newTime: '', note: '', punches: [], changed: false });
            await this.refresh();
        },
        async refresh() {
            this.loading = true;
            try {
                const data = await send(`${urls.dayUrl}?pin=${encodeURIComponent(this.pin)}&date=${this.date}`);
                this.employee = data.employee;
                this.punches = data.punches.map((p) => ({ ...p, edit: p.time.slice(11, 16) }));
            } catch (e) {
                this.error = e.message;
            } finally {
                this.loading = false;
            }
        },
        async run(fn) {
            this.error = '';
            try {
                await fn();
                this.changed = true;
                await this.refresh();
            } catch (e) {
                this.error = e.message;
            }
        },
        add() {
            return this.run(async () => {
                await send(urls.storeUrl, { method: 'POST', body: { pin: this.pin, time: `${this.date} ${this.newTime}`, note: this.note } });
                this.newTime = '';
            });
        },
        save(p) {
            return this.run(() => send(`${urls.punchUrl}/${p.id}`, { method: 'PUT', body: { time: `${this.date} ${p.edit}`, note: this.note } }));
        },
        remove(p) {
            if (!window.confirm(document.documentElement.lang.startsWith('ar') ? 'حذف هذه البصمة؟' : 'Delete this punch?')) return;
            return this.run(() => send(`${urls.punchUrl}/${p.id}`, { method: 'DELETE', body: { note: this.note } }));
        },
        close() {
            if (!this.show) return;
            this.show = false;
            if (this.changed) window.location.reload();
        },
    }));

    /* --------------------------------------------------- file import */
    Alpine.data('punchFileImport', (urls) => ({
        file: null, sn: 'USB', dateOrder: 'dmy', withLeaves: true,
        busy: false, error: '', info: null, done: null, dragging: false,

        pick(file) {
            if (!file) return;
            this.file = file;
            this.done = null;
            this.preview();
        },
        form() {
            const f = new FormData();
            f.append('file', this.file);
            f.append('sn', this.sn);
            f.append('date_order', this.dateOrder);
            f.append('leaves', this.withLeaves ? '1' : '0');
            return f;
        },
        async preview() {
            Object.assign(this, { busy: true, error: '', info: null });
            try {
                this.info = await send(urls.previewUrl, { method: 'POST', form: this.form() });
            } catch (e) {
                this.error = e.message;
            } finally {
                this.busy = false;
            }
        },
        async save() {
            Object.assign(this, { busy: true, error: '' });
            try {
                this.done = await send(urls.storeUrl, { method: 'POST', form: this.form() });
                this.info = null;
                setTimeout(() => window.location.reload(), 2500);
            } catch (e) {
                this.error = e.message;
            } finally {
                this.busy = false;
            }
        },
    }));

    /* ------------------------------------------------------- LAN pull */
    Alpine.data('lanPull', (opts) => ({
        ip: opts.ip || '', port: opts.port || 4370, busy: false, error: '', info: null, result: null,

        async call(url) {
            Object.assign(this, { busy: true, error: '', info: null, result: null });
            try {
                return await send(url, { method: 'POST', body: { ip: this.ip, port: Number(this.port) || 4370 } });
            } catch (e) {
                this.error = e.message;
                return null;
            } finally {
                this.busy = false;
            }
        },
        async test() { this.info = await this.call(opts.testUrl); },
        async pull() { this.result = await this.call(opts.pullUrl); },
    }));

    /* ------------------------------------------------------- overview */
    Alpine.data('attendanceOverview', (cfg) => {
    // Chart objects stay outside Alpine's reactive state
    const charts = {};
    return {
        t: cfg.t,
        exportUrl: cfg.exportUrl,
        today: cfg.today,
        from: cfg.from || cfg.today.slice(0, 8) + '01',
        to: cfg.to || cfg.today,
        pin: cfg.pin || '',
        dept: '',
        preset: cfg.from ? null : 'month',
        mode: 'day', pending: null,
        calMonth: (cfg.to || cfg.today).slice(0, 7),
        calDays: {},
        view: 'employee', search: '',
        loading: false, error: '',
        rows: [], summary: [], hours: [],

        init() {
            readColors();
            this.load();
            // Redraw in the new colours when the theme changes
            const redraw = () => { readColors(); this.$nextTick(() => this.draw()); };
            window.addEventListener('themechange', () => setTimeout(redraw, 30));
            window.matchMedia?.('(prefers-color-scheme: dark)').addEventListener?.('change', redraw);
        },

        /* ---------- period ---------- */
        usePreset(p) {
            const t = this.today;
            const d = parse(t);
            const month = t.slice(0, 8) + '01';
            const ranges = {
                today: [t, t],
                yesterday: [addDays(t, -1), addDays(t, -1)],
                week: [addDays(t, -d.getUTCDay()), t],
                last7: [addDays(t, -6), t],
                month: [month, t],
                lastmonth: [iso(new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() - 1, 1))), addDays(month, -1)],
                last30: [addDays(t, -29), t],
            };
            this.setRange(...ranges[p]);
            this.preset = p;
        },
        setRange(from, to) {
            if (!from || !to) return;
            if (from > to) [from, to] = [to, from];
            Object.assign(this, { from, to, preset: null, pending: null });
            this.calMonth = to.slice(0, 7);
            this.load();
        },
        shift(dir) {
            const span = diffDays(this.from, this.to) + 1;
            this.setRange(addDays(this.from, dir * span), addDays(this.to, dir * span));
        },
        get single() { return this.from === this.to; },
        get periodDays() {
            const out = [];
            for (let d = this.from; d <= this.to && out.length < 400; d = addDays(d, 1)) out.push(d);
            return out;
        },
        nice(s, year = true) {
            return parse(s).toLocaleDateString(document.documentElement.lang || 'en', { day: 'numeric', month: 'short', timeZone: 'UTC', ...(year ? { year: 'numeric' } : {}) });
        },
        get periodTitle() {
            return this.single
                ? parse(this.from).toLocaleDateString(document.documentElement.lang || 'en', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' })
                : `${this.nice(this.from, false)} – ${this.nice(this.to)}`;
        },
        get periodSub() {
            return this.single ? '' : `${diffDays(this.from, this.to) + 1} ${this.t.days}`;
        },
        get employeeOptions() {
            return cfg.employees.filter((e) => !this.dept || String(e.department_id) === String(this.dept));
        },
        get exportHref() {
            const q = new URLSearchParams({ from: this.from, to: this.to });
            if (this.dept) q.set('department_id', this.dept);
            return `${this.exportUrl}?${q}`;
        },

        /* ---------- data ---------- */
        query(extra = {}) {
            const q = new URLSearchParams(extra);
            if (this.dept) q.set('department_id', this.dept);
            if (this.pin) q.set('pin', this.pin);
            return q;
        },
        async load() {
            this.loading = true;
            this.error = '';
            try {
                const data = await send(`${cfg.dataUrl}?${this.query({ from: this.from, to: this.to })}`);
                this.rows = data.days;
                this.summary = data.summary;
                this.hours = data.hours;
                this.loadCalendar();
                this.$nextTick(() => this.draw());
            } catch (e) {
                this.error = e.message;
            } finally {
                this.loading = false;
            }
        },
        async loadCalendar() {
            try {
                const data = await send(`${cfg.calendarUrl}?${this.query({ month: this.calMonth })}`);
                this.calDays = Object.fromEntries(data.days.map((d) => [d.day, d]));
            } catch (e) {
                this.calDays = {};
            }
        },

        /* ---------- calendar ---------- */
        get monthTitle() {
            const [y, m] = this.calMonth.split('-').map(Number);
            return `${this.t.months[m - 1]} ${y}`;
        },
        moveMonth(dir) {
            const [y, m] = this.calMonth.split('-').map(Number);
            const d = new Date(Date.UTC(y, m - 1 + dir, 1));
            this.calMonth = iso(d).slice(0, 7);
            this.loadCalendar();
        },
        get calendarCells() {
            const [y, m] = this.calMonth.split('-').map(Number);
            const first = new Date(Date.UTC(y, m - 1, 1));
            const days = new Date(Date.UTC(y, m, 0)).getUTCDate();
            const cells = [];
            for (let i = 0; i < first.getUTCDay(); i++) cells.push({ key: 'e' + i });
            const max = Math.max(1, ...Object.values(this.calDays).map((d) => d.employees));
            for (let n = 1; n <= days; n++) {
                const day = `${this.calMonth}-${pad(n)}`;
                const info = this.calDays[day];
                cells.push({
                    key: day, day, n, future: day > this.today,
                    level: info ? Math.min(5, Math.ceil((info.employees / max) * 5)) : 0,
                    title: info ? `${info.employees} ${this.t['employees punched']} · ${info.punches} ${this.t.Punches}` : '',
                });
            }
            return cells;
        },
        cellClass(c) {
            const inRange = c.day >= this.from && c.day <= this.to;
            const levels = ['text-slate-500', 'bg-heat-1 text-[var(--heat-ink-dark)] font-medium', 'bg-heat-2 text-[var(--heat-ink-dark)] font-medium',
                'bg-heat-3 text-[var(--heat-ink-dark)] font-medium', 'bg-heat-4 text-[var(--heat-ink-light)] font-medium', 'bg-heat-5 text-[var(--heat-ink-light)] font-medium'];
            return [
                levels[c.level],
                inRange ? 'ring-2 ring-inset ring-slate-900' : '',
                c.day === this.pending ? 'ring-2 ring-inset ring-att-late' : '',
                c.day === this.today ? 'underline underline-offset-4' : '',
                c.future ? 'opacity-50 cursor-not-allowed' : 'hover:ring-1 hover:ring-inset hover:ring-slate-500',
            ].join(' ');
        },
        pickDay(day) {
            if (this.mode === 'day') return this.setRange(day, day);
            if (!this.pending) {
                this.pending = day;
                return;
            }
            this.setRange(this.pending, day);
        },

        /* ---------- figures ---------- */
        n(group) { return this.rows.filter((r) => GROUP[r.status] === group).length; },
        get stats() {
            const t = this.t;
            const rows = this.rows;
            const lateMin = rows.reduce((s, r) => s + (r.late_minutes || 0), 0);
            const ins = rows.filter((r) => r.first_in).map((r) => toMin(r.first_in.slice(11, 16)));
            const avgIn = ins.length ? clock(ins.reduce((a, b) => a + b, 0) / ins.length) : '—';
            if (this.single) {
                return [
                    { label: t.Present, value: this.n('ok') + this.n('late'), tone: 'text-slate-900', dot: 'bg-att-ok', sub: '' },
                    { label: t.Late, value: this.n('late'), tone: 'text-amber-700', dot: 'bg-att-late', sub: lateMin ? `${dur(lateMin)} ${t['in total']}` : '' },
                    { label: t.Absent, value: this.n('absent'), tone: 'text-red-700', dot: 'bg-att-absent', sub: '' },
                    { label: t['On leave'], value: this.n('leave'), tone: 'text-blue-700', dot: 'bg-att-leave', sub: '' },
                    { label: t['Not in yet'], value: rows.filter((r) => r.status === 'not_yet').length, tone: 'text-slate-500', dot: 'bg-slate-300', sub: '' },
                    { label: t['Avg first in'], value: avgIn, tone: 'text-slate-900', dot: 'bg-brand-600', sub: '' },
                ];
            }
            const workDays = this.summary.reduce((s, e) => s + e.work_days, 0);
            const present = this.summary.reduce((s, e) => s + e.present, 0);
            const worked = rows.filter((r) => r.worked_minutes);
            const avgWork = worked.length ? dur(worked.reduce((s, r) => s + r.worked_minutes, 0) / worked.length) : '—';
            return [
                { label: t.Attendance, value: workDays ? Math.round((present / workDays) * 100) + '%' : '—', tone: 'text-slate-900', dot: 'bg-att-ok', sub: `${present} ${t.of} ${workDays} ${t['work days']}` },
                { label: t['Late arrivals'], value: this.n('late'), tone: 'text-amber-700', dot: 'bg-att-late', sub: lateMin ? `${dur(lateMin)} ${t['in total']}` : '' },
                { label: t.Absences, value: this.n('absent'), tone: 'text-red-700', dot: 'bg-att-absent', sub: t['work days with no punch'] },
                { label: t['Leave days'], value: this.n('leave'), tone: 'text-blue-700', dot: 'bg-att-leave', sub: '' },
                { label: t['Avg first in'], value: avgIn, tone: 'text-slate-900', dot: 'bg-brand-600', sub: '' },
                { label: t['Avg worked'], value: avgWork, tone: 'text-slate-900', dot: 'bg-brand-600', sub: t['first in to last out'] },
            ];
        },
        get mainHeight() {
            return this.single ? Math.max(160, Math.min(900, this.rows.filter((r) => r.first_in).length * 22 + 60)) : 280;
        },

        /* ---------- charts ---------- */
        chart(ref, config) {
            charts[ref]?.destroy();
            const el = this.$refs[ref];
            if (!el || !window.Chart) return;
            charts[ref] = new window.Chart(el, {
                ...config,
                options: {
                    responsive: true, maintainAspectRatio: false, animation: false,
                    plugins: { legend: { position: 'bottom', rtl: cfg.rtl, labels: { boxWidth: 10, usePointStyle: true } } },
                    ...config.options,
                },
            });
        },
        draw() {
            const t = this.t;
            const rows = this.rows;

            if (this.single) {
                const list = rows.filter((r) => r.first_in).sort((a, b) => a.first_in.localeCompare(b.first_in));
                const startOf = (r) => toMin(r.first_in.slice(11, 16)) + (r.first_in.slice(0, 10) > r.date ? 1440 : 0);
                const endOf = (r) => (r.last_out ? toMin(r.last_out.slice(11, 16)) + (r.last_out.slice(0, 10) > r.date ? 1440 : 0) : startOf(r) + 5);
                this.chart('main', {
                    type: 'bar',
                    data: {
                        labels: list.map((r) => r.name || '#' + r.pin),
                        datasets: [{
                            data: list.map((r) => [startOf(r), endOf(r)]),
                            backgroundColor: list.map((r) => (r.status === 'late' ? COLORS.late : COLORS.ok)),
                            borderRadius: 4, barThickness: 14,
                        }],
                    },
                    options: {
                        indexAxis: 'y',
                        plugins: {
                            legend: { display: false },
                            tooltip: { callbacks: { label: (c) => `${clock(c.raw[0])} – ${clock(c.raw[1])}` } },
                        },
                        scales: { x: { reverse: cfg.rtl, ticks: { callback: (v) => clock(v) } }, y: { position: cfg.rtl ? 'right' : 'left', grid: { display: false } } },
                    },
                });
            } else {
                const days = this.periodDays;
                const count = (d, g) => rows.filter((r) => r.date === d && GROUP[r.status] === g).length;
                const ds = (label, g) => ({ label, data: days.map((d) => count(d, g)), backgroundColor: COLORS[g], borderRadius: 2, stack: 's' });
                this.chart('main', {
                    type: 'bar',
                    data: {
                        labels: days.map((d) => this.nice(d, false)),
                        datasets: [ds(t.Present, 'ok'), ds(t.Late, 'late'), ds(t['On leave'], 'leave'), ds(t.Absent, 'absent')],
                    },
                    options: {
                        interaction: { mode: 'index', intersect: false },
                        scales: { x: { stacked: true, reverse: cfg.rtl, grid: { display: false } }, y: { stacked: true, beginAtZero: true, position: cfg.rtl ? 'right' : 'left', ticks: { precision: 0 } } },
                        onClick: (e, items) => items.length && this.setRange(days[items[0].index], days[items[0].index]),
                    },
                });
            }

            // Arrival times, 30-minute slots
            const firsts = rows.filter((r) => r.first_in && r.status !== 'offday_work');
            const mins = firsts.map((r) => toMin(r.first_in.slice(11, 16)));
            const lo = mins.length ? Math.floor(Math.min(...mins) / 30) * 30 : 360;
            const hi = mins.length ? Math.floor(Math.max(...mins) / 30) * 30 : 600;
            const slots = [];
            for (let s = lo; s <= hi && slots.length < 48; s += 30) slots.push(s);
            const inSlot = (r, s) => { const m = toMin(r.first_in.slice(11, 16)); return m >= s && m < s + 30; };
            this.chart('arrival', {
                type: 'bar',
                data: {
                    labels: slots.map((s) => clock(s)),
                    datasets: [
                        { label: t['On time'], data: slots.map((s) => firsts.filter((r) => r.status !== 'late' && inSlot(r, s)).length), backgroundColor: COLORS.ok, stack: 'a', borderRadius: 2 },
                        { label: t.Late, data: slots.map((s) => firsts.filter((r) => r.status === 'late' && inSlot(r, s)).length), backgroundColor: COLORS.late, stack: 'a', borderRadius: 2 },
                    ],
                },
                options: { interaction: { mode: 'index', intersect: false }, scales: { x: { stacked: true, reverse: cfg.rtl, grid: { display: false } }, y: { stacked: true, beginAtZero: true, position: cfg.rtl ? 'right' : 'left', ticks: { precision: 0 } } } },
            });

            const byHour = Object.fromEntries(this.hours.map((h) => [h.hour, h.punches]));
            const hours = Array.from({ length: 24 }, (_, h) => h);
            this.chart('hours', {
                type: 'bar',
                data: { labels: hours.map((h) => pad(h)), datasets: [{ label: t.Punches, data: hours.map((h) => byHour[h] || 0), backgroundColor: COLORS.hours, borderRadius: 2 }] },
                options: { plugins: { legend: { display: false } }, scales: { x: { reverse: cfg.rtl, grid: { display: false } }, y: { beginAtZero: true, position: cfg.rtl ? 'right' : 'left', ticks: { precision: 0 } } } },
            });
        },

        /* ---------- map and tables ---------- */
        get byKey() { return Object.fromEntries(this.rows.map((r) => [r.pin + '|' + r.date, r])); },
        get mapEmployees() { return this.summary.filter((s) => this.matches(s)); },
        mapClass(pin, d) {
            const r = this.byKey[pin + '|' + d];
            return r ? (MAP_CLASS[GROUP[r.status]] || 'bg-white ring-1 ring-slate-200') : 'bg-white ring-1 ring-slate-100';
        },
        mapTitle(e, d) {
            const r = this.byKey[e.pin + '|' + d];
            const parts = [e.name || '#' + e.pin, this.nice(d)];
            if (r) {
                parts.push(this.t.statuses[r.status] || '');
                if (r.first_in) parts.push(r.first_in.slice(11, 16) + (r.last_out ? ' – ' + r.last_out.slice(11, 16) : ''));
            }
            return parts.join(' · ');
        },
        matches(r) {
            const q = this.search.trim().toLowerCase();
            return !q || (r.name || '').toLowerCase().includes(q) || String(r.pin).includes(q);
        },
        get tableSummary() { return this.summary.filter((s) => this.matches(s)); },
        get tableDays() { return this.rows.filter((r) => r.status !== 'weekend' && r.status !== 'holiday' && this.matches(r)).slice(0, 2000); },
        badgeClass(status) { return BADGE[status] || 'bg-slate-100 text-slate-600'; },
        dur,
    };
    });
}
