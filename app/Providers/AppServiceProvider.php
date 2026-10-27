<?php

namespace App\Providers;

use App\Models\Horse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Plans: @can('feature', 'report_downloads') in views, Gate::allows('feature', ...) in code.
        \Illuminate\Support\Facades\Gate::define('feature', fn (?\App\Models\User $user, string $feature) => \App\Support\Plans::allows($user, $feature));
        // Members page: when someone last signed in.
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Login::class, fn ($e) => $e->user->forceFill(['last_login_at' => now()])->saveQuietly());

        Route::bind('horse', fn (string $value) => Horse::where('horsefax_id', strtoupper($value))->firstOrFail());

        Password::defaults(fn () => app()->isProduction() ? Password::min(10)->uncompromised() : Password::min(10));

        $key = fn (Request $r) => $r->user()?->id ?: $r->ip();
        RateLimiter::for('search', fn (Request $r) => Limit::perMinute($r->user() ? 60 : 20)->by($key($r)));
        RateLimiter::for('suggest', fn (Request $r) => Limit::perMinute(120)->by($key($r)));
        RateLimiter::for('radiograph-uploads', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip())); // one request per file, sets run to dozens of files
        RateLimiter::for('reports', fn (Request $r) => Limit::perMinute(30)->by($r->ip()));
        RateLimiter::for('forms', fn (Request $r) => Limit::perMinute(6)->by($key($r)));
        RateLimiter::for('uploads', fn (Request $r) => Limit::perMinute(30)->by($key($r)));
        RateLimiter::for('files', fn (Request $r) => Limit::perMinute(120)->by($key($r)));
    }
}
