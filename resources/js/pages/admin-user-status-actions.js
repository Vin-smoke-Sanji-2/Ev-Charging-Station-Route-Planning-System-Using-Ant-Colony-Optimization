import { apiFetch } from '../api.js';

/**
 * Shared between Station Owners and Total Users - both pages render
 * station-owner rows with identical statuses, identical actions, and
 * identical PUT /api/admin/users/{id}/status semantics, so duplicating
 * this would be a real divergence risk (two pages silently disagreeing
 * about what "Suspended" looks like or what confirm() says), unlike this
 * project's usual "copy the well-tested shape per page" precedent, which
 * applies when two pages' rendering needs genuinely differ.
 */

// Same badge tokens the original combined Users screen established:
// badge-verified navy = Active, bg-warning = Suspended, bg-danger =
// Rejected, bg-accent gold = Pending.
export function statusBadge(status) {
    if (status === 'active') return '<span class="badge badge-verified badge-status">Active</span>';
    if (status === 'suspended') return '<span class="badge bg-warning text-dark badge-status">Suspended</span>';
    if (status === 'rejected') return '<span class="badge bg-danger badge-status">Rejected</span>';
    return '<span class="badge bg-accent badge-status">Pending</span>';
}

/**
 * Renders the correct action button(s) for a station-owner row based on
 * their actual current status - Pending shows Accept+Reject; Active shows
 * Suspend; Suspended shows Reactivate; Rejected (terminal) shows nothing.
 * `extraAttrs` lets a caller attach page-specific bookkeeping (e.g.
 * Station Owners' `data-tab="pending"`, used to know which tab to refetch
 * after a mutation) without this module needing any tab concept of its
 * own - Total Users has no tabs and passes none.
 */
export function userStatusActionHtml(user, extraAttrs = '') {
    if (user.status === 'pending') {
        return `
            <div class="d-flex gap-3">
                <button type="button" class="btn btn-primary btn-admin-action" data-action="accept" data-user-id="${user.id}" ${extraAttrs}>
                    <i class="bi bi-check-lg"></i> Accept
                </button>
                <button type="button" class="btn btn-danger btn-admin-action" data-action="reject" data-user-id="${user.id}" ${extraAttrs}>
                    <i class="bi bi-x-lg"></i> Reject
                </button>
            </div>
        `;
    }

    if (user.status === 'active') {
        return `
            <button type="button" class="btn btn-danger btn-admin-action" data-action="suspend" data-user-id="${user.id}" ${extraAttrs}>
                <i class="bi bi-pause-circle"></i> Suspend
            </button>
        `;
    }

    if (user.status === 'suspended') {
        return `
            <button type="button" class="btn btn-primary btn-admin-action" data-action="reactivate" data-user-id="${user.id}" ${extraAttrs}>
                <i class="bi bi-play-circle"></i> Reactivate
            </button>
        `;
    }

    return ''; // rejected - terminal, no action offered
}

const STATUS_CHANGE_TARGET = { accept: 'active', reject: 'rejected', suspend: 'suspended', reactivate: 'active' };

// Reject/Suspend are consequential (mirrors this project's existing
// confirm()-before-destructive-action discipline); Accept/Reactivate are
// restorative and need no confirmation.
const CONFIRM_MESSAGE = {
    reject: 'Reject this station owner application? They will need to register again to be reconsidered.',
    suspend: 'Suspend this station owner? They will lose access until reactivated.',
};

// Returns false without prompting again if a destructive action's
// confirm() was dismissed - the caller should bail out when this is false.
export function confirmStatusChange(action) {
    if (!CONFIRM_MESSAGE[action]) return true;
    return confirm(CONFIRM_MESSAGE[action]);
}

export async function applyUserStatusChange(userId, action) {
    return apiFetch(`/api/admin/users/${userId}/status`, {
        method: 'PUT',
        body: JSON.stringify({ status: STATUS_CHANGE_TARGET[action] }),
    });
}
