<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Page-level (routes/web.php) counterpart to EnsureUserHasRole, which
 * guards /api/* and returns a JSON 403 - a broken experience for a
 * Blade page, so this redirects instead, to the user's own real
 * landing page (User::ROLE_LANDING_PAGES, the same mapping login.js
 * uses client-side after a successful login - see that constant's own
 * doc block for why this can't be a single literal shared definition
 * in this stack).
 *
 * Usage in routes/web.php:
 *   Route::middleware('portal:ev_owner')->group(...)
 *   Route::middleware('portal:station_owner')->group(...)
 * Register the alias in bootstrap/app.php.
 *
 * Deliberately role-only, NOT status-aware, unlike EnsureUserHasRole -
 * a pending/rejected/suspended station_owner must still reach their
 * own Overview page (the page specifically built to explain their
 * status via stationOwnerAccessDeniedMessage()), so this middleware
 * never inspects status at all. A status-aware refinement for the
 * station-owner *management* pages specifically (redirect a non-active
 * owner to Overview instead of letting the page load and silently fail
 * via the underlying API's 403) is handled separately, inline in
 * routes/web.php, reusing that same existing method - not duplicated
 * or conflicted with here.
 */
class EnsureUserBelongsToPortal
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user && ! in_array($user->role, $roles, true)) {
            $target = $user->landingPage();

            // Defensive only - prevents an infinite redirect loop for a
            // role with no real portal of its own yet (e.g. admin, see
            // CLAUDE.md's Next steps) whose landingPage() falls back to
            // /dashboard, which itself sits behind this same middleware.
            // Real ev_owner/station_owner accounts never hit this branch,
            // since both have a real landing page outside every page
            // they'd ever be redirected away from.
            if ('/'.ltrim($target, '/') !== '/'.ltrim($request->path(), '/')) {
                return redirect($target);
            }
        }

        return $next($request);
    }
}
