import { apiFetch } from '../api.js';
import { initPasswordToggles } from '../password-toggle.js';

// Extensible role -> post-login landing page, not an if/else chain - a
// future role (e.g. admin, once that portal exists) is one more entry
// here, not a new branch. Anything not listed (or an unrecognized value)
// falls back to /dashboard.
const ROLE_LANDING_PAGES = {
    ev_owner: '/dashboard',
    station_owner: '/station-owner/overview',
    admin: '/admin/overview',
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

function redirectAfterLogin(user) {
    window.location.href = ROLE_LANDING_PAGES[user.role] ?? '/dashboard';
}

document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('login-form');
    const errorBox = document.getElementById('form-error');
    const submitBtn = document.getElementById('login-submit');
    const signupHint = document.getElementById('login-signup-hint');

    const otpForm = document.getElementById('otp-form');
    const otpEmailInput = document.getElementById('otp-email');
    const otpEmailDisplay = document.getElementById('otp-email-display');
    const otpCodeInput = document.getElementById('otp-code');
    const otpSubmitBtn = document.getElementById('otp-submit');
    const otpBackBtn = document.getElementById('otp-back-btn');
    const otpResendBtn = document.getElementById('otp-resend-btn');

    if (!form) return;

    initPasswordToggles(form);

    function showOtpStep(email) {
        form.classList.add('d-none');
        signupHint.classList.add('d-none');
        otpForm.classList.remove('d-none');
        otpEmailInput.value = email;
        otpEmailDisplay.textContent = email;
        otpCodeInput.value = '';
        clearErrors(otpForm, errorBox);
        otpCodeInput.focus();
    }

    function showLoginStep() {
        otpForm.classList.add('d-none');
        form.classList.remove('d-none');
        signupHint.classList.remove('d-none');
        clearErrors(form, errorBox);
    }

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

            const data = await response.json().catch(() => ({}));

            if (response.ok) {
                if (data.otp_required) {
                    showOtpStep(data.email);
                } else {
                    redirectAfterLogin(data);
                }
                return;
            }

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

    otpForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearErrors(otpForm, errorBox);
        otpSubmitBtn.disabled = true;
        otpSubmitBtn.textContent = 'Verifying...';

        try {
            const response = await apiFetch('/api/auth/verify-otp', {
                method: 'POST',
                body: JSON.stringify({
                    email: otpEmailInput.value,
                    code: otpCodeInput.value,
                }),
                redirectOn401: false,
            });

            const data = await response.json().catch(() => ({}));

            if (response.ok) {
                redirectAfterLogin(data);
                return;
            }

            errorBox.textContent = data.message || 'Unable to verify that code. Please try again.';
            errorBox.classList.remove('d-none');
        } catch (error) {
            errorBox.textContent = 'Something went wrong. Please check your connection and try again.';
            errorBox.classList.remove('d-none');
        } finally {
            otpSubmitBtn.disabled = false;
            otpSubmitBtn.textContent = 'Verify code';
        }
    });

    otpBackBtn.addEventListener('click', showLoginStep);

    otpResendBtn.addEventListener('click', async () => {
        otpResendBtn.disabled = true;
        const originalText = otpResendBtn.textContent;
        otpResendBtn.textContent = 'Sending...';

        try {
            await apiFetch('/api/auth/resend-otp', {
                method: 'POST',
                body: JSON.stringify({ email: otpEmailInput.value }),
            });
            otpResendBtn.textContent = 'Code sent!';
        } catch (error) {
            otpResendBtn.textContent = originalText;
        } finally {
            setTimeout(() => {
                otpResendBtn.disabled = false;
                otpResendBtn.textContent = originalText;
            }, 3000);
        }
    });
});
