<?php

namespace App\Http\Middleware;

use App\Http\Controllers\TrapController;
use Closure;
use Illuminate\Http\Request;

/** Turns away an IP caught by the bot trap from record and search pages (see TrapController). */
class BlockTrapped
{
    public function handle(Request $request, Closure $next)
    {
        if (TrapController::trapped($request)) {
            return response()->view('errors.record-limit', ['daily' => false, 'trapped' => true], 403);
        }

        return $next($request);
    }
}
