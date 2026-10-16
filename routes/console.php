<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Give or remove staff access: php artisan hf:staff someone@example.com [--remove]
Artisan::command('hf:staff {email} {--remove}', function (string $email) {
    $user = \App\Models\User::whereRaw('lower(email) = lower(?)', [$email])->first();
    if (! $user) {
        $this->error('No account with that email.');

        return 1;
    }
    $user->forceFill(['is_staff' => ! $this->option('remove')])->save();
    \App\Support\Audit::record($this->option('remove') ? 'user.staff_removed' : 'user.staff_added', $user, null, ['email' => $user->email], null, null);
    $this->info($user->email.($user->is_staff ? ' is now staff. They must turn on two-factor sign-in to open /admin.' : ' is no longer staff.'));
})->purpose('Give or remove Horsefax staff access');
