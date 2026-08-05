import { apiFetch } from '../api.js';

function formatDate(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function notificationItem(notification) {
    if (notification.is_read) {
        return `
            <li class="list-group-item" data-notification-id="${notification.id}">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="text-muted small text-uppercase">${notification.type}</div>
                        <div>${notification.message}</div>
                    </div>
                    <span class="text-muted small">${formatDate(notification.created_at)}</span>
                </div>
            </li>
        `;
    }

    return `
        <li class="list-group-item list-group-item-light border-start border-4 border-primary"
            data-notification-id="${notification.id}">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="text-muted small text-uppercase d-flex align-items-center gap-2">
                        <span class="badge rounded-pill bg-primary">&nbsp;</span> ${notification.type}
                    </div>
                    <div class="fw-semibold">${notification.message}</div>
                </div>
                <div class="text-end">
                    <div class="text-muted small mb-1">${formatDate(notification.created_at)}</div>
                    <button type="button" class="btn btn-sm btn-outline-primary mark-read-btn"
                            data-id="${notification.id}">
                        Mark as read
                    </button>
                </div>
            </div>
        </li>
    `;
}

function setPageLinkState(itemEl, linkEl, url) {
    if (url) {
        itemEl.classList.remove('disabled');
        linkEl.dataset.url = url;
    } else {
        itemEl.classList.add('disabled');
        delete linkEl.dataset.url;
    }
}

let currentPageData = null;
let lastLoadedUrl = '/api/notifications';

async function loadPage(url) {
    lastLoadedUrl = url ?? '/api/notifications';
    const loading = document.getElementById('notifications-loading');
    const empty = document.getElementById('notifications-empty');
    const wrap = document.getElementById('notifications-wrap');
    const list = document.getElementById('notifications-list');
    const pagination = document.getElementById('notifications-pagination');
    const prevItem = document.getElementById('notifications-prev-item');
    const nextItem = document.getElementById('notifications-next-item');
    const prevLink = document.getElementById('notifications-prev');
    const nextLink = document.getElementById('notifications-next');
    const markAllBtn = document.getElementById('mark-all-read-btn');

    loading.classList.remove('d-none');
    empty.classList.add('d-none');
    wrap.classList.add('d-none');
    pagination.classList.add('d-none');

    const response = await apiFetch(url ?? '/api/notifications');
    const data = response.ok ? await response.json() : null;

    loading.classList.add('d-none');

    // Same flat paginator shape as Trip History / station reviews.
    if (!data || data.total === 0) {
        empty.classList.remove('d-none');
        markAllBtn.disabled = true;
        currentPageData = null;
        return;
    }

    currentPageData = data;
    list.innerHTML = data.data.map(notificationItem).join('');
    wrap.classList.remove('d-none');

    const hasUnreadOnPage = data.data.some((n) => !n.is_read);
    markAllBtn.disabled = !hasUnreadOnPage;

    pagination.classList.remove('d-none');
    setPageLinkState(prevItem, prevLink, data.prev_page_url);
    setPageLinkState(nextItem, nextLink, data.next_page_url);
}

document.addEventListener('DOMContentLoaded', () => {
    const list = document.getElementById('notifications-list');
    if (!list) return;

    const prevItem = document.getElementById('notifications-prev-item');
    const nextItem = document.getElementById('notifications-next-item');
    const prevLink = document.getElementById('notifications-prev');
    const nextLink = document.getElementById('notifications-next');
    const markAllBtn = document.getElementById('mark-all-read-btn');

    loadPage();

    prevLink.addEventListener('click', (event) => {
        event.preventDefault();
        if (prevItem.classList.contains('disabled') || !prevLink.dataset.url) return;
        loadPage(prevLink.dataset.url);
    });

    nextLink.addEventListener('click', (event) => {
        event.preventDefault();
        if (nextItem.classList.contains('disabled') || !nextLink.dataset.url) return;
        loadPage(nextLink.dataset.url);
    });

    list.addEventListener('click', async (event) => {
        const btn = event.target.closest('.mark-read-btn');
        if (!btn) return;

        btn.disabled = true;
        await apiFetch(`/api/notifications/${btn.dataset.id}/read`, { method: 'PUT' });

        // Update just this item's styling in place rather than reloading
        // the whole page.
        const li = list.querySelector(`[data-notification-id="${btn.dataset.id}"]`);
        const notification = currentPageData?.data.find((n) => String(n.id) === btn.dataset.id);
        if (li && notification) {
            notification.is_read = true;
            li.outerHTML = notificationItem(notification);
        }

        const stillHasUnread = currentPageData?.data.some((n) => !n.is_read);
        markAllBtn.disabled = !stillHasUnread;
    });

    markAllBtn.addEventListener('click', async () => {
        markAllBtn.disabled = true;
        await apiFetch('/api/notifications/read-all', { method: 'PUT' });
        await loadPage(lastLoadedUrl);
    });
});
