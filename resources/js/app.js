/*
 | Front-end entry. Alpine.js drives the small interactive bits (menus, modals,
 | tabs, the bell); Chart.js draws charts. Add your own Alpine components with
 | Alpine.data('name', () => ({ ... })) below, before Alpine.start().
 */
import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import { Chart, registerables } from 'chart.js';
import { registerAttendance } from './attendance';

Chart.register(...registerables);
window.Chart = Chart;
window.Alpine = Alpine;
Alpine.plugin(collapse);

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content;

async function postJson(url, body = {}) {
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify(body),
        credentials: 'same-origin',
    });
    if (res.status === 401 || res.status === 419) {
        window.location.reload(); // signed out / session expired
        return null;
    }
    return res.ok ? res.json() : null;
}

/* ----------------------------------------------------------------- layout */
Alpine.store('layout', {
    sidebarOpen: false, // the side menu on phones
});

/* -------------------------------------------- heartbeat (presence + counters) */
Alpine.store('live', { unread: 0, online: null, badges: {} });

function startHeartbeat(url) {
    let interacted = true;
    ['click', 'keydown', 'scroll', 'mousemove', 'touchstart'].forEach((e) =>
        window.addEventListener(e, () => (interacted = true), { passive: true }),
    );

    const beat = async () => {
        if (document.hidden) return;
        const data = await postJson(url, { active: interacted ? 1 : 0 });
        interacted = false;
        if (!data) return;
        const live = Alpine.store('live');
        live.unread = data.unread ?? 0;
        live.online = data.online ?? null;
        live.badges = data.badges ?? {};
    };

    beat();
    setInterval(beat, 60_000);
    document.addEventListener('visibilitychange', () => !document.hidden && beat());
}

/* ------------------------------------------------------------------ the bell */
Alpine.data('bell', (feedUrl, readAllUrl) => ({
    open: false,
    loading: false,
    announcements: [],
    notifications: [],
    async toggle() {
        this.open = !this.open;
        if (!this.open) return;
        this.loading = true;
        try {
            const res = await fetch(feedUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const data = await res.json();
            this.announcements = data.announcements;
            this.notifications = data.notifications;
            Alpine.store('live').unread = data.unread;
        } finally {
            this.loading = false;
        }
    },
    async readAll() {
        await postJson(readAllUrl);
        this.notifications = this.notifications.map((n) => ({ ...n, unread: false }));
        Alpine.store('live').unread = 0;
    },
}));

/* --------------------------------------------------------------- utilities */
Alpine.data('copy', (text) => ({
    copied: false,
    async copy() {
        await navigator.clipboard.writeText(text);
        this.copied = true;
        setTimeout(() => (this.copied = false), 1800);
    },
}));

Alpine.data('chart', (config) => ({
    init() {
        const brand = getComputedStyle(document.documentElement).getPropertyValue('--color-brand-600').trim() || '#0f6e56';
        config.data.datasets.forEach((ds, i) => {
            ds.borderColor ??= i === 0 ? brand : '#ef4444';
            ds.backgroundColor ??= i === 0 ? brand + '22' : '#ef444422';
        });
        new Chart(this.$refs.canvas, {
            ...config,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, usePointStyle: true } } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false } } },
                ...(config.options ?? {}),
            },
        });
    },
}));

/* Live mm:ss countdown, e.g. the sign-in lockout: x-data="countdown(90)". */
Alpine.data('countdown', (seconds) => ({
    left: Math.max(0, seconds),
    get clock() {
        const m = Math.floor(this.left / 60);
        const s = this.left % 60;
        return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
    },
    init() {
        const timer = setInterval(() => {
            this.left = Math.max(0, this.left - 1);
            if (this.left === 0) clearInterval(timer);
        }, 1000);
    },
}));

/* Sign-in pages: the soft light behind the card eases toward the pointer. */
const authBg = document.querySelector('[data-auth-bg]');
if (authBg && window.matchMedia?.('(pointer: fine)').matches) {
    let tx = 50, ty = 32, cx = 50, cy = 32, raf = null;
    const step = () => {
        cx += (tx - cx) * 0.09;
        cy += (ty - cy) * 0.09;
        authBg.style.setProperty('--mx', `${cx.toFixed(2)}%`);
        authBg.style.setProperty('--my', `${cy.toFixed(2)}%`);
        raf = Math.abs(tx - cx) > 0.1 || Math.abs(ty - cy) > 0.1 ? requestAnimationFrame(step) : null;
    };
    window.addEventListener('pointermove', (e) => {
        tx = (e.clientX / window.innerWidth) * 100;
        ty = (e.clientY / window.innerHeight) * 100;
        raf ??= requestAnimationFrame(step);
    }, { passive: true });
}

/* Forms with data-confirm="Are you sure?" ask before submitting. */
document.addEventListener('submit', (e) => {
    const msg = e.target.dataset?.confirm;
    if (msg && !window.confirm(msg)) e.preventDefault();
});

registerAttendance(Alpine);

window.App = { postJson, startHeartbeat };
Alpine.start();

const hb = document.querySelector('meta[name="heartbeat-url"]')?.content;
if (hb) startHeartbeat(hb);
