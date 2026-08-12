import { apiFetch } from '../api.js';

// Extensible role -> post-login landing page, not an if/else chain - a
// future role (e.g. admin, once that portal exists) is one more entry
// here, not a new branch. Anything not listed (or an unrecognized value)
// falls back to /dashboard.
const ROLE_LANDING_PAGES = {
    ev_owner: '/dashboard',
    station_owner: '/station-owner/overview',
};

function clearErrors(form, errorBox) {
    errorBox.classList.add('d-none');
    errorBox.textContent = '';
    form.querySelectorAll('.is-invalid').forEach((el) => el.classList.remove('is-invalid'));
    form.querySelectorAll('[data-error-for]').forEach((el) => (el.textContent = ''));
}

function showFieldErrors(form, errors) {
    Object.entries(errors).forEach(([field, messages]) => {
        const input = form.querySelector(`[name="${field}"]`);
        const feedback = form.querySelector(`[data-error-for="${field}"]`);
        if (input) input.classList.add('is-invalid');
        if (feedback) feedback.textContent = messages[0];
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('login-form');
    const errorBox = document.getElementById('form-error');
    const submitBtn = document.getElementById('login-submit');

    if (!form) return;

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearErrors(form, errorBox);
        submitBtn.disabled = true;
        submitBtn.textContent = 'Logging in...';

        try {
            const response = await apiFetch('/api/auth/login', {
                method: 'POST',
                body: JSON.stringify({
                    email: form.email.value,
                    password: form.password.value,
                }),
                redirectOn401: false,
            });

            if (response.ok) {
                const user = await response.json();
                window.location.href = ROLE_LANDING_PAGES[user.role] ?? '/dashboard';
                return;
            }

            const data = await response.json().catch(() => ({}));

            if (response.status === 422 && data.errors) {
                showFieldErrors(form, data.errors);
            } else {
                errorBox.textContent = data.message || 'Unable to log in. Please try again.';
                errorBox.classList.remove('d-none');
            }
        } catch (error) {
            errorBox.textContent = 'Something went wrong. Please check your connection and try again.';
            errorBox.classList.remove('d-none');
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Log in';
        }
    });
});
