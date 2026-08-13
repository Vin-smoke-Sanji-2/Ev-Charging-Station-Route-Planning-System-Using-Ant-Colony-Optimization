import { apiFetch } from '../api.js';

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

function flashSuccess(successBox) {
    successBox.classList.remove('d-none');
    setTimeout(() => successBox.classList.add('d-none'), 3000);
}

function renderAvatar(avatarUrl) {
    const preview = document.getElementById('avatar-preview');
    const placeholder = document.getElementById('avatar-placeholder');
    const removeBtn = document.getElementById('remove-avatar-btn');
    // The shared navbar's own avatar (top-right corner, every layout) -
    // kept in sync from this same function/call sites so it never needs
    // a full page reload to reflect a just-uploaded or just-removed
    // photo, matching the Profile page's own preview.
    const navbarImg = document.getElementById('navbar-avatar-img');
    const navbarInitial = document.getElementById('navbar-avatar-initial');

    if (avatarUrl) {
        const cacheBusted = `${avatarUrl}?t=${Date.now()}`; // cache-bust so a replaced photo shows immediately
        preview.src = cacheBusted;
        preview.classList.remove('d-none');
        placeholder.classList.add('d-none');
        removeBtn.classList.remove('d-none');

        navbarImg.src = cacheBusted;
        navbarImg.classList.remove('d-none');
        navbarInitial.classList.add('d-none');
    } else {
        preview.classList.add('d-none');
        placeholder.classList.remove('d-none');
        removeBtn.classList.add('d-none');

        navbarImg.removeAttribute('src');
        navbarImg.classList.add('d-none');
        navbarInitial.classList.remove('d-none');
    }
}

async function loadProfile() {
    const response = await apiFetch('/api/auth/me');
    if (!response.ok) return;

    const user = await response.json();
    document.getElementById('name').value = user.name ?? '';
    document.getElementById('email').value = user.email ?? '';
    document.getElementById('phone').value = user.phone ?? '';
    renderAvatar(user.avatar_url);
}

document.addEventListener('DOMContentLoaded', () => {
    const profileForm = document.getElementById('profile-form');
    if (!profileForm) return;

    loadProfile();

    // --- Avatar upload / remove ---
    const avatarInput = document.getElementById('avatar-input');
    const uploadBtn = document.getElementById('upload-avatar-btn');
    const removeBtn = document.getElementById('remove-avatar-btn');
    const avatarError = document.getElementById('avatar-error');

    uploadBtn.addEventListener('click', () => avatarInput.click());

    avatarInput.addEventListener('change', async () => {
        const file = avatarInput.files[0];
        if (!file) return;

        avatarError.classList.add('d-none');
        uploadBtn.disabled = true;
        uploadBtn.textContent = 'Uploading...';

        const formData = new FormData();
        formData.append('avatar', file);

        try {
            const response = await apiFetch('/api/auth/avatar', {
                method: 'POST',
                body: formData,
            });

            const data = await response.json().catch(() => ({}));

            if (response.ok) {
                renderAvatar(data.avatar_url);
            } else {
                avatarError.textContent = data.errors?.avatar?.[0] || data.message || 'Unable to upload this photo.';
                avatarError.classList.remove('d-none');
            }
        } catch (error) {
            avatarError.textContent = 'Something went wrong. Please check your connection and try again.';
            avatarError.classList.remove('d-none');
        } finally {
            avatarInput.value = '';
            uploadBtn.disabled = false;
            uploadBtn.innerHTML = '<i class="bi bi-upload"></i> Upload Photo';
        }
    });

    removeBtn.addEventListener('click', async () => {
        if (!confirm('Remove your profile photo?')) return;

        removeBtn.disabled = true;
        const response = await apiFetch('/api/auth/avatar', { method: 'DELETE' });
        const data = await response.json().catch(() => ({}));
        if (response.ok) {
            renderAvatar(data.avatar_url);
        }
        removeBtn.disabled = false;
    });

    // --- Profile info form ---
    const profileErrorBox = document.getElementById('profile-form-error');
    const profileSuccessBox = document.getElementById('profile-form-success');
    const profileSubmitBtn = document.getElementById('profile-form-submit');

    profileForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearErrors(profileForm, profileErrorBox);
        profileSuccessBox.classList.add('d-none');
        profileSubmitBtn.disabled = true;
        profileSubmitBtn.textContent = 'Saving...';

        try {
            const response = await apiFetch('/api/auth/profile', {
                method: 'PUT',
                body: JSON.stringify({
                    name: profileForm.name.value,
                    phone: profileForm.phone.value || null,
                }),
            });

            const data = await response.json().catch(() => ({}));

            if (response.ok) {
                flashSuccess(profileSuccessBox);
            } else if (response.status === 422 && data.errors) {
                showFieldErrors(profileForm, data.errors);
            } else {
                profileErrorBox.textContent = data.message || 'Unable to save your profile. Please try again.';
                profileErrorBox.classList.remove('d-none');
            }
        } catch (error) {
            profileErrorBox.textContent = 'Something went wrong. Please check your connection and try again.';
            profileErrorBox.classList.remove('d-none');
        } finally {
            profileSubmitBtn.disabled = false;
            profileSubmitBtn.textContent = 'Save Changes';
        }
    });

    // --- Change password form ---
    const passwordForm = document.getElementById('password-form');
    const passwordErrorBox = document.getElementById('password-form-error');
    const passwordSuccessBox = document.getElementById('password-form-success');
    const passwordSubmitBtn = document.getElementById('password-form-submit');

    passwordForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearErrors(passwordForm, passwordErrorBox);
        passwordSuccessBox.classList.add('d-none');
        passwordSubmitBtn.disabled = true;
        passwordSubmitBtn.textContent = 'Updating...';

        try {
            const response = await apiFetch('/api/auth/password', {
                method: 'PUT',
                body: JSON.stringify({
                    current_password: passwordForm.current_password.value,
                    password: passwordForm.password.value,
                    password_confirmation: passwordForm.password_confirmation.value,
                }),
            });

            const data = await response.json().catch(() => ({}));

            if (response.ok) {
                passwordForm.current_password.value = '';
                passwordForm.password.value = '';
                passwordForm.password_confirmation.value = '';
                flashSuccess(passwordSuccessBox);
            } else if (response.status === 422 && data.errors) {
                // Real validation failures (e.g. password_confirmation mismatch)
                // come back in the standard {errors: {...}} shape.
                showFieldErrors(passwordForm, data.errors);
            } else {
                // changePassword()'s wrong-current-password branch is a manual
                // check, not a validation rule, so it returns a plain
                // {message: "..."} with no "errors" key - same fallback
                // pattern login.js/register.js use for their own 401s.
                passwordErrorBox.textContent = data.message || 'Unable to update your password. Please try again.';
                passwordErrorBox.classList.remove('d-none');
            }
        } catch (error) {
            passwordErrorBox.textContent = 'Something went wrong. Please check your connection and try again.';
            passwordErrorBox.classList.remove('d-none');
        } finally {
            passwordSubmitBtn.disabled = false;
            passwordSubmitBtn.textContent = 'Update Password';
        }
    });
});
