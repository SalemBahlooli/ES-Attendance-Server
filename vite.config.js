import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            // The font (Thmanyah Serif Display) is in resources/fonts and declared in resources/css/theme.css.
        }),
        tailwindcss(),
    ],
    server: {
        // Inside Docker: listen on every interface so the browser on the host can reach Vite.
        host: '0.0.0.0',
        hmr: { host: 'localhost' },
        watch: {
            ignored: ['**/storage/framework/views/**', '**/vendor/**'],
        },
    },
});
