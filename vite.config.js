import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/js/app.js',
                'resources/js/pages/login.js',
                'resources/js/pages/register.js',
                'resources/js/pages/plan-trip.js',
                'resources/js/pages/trip-show.js',
            ],
            refresh: true,
        }),
    ],
});
