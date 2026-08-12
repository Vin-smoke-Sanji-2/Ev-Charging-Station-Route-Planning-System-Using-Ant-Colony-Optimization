import { apiFetch } from '../api.js';
import { setupLocationPicker, setupUseMyLocation, collectStationFields, applyStationFieldError } from '../station-form.js';

function clearErrors(form, errorBox) {
    errorBox.classList.add('d-none');
    errorBox.textContent = '';
    form.querySelectorAll('.is-invalid').forEach((el) => el.classList.remove('is-invalid'));
    form.querySelectorAll('[data-error-for]').forEach((el) => (el.textContent = ''));
}

function showFieldErrors(form, errors) {
    Object.entries(errors).forEach(([field, messages]) => {
        // Account fields (name/email/phone/password) validate under their
        // own flat key and have a matching flat input id, handled directly
        // here. Station fields validate under a nested "station.xxx" key
        // (AuthController::register() validates 'station.name' etc.) - the
        // shared station-form.js module only ever deals in flat field
        // names, so the "station." prefix is stripped before delegating.
        if (field.startsWith('station.')) {
            applyStationFieldError(form, field.slice('station.'.length), messages[0]);
            return;
        }
        const input = document.getElementById(field);
        const feedback = form.querySelector(`[data-error-for="${field}"]`);
        if (input) input.classList.add('is-invalid');
        if (feedback) feedback.textContent = messages[0];
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('station-owner-register-form');
    if (!form) return;

    const errorBox = document.getElementById('form-error');
    const submitBtn = document.getElementById('register-submit');

    const picker = setupLocationPicker();
    setupUseMyLocation(picker);

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearErrors(form, errorBox);

        if (!picker.hasSelection()) {
            picker.showInvalidHint();
            return;
        }

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
                    role: 'station_owner',
                    station: collectStationFields(form),
                }),
                redirectOn401: false,
            });

            if (response.ok) {
                window.location.href = '/station-owner/overview';
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
            submitBtn.textContent = 'Create account & register station';
        }
    });
});
