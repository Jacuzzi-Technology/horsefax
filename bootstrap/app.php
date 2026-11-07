<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'standing' => \App\Http\Middleware\GoodStanding::class,
            'staff' => \App\Http\Middleware\StaffOnly::class,
            'untrapped' => \App\Http\Middleware\BlockTrapped::class,
        ]);
        $middleware->web(append: [\App\Http\Middleware\SecurityHeaders::class]);
        // Only Cloudflare (and the local host) may set X-Forwarded-For. Trusting '*' let any client fake its IP and
        // dodge the per-IP throttles. Ranges from cloudflare.com/ips-v4 and /ips-v6 (Oct 18 2026); refresh if they change.
        $middleware->trustProxies(at: [
            '127.0.0.1', '::1',
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
            '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
