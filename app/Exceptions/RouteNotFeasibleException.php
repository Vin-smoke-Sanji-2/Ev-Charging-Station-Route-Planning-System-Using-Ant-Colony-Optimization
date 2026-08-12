<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown when every ant, across every ACO iteration, fails to reach
 * the destination within its remaining battery range. Renders itself
 * as a 422 rather than ever persisting a broken or empty TripRoute row.
 */
class RouteNotFeasibleException extends RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
