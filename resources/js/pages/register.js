import { apiFetch } from '../api.js';
import { initPasswordToggles } from '../password-toggle.js';

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
    const form = document.getElementById('register-form');
    const errorBox = document.getElementById('form-error');
    const submitBtn = document.getElementById('register-submit');

    if (!form) return;

    initPasswordToggles(form);

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearErrors(form, errorBox);
        submitBtn.disabled = true;
        submitBtn.textContent = 'Creating account...';

        try {
            const response = await apiFetch('/api/auth/register', {
                method: 'POST',
                body: JSON.stringify({
                    name: form.name.value,
                    email: form.email.value,
                    phone: form.phone.value || null,
                    password: form.password.value,
                    password_confirmation: form.password_confirmation.value,
                }),
                redirectOn401: false,
            });

            if (response.ok) {
                window.location.href = '/dashboard';
                return;
            }

            const data = await response.json().catch(() => ({}));

            if (response.status === 422 && data.errors) {
                showFieldErrors(form, data.errors);
            } else {
                errorBox.textContent = data.message || 'Unable to create your account. Please try again.';
                errorBox.classList.remove('d-none');
            }
        } catch (error) {
            errorBox.textContent = 'Something went wrong. Please check your connection and try again.';
            errorBox.classList.remove('d-none');
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Sign up';
        }
    });
});
