<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Usage in routes/api.php:
     *   Route::middleware('role:admin')->group(...)
     *   Route::middleware('role:admin,station_owner')->group(...)
     * Register the alias in bootstrap/app.php (see README.md).
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role, $roles, true)) {
            return response()->json(['message' => 'Forbidden: insufficient role'], 403);
        }

        if ($message = $user->stationOwnerAccessDeniedMessage()) {
            return response()->json(['message' => $message], 403);
        }

        return $next($request);
    }
}
