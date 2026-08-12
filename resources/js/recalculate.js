import { apiFetch } from './api.js';

// Shared "Recalculate Route" trigger for the existing, long-tested
// POST /api/trips/{id}/recalculate endpoint - Trip Result and Live Trip both
// need the identical modal-collects-reason-and-battery-then-refresh behavior,
// so this is a real shared module (like navigate.js/route-map.js), not a
// copy-pasted pattern.

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

/**
 * @param {object} options
 * @param {number|string} options.tripId
 * @param {number|string|null} options.defaultBatteryPercent - pre-fills the
 *   battery field (typically the trip's originally-planned battery_percent)
 *   as a starting point only - the whole point of recalculate() is to use
 *   the driver's REAL current level, so this is never submitted unedited
 *   without the user being able to see/change it first.
 * @param {() => Promise<void>|void} options.onSuccess - called after a
 *   successful recalculation so the caller can refetch and re-render the
 *   trip (new route, new map geometry) from the server, rather than this
 *   module guessing at how each page renders a route.
 */
export function attachRecalculate({ tripId, defaultBatteryPercent, onSuccess }) {
    const modalEl = document.getElementById('recalculateModal');
    if (!modalEl) return;

    // Callers (Trip Result, Live Trip) re-run their whole load/render flow
    // after a successful recalculation, which calls this again - clone-
    // replacing the button and form each time strips any listeners from the
    // previous call so they don't pile up across repeated recalculations.
    const oldButton = document.getElementById('recalculate-btn');
    if (!oldButton) return;
    const button = oldButton.cloneNode(true);
    oldButton.replaceWith(button);

    const oldForm = document.getElementById('recalculate-form');
    const form = oldForm.cloneNode(true);
    oldForm.replaceWith(form);

    const errorBox = form.querySelector('#recalculate-form-error');
    const submitBtn = form.querySelector('#recalculate-form-submit');
    const reasonField = form.querySelector('#recalculate-reason');
    const batteryField = form.querySelector('#recalculate-battery');

    button.addEventListener('click', () => {
        clearErrors(form, errorBox);
        form.reset();
        if (defaultBatteryPercent !== null && defaultBatteryPercent !== undefined) {
            batteryField.value = defaultBatteryPercent;
        }
        window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearErrors(form, errorBox);
        submitBtn.disabled = true;
        submitBtn.textContent = 'Recalculating...';

        try {
            const response = await apiFetch(`/api/trips/${tripId}/recalculate`, {
                method: 'POST',
                body: JSON.stringify({
                    reason: reasonField.value,
                    current_battery_percent: batteryField.value,
                }),
            });

            if (response.ok) {
                // Reset the button BEFORE onSuccess() reloads the page - the
                // reload calls attachRecalculate() again, which clones this
                // exact form to strip listeners. Resetting after the reload
                // (e.g. in the finally block below) targets the OLD button,
                // since by then it's already been replaced by the clone -
                // and the clone would otherwise inherit today's disabled,
                // "Recalculating..." state forever, permanently stuck.
                submitBtn.disabled = false;
                submitBtn.textContent = 'Recalculate';
                window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                await onSuccess();
                return;
            }

            const data = await response.json().catch(() => ({}));

            if (response.status === 422 && data.errors) {
                showFieldErrors(form, data.errors);
            } else {
                errorBox.textContent = data.message || 'Unable to recalculate this route. Please try again.';
                errorBox.classList.remove('d-none');
            }
        } catch (error) {
            errorBox.textContent = 'Something went wrong. Please check your connection and try again.';
            errorBox.classList.remove('d-none');
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Recalculate';
        }
    });
}
