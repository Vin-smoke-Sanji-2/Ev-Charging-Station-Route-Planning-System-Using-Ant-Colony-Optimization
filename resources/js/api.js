// Shared wrapper for every call to /api/*. No other module should call
// fetch() against the API directly - always go through apiFetch() so the
// CSRF cookie, credentials, headers, and 401 handling stay in one place.

let csrfCookiePromise = null;

function readCookie(name) {
    const match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : null;
}

// Sanctum's stateful-SPA auth needs the XSRF-TOKEN cookie before it will
// accept a non-GET request. Fetched at most once per page load.
function ensureCsrfCookie() {
    if (!csrfCookiePromise) {
        csrfCookiePromise = fetch('/sanctum/csrf-cookie', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
    }

    return csrfCookiePromise;
}

/**
 * @param {string} url
 * @param {RequestInit & { redirectOn401?: boolean }} [options]
 *   Set redirectOn401: false for calls where a 401 is an expected outcome
 *   to handle inline (e.g. the login form itself), not a sign the session
 *   expired.
 */
export async function apiFetch(url, options = {}) {
    const { redirectOn401 = true, ...init } = options;
    const method = (init.method || 'GET').toUpperCase();

    if (method !== 'GET') {
        await ensureCsrfCookie();
    }

    const headers = new Headers(init.headers || {});
    headers.set('Accept', 'application/json');

    if (init.body && !(init.body instanceof FormData) && !headers.has('Content-Type')) {
        headers.set('Content-Type', 'application/json');
    }

    if (method !== 'GET') {
        const token = readCookie('XSRF-TOKEN');
        if (token) {
            headers.set('X-XSRF-TOKEN', token);
        }
    }

    const response = await fetch(url, {
        ...init,
        method,
        headers,
        credentials: 'same-origin',
    });

    if (response.status === 401 && redirectOn401) {
        window.location.href = '/login';
        return response;
    }

    return response;
}
