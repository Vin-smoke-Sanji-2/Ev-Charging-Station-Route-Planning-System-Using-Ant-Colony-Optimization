import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.css';
import '../css/app.css';
import * as bootstrap from 'bootstrap';
import { apiFetch } from './api.js';
import { updateNotificationBadge } from './notification-badge.js';

window.bootstrap = bootstrap;

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-logout]').forEach((link) => {
        link.addEventListener('click', async (event) => {
            event.preventDefault();
            await apiFetch('/api/auth/logout', { method: 'POST' });
            window.location.href = '/';
        });
    });

    // Runs on every authenticated page (app.js loads everywhere) - a no-op
    // on guest pages, which have no sidebar/badge element at all.
    updateNotificationBadge();
});
