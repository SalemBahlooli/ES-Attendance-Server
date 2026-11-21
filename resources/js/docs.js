/*
 | The Documentation page's Alpine components:
 |   docsPage       table of contents: search, and the section being read
 |   dayCalculator  "Try it": one day worked out the way the Calculator does it
 |                  (App\Services\Attendance\Calculator::compute), step by step
 */
const pad = (n) => String(n).padStart(2, '0');
const toMin = (t) => {
    const [h, m] = String(t || '0:0').split(':').map(Number);
    return (h || 0) * 60 + (m || 0);
};
const clock = (m) => `${pad(Math.floor((((m % 1440) + 1440) % 1440) / 60))}:${pad(((m % 60) + 60) % 60)}`;
const dur = (m) => `${Math.floor(m / 60)}:${pad(m % 60)}`;

export function registerDocs(Alpine) {
    /* ------------------------------------------------- table of contents */
    Alpine.data('docsPage', () => ({
        q: '',
        active: '',
        found: 0,
        init() {
            const sections = [...this.$root.querySelectorAll('section[data-doc]')];
            const io = new IntersectionObserver(
                (entries) => {
                    entries.filter((e) => e.isIntersecting).forEach((e) => (this.active = e.target.id));
                },
                { rootMargin: '-15% 0px -70% 0px' },
            );
            sections.forEach((s) => io.observe(s));
            this.found = sections.length;
            this.$watch('q', (q) => {
                const term = q.trim().toLowerCase();
                let n = 0;
                sections.forEach((s) => {
                    const hit = !term || s.textContent.toLowerCase().includes(term);
                    s.hidden = !hit;
                    this.$root.querySelector(`[data-toc="${s.id}"]`)?.classList.toggle('opacity-30', !hit);
                    n += hit ? 1 : 0;
                });
                this.found = n;
            });
        },
        go(id) {
            document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        },
    }));

    /* ------------------------------------------------- one day, worked out */
    Alpine.data('dayCalculator', (cfg) => ({
        start: cfg.start,
        end: cfg.end,
        graceIn: cfg.graceIn,
        graceOut: cfg.graceOut,
        breakStart: cfg.breakStart,
        breakMin: cfg.breakMin,
        breakPaid: cfg.breakPaid,
        flexible: false,
        requiredHours: '',
        overtime: cfg.overtime,
        otFrom: cfg.otFrom,
        dayType: 'work', // work | weekend | holiday | leave
        hasPunches: true,
        firstIn: cfg.start,
        lastOut: cfg.end,
        noOut: false,
        t: cfg.text,

        get shift() {
            const s = toMin(this.start);
            const length = ((toMin(this.end) - s + 1440) % 1440) || 1440;
            const unpaid = this.breakMin > 0 && !this.breakPaid ? +this.breakMin : 0;
            return {
                s, length, e: s + length, unpaid,
                breakFrom: s + ((toMin(this.breakStart) - s + 1440) % 1440),
                work: Math.max(0, length - unpaid),
            };
        },

        /** A clock time in the day's window: it starts 4 hours before the shift. */
        place(t) {
            let m = toMin(t);
            if (m < this.shift.s - 240) m += 1440;
            if (m >= this.shift.s - 240 + 1440) m -= 1440;
            return m;
        },

        get result() {
            const sh = this.shift;
            const t = this.t;
            const steps = [];
            const r = { status: 'present', late: 0, early: 0, worked: 0, overtime: 0, missingOut: false, steps };
            const off = this.dayType === 'weekend' || this.dayType === 'holiday';

            steps.push(t.window.replace(':from', clock(sh.s - 240)).replace(':to', clock(sh.s + 1200)));

            if (!this.hasPunches) {
                if (off) {
                    r.status = this.dayType;
                    steps.push(t.noPunchOff);
                } else if (this.dayType === 'leave') {
                    r.status = 'leave';
                    steps.push(t.noPunchLeave);
                } else {
                    r.status = 'absent';
                    steps.push(t.noPunchAbsent);
                }
                return r;
            }

            const f = this.place(this.firstIn);
            let l = this.noOut ? f : this.place(this.lastOut);
            if (l < f) l = f;
            const hasOut = l - f >= 5;
            steps.push(t.firstIn.replace(':time', clock(f)));

            if (hasOut) {
                let worked = l - f;
                steps.push(t.lastOut.replace(':time', clock(l)).replace(':span', dur(worked)));
                if (sh.unpaid) {
                    const overlap = Math.max(0, Math.min(l, sh.breakFrom + sh.unpaid) - Math.max(f, sh.breakFrom));
                    worked -= overlap;
                    steps.push(t.breakOff.replace(':min', overlap));
                }
                r.worked = worked;
                steps.push(t.worked.replace(':worked', dur(worked)));
            } else {
                r.missingOut = true;
                steps.push(t.noOut);
            }

            if (off) {
                r.status = 'offday_work';
                steps.push(t.offWork);
                if (this.overtime && r.worked >= Math.max(1, +this.otFrom)) {
                    r.overtime = r.worked;
                    steps.push(t.offOvertime);
                }
                return r;
            }

            if (this.flexible) {
                const required = this.requiredHours ? Math.round(+this.requiredHours * 60) : sh.work;
                steps.push(t.flexRequired.replace(':required', dur(required)));
                if (hasOut) {
                    const short = required - r.worked;
                    if (short > +this.graceOut) {
                        r.early = short;
                        steps.push(t.flexShort.replace(':min', short));
                    }
                    const extra = r.worked - required;
                    if (this.overtime && extra > 0 && extra >= +this.otFrom) {
                        r.overtime = extra;
                        steps.push(t.overtime.replace(':min', extra));
                    }
                }
                return r;
            }

            const late = f - sh.s;
            if (late > +this.graceIn) {
                r.late = late;
                r.status = 'late';
                steps.push(t.late.replace(':min', late).replace(':grace', this.graceIn));
            } else {
                steps.push(t.onTime.replace(':grace', this.graceIn));
            }
            if (hasOut) {
                const early = sh.e - l;
                if (early > +this.graceOut) {
                    r.early = early;
                    steps.push(t.early.replace(':min', early).replace(':grace', this.graceOut));
                }
                const after = l - sh.e;
                if (this.overtime && after > 0) {
                    if (after >= +this.otFrom) {
                        r.overtime = after;
                        steps.push(t.overtime.replace(':min', after));
                    } else {
                        steps.push(t.overtimeShort.replace(':min', after).replace(':from', this.otFrom));
                    }
                }
            }
            return r;
        },

        /** Positions on the 24-hour bar (window start = 0 %). */
        pos(m) {
            return Math.max(0, Math.min(100, ((m - (this.shift.s - 240)) / 1440) * 100));
        },
        get bar() {
            const sh = this.shift;
            const f = this.place(this.firstIn);
            const l = this.noOut ? f : Math.max(f, this.place(this.lastOut));
            return {
                shiftAt: this.pos(sh.s), shiftWidth: this.pos(sh.e) - this.pos(sh.s),
                graceWidth: this.pos(sh.s + +this.graceIn) - this.pos(sh.s),
                breakAt: this.pos(sh.breakFrom), breakWidth: sh.unpaid ? this.pos(sh.breakFrom + sh.unpaid) - this.pos(sh.breakFrom) : 0,
                inAt: this.pos(f), outAt: this.pos(l), workWidth: this.pos(l) - this.pos(f),
                ticks: [0, 4, 8, 12, 16, 20, 24].map((h) => ({ at: (h / 24) * 100, label: clock(sh.s - 240 + h * 60) })),
            };
        },
        dur(m) {
            return m ? dur(m) : '—';
        },
    }));
}
