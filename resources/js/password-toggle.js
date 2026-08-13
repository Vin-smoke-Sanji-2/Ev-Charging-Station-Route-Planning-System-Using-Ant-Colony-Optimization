// Shared show/hide toggle for password fields (Login, Register - see each
// page's own doc comment on where this is wired in). One generic scan
// rather than page-specific code, since the markup/behavior is identical
// everywhere it's used: a `.password-toggle-btn` with `data-target` set to
// the id of the password `<input>` it controls.
export function initPasswordToggles(root = document) {
    root.querySelectorAll('.password-toggle-btn').forEach((btn) => {
        if (btn.dataset.toggleBound) return; // avoid double-binding if called twice on the same root
        btn.dataset.toggleBound = '1';

        btn.addEventListener('click', () => {
            const input = document.getElementById(btn.dataset.target);
            if (!input) return;

            const icon = btn.querySelector('i');
            const willShow = input.type === 'password';

            input.type = willShow ? 'text' : 'password';
            icon.classList.toggle('bi-eye', !willShow);
            icon.classList.toggle('bi-eye-slash', willShow);
            btn.setAttribute('aria-label', willShow ? 'Hide password' : 'Show password');
        });
    });
}
