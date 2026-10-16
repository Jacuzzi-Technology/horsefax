<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Staff pages need a staff account with two-factor sign-in turned on. */
class StaffOnly
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        abort_unless($user?->isStaff(), 404);
        if (config('horsefax.staff_requires_two_factor') && ! $user->two_factor_confirmed_at) {
            return redirect()->route('account')->with('status', 'Turn on two-factor sign-in to use the staff pages.');
        }

        return $next($request);
    }
}
