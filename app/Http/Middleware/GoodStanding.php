<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class GoodStanding
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user() && $request->user()->standing !== 'good') {
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            return redirect()->route('login')->withErrors(['email' => 'This account is suspended. Contact Horsefax for help.']);
        }

        return $next($request);
    }
}
