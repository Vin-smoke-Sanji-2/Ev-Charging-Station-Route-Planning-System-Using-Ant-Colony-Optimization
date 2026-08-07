import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/js/app.js',
                'resources/js/pages/dashboard.js',
                'resources/js/pages/login.js',
                'resources/js/pages/register.js',
                'resources/js/pages/plan-trip.js',
                'resources/js/pages/trip-show.js',
                'resources/js/pages/vehicles-index.js',
                'resources/js/pages/trip-history.js',
                'resources/js/pages/stations-index.js',
                'resources/js/pages/stations-show.js',
                'resources/js/pages/favorites-index.js',
                'resources/js/pages/notifications-index.js',
                'resources/js/pages/profile-index.js',
            ],
            refresh: true,
        }),
    ],
});
