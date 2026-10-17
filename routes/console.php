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

// Polite, resumable harvest of the Congress results archive into the registered-name index.
// php artisan hf:harvest-congress --years=2025 --delay=3 [--max-pages=20]
Artisan::command('hf:harvest-congress {--years=all} {--delay=3} {--max-pages=}', function () {
    $h = new \App\Harvest\CongressHarvester((float) $this->option('delay'), fn ($l) => $this->line($l));
    $years = $h->yearPages();
    if ($this->option('years') !== 'all') {
        $want = array_map('intval', explode(',', $this->option('years')));
        $years = array_intersect_key($years, array_flip($want));
    }
    unset($years[(int) now()->format('Y')]); // the current year's show may still be running
    foreach ($years as $year => $url) {
        [$pages, $rows] = $h->year($year, $url, $this->option('max-pages') ? (int) $this->option('max-pages') : null);
        $this->info("$year: $pages class pages, $rows results stored");
    }
    $this->info('Index now holds '.\App\Models\ShowRecord::count().' registered names, '.\App\Models\ShowAppearance::count().' results.');
})->purpose('Harvest Congress show results into the registered-name index');

// Polite, resumable harvest of NRHA's public show results, newest year first.
// php artisan hf:harvest-nrha [--years=2025,2024] [--from=2025 --to=1974] --delay=3 [--max-pages=20]
Artisan::command('hf:harvest-nrha {--years=} {--from=} {--to=1974} {--delay=3} {--max-pages=}', function () {
    $h = new \App\Harvest\NrhaHarvester((float) $this->option('delay'), fn ($l) => $this->line($l));
    $years = $this->option('years')
        ? array_map('intval', explode(',', $this->option('years')))
        : range((int) ($this->option('from') ?: now()->year - 1), (int) $this->option('to'));
    foreach ($years as $year) {
        [$pages, $rows] = $h->year($year, $this->option('max-pages') ? (int) $this->option('max-pages') : null);
        $this->info("NRHA $year: $pages class pages, $rows results stored");
    }
    $this->info('Index now holds '.\App\Models\ShowRecord::count().' registered names, '.\App\Models\ShowAppearance::count().' results.');
})->purpose('Harvest NRHA show results into the registered-name index');
