import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.css';
import '../css/app.css';
import * as bootstrap from 'bootstrap';
import { apiFetch } from './api.js';

window.bootstrap = bootstrap;

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-logout]').forEach((link) => {
        link.addEventListener('click', async (event) => {
            event.preventDefault();
            await apiFetch('/api/auth/logout', { method: 'POST' });
            window.location.href = '/';
        });
    });
});
