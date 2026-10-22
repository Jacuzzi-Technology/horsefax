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

// Polite, resumable harvest of NRCHA's public results data files, newest year first (skips the current year).
// php artisan hf:harvest-nrcha [--years=2025] --delay=3 [--max-shows=5]
Artisan::command('hf:harvest-nrcha {--years=} {--delay=3} {--max-shows=}', function () {
    $h = new \App\Harvest\NrchaHarvester((float) $this->option('delay'), fn ($l) => $this->line($l));
    $years = $this->option('years') ? array_map('intval', explode(',', $this->option('years')))
        : array_values(array_filter($h->years(), fn ($y) => $y < (int) now()->format('Y')));
    foreach ($years as $year) {
        [$shows, $rows] = $h->year($year, $this->option('max-shows') ? (int) $this->option('max-shows') : null);
        $this->info("NRCHA $year: $shows shows, $rows results stored");
    }
    $this->info('Index now holds '.\App\Models\ShowRecord::count().' registered names, '.\App\Models\ShowAppearance::count().' results.');
})->purpose('Harvest NRCHA show results (with sire, dam and dam\'s sire) into the registered-name index');

// Polite, resumable harvest of a state Quarter Horse association's posted results PDFs (nqha, moqha).
// php artisan hf:harvest-state nqha --delay=3 [--max-files=2]
Artisan::command('hf:harvest-state {source} {--delay=3} {--max-files=}', function () {
    $h = new \App\Harvest\StatePdfHarvester($this->argument('source'), (float) $this->option('delay'), fn ($l) => $this->line($l));
    [$files, $rows] = $h->run($this->option('max-files') ? (int) $this->option('max-files') : null);
    $this->info($this->argument('source').": $files files read, $rows results stored");
    $this->info('Index now holds '.\App\Models\ShowRecord::count().' registered names, '.\App\Models\ShowAppearance::count().' results.');
})->purpose('Harvest a state Quarter Horse association\'s results PDFs into the registered-name index');

// Ongoing collection of EquestrianLive.com hunter/jumper results: each finished show once, one request per 20 seconds.
// php artisan hf:harvest-equestrianlive [--delay=20] [--max-shows=1]   (cron runs it every 6 hours)
Artisan::command('hf:harvest-equestrianlive {--delay=20} {--max-shows=}', function () {
    $h = new \App\Harvest\EquestrianLiveHarvester((float) $this->option('delay'), fn ($l) => $this->line(now()->format('H:i:s').' '.$l));
    [$shows, $rows] = $h->run($this->option('max-shows') ? (int) $this->option('max-shows') : null);
    $this->info("EquestrianLive: $shows shows read, $rows results stored");
})->purpose('Collect finished shows from EquestrianLive.com into the registered-name index');

// Build horse records from the registered-name index (Richard, Oct 17 2026). Rerunnable; cron runs it nightly.
// php artisan hf:build-records [--limit=100]
Artisan::command('hf:build-records {--limit=}', function () {
    $out = app(\App\Actions\Record\BuildRecordsFromResults::class)($this->option('limit') ? (int) $this->option('limit') : null, fn ($l) => $this->line(now()->format('H:i:s').' '.$l));
    $this->info(json_encode($out));
})->purpose('Create unverified horse records from published show results and attach the results');

// Super Sires stallion pedigrees and nominated foals (one page per 10 seconds, resumable).
// php artisan hf:harvest-supersires [--delay=10] [--max-pages=5]
Artisan::command('hf:harvest-supersires {--delay=10} {--max-pages=}', function () {
    $out = (new \App\Harvest\SuperSiresHarvester((float) $this->option('delay'), fn ($l) => $this->line($l)))->run($this->option('max-pages') ? (int) $this->option('max-pages') : null);
    $this->info('Super Sires: '.json_encode($out));
})->purpose('Collect Super Sires stallion pedigrees and nominated foals into horse records');

// Sale history (Richard, Oct 17 2026): Live Ag, Premier and Platinum sale results into sale_lots, matched to records
// by hf:match-sales; WSQHA show results (with registration numbers and foal years) into the registered-name index.
// php artisan hf:harvest-liveag [--events=150,398] --delay=3 [--max-events=1]
Artisan::command('hf:harvest-liveag {--events=} {--delay=3} {--max-events=}', function () {
    $h = new \App\Harvest\LiveAgHarvester((float) $this->option('delay'), fn ($l) => $this->line(now()->format('H:i:s').' '.$l));
    $ids = $this->option('events') ? array_map('intval', explode(',', $this->option('events'))) : null;
    [$sales, $lots] = $h->run($ids, $this->option('max-events') ? (int) $this->option('max-events') : null);
    $this->info("Live Ag: $sales sales read, $lots lots stored");
})->purpose('Collect finished horse sales from Live Ag into the sale-lots index');

// php artisan hf:harvest-wsqha --delay=3 [--max-files=2]
Artisan::command('hf:harvest-wsqha {--delay=3} {--max-files=}', function () {
    [$files, $rows] = (new \App\Harvest\WsqhaHarvester((float) $this->option('delay'), fn ($l) => $this->line($l)))->run($this->option('max-files') ? (int) $this->option('max-files') : null);
    $this->info("WSQHA: $files files read, $rows results stored");
})->purpose('Harvest WSQHA results PDFs (with registration numbers and foal years) into the registered-name index');

// php artisan hf:harvest-premier --delay=3 [--max-sales=1]
Artisan::command('hf:harvest-premier {--delay=3} {--max-sales=}', function () {
    [$catalog, $sales, $lots] = (new \App\Harvest\PremierHarvester((float) $this->option('delay'), fn ($l) => $this->line($l)))->run($this->option('max-sales') ? (int) $this->option('max-sales') : null);
    $this->info("Premier: $catalog catalog horses, $sales sales read, $lots result lots stored");
})->purpose('Collect Premier Horse Sales results PDFs and the coming sale\'s catalog into the sale-lots index');

// php artisan hf:harvest-platinum [--all] --delay=3 [--max-pages=2]
Artisan::command('hf:harvest-platinum {--all} {--delay=3} {--max-pages=}', function () {
    [$pages, $lots] = (new \App\Harvest\PlatinumHarvester((float) $this->option('delay'), fn ($l) => $this->line(now()->format('H:i:s').' '.$l)))->run((bool) $this->option('all'), $this->option('max-pages') ? (int) $this->option('max-pages') : null);
    $this->info("Platinum: $pages listing pages read, $lots lots stored");
})->purpose('Collect Platinum Equine Auction expired auctions into the sale-lots index');

// php artisan hf:match-sales [--limit=100]   (cron runs it nightly after hf:build-records)
Artisan::command('hf:match-sales {--limit=}', function () {
    $out = app(\App\Actions\Record\MatchSaleLots::class)($this->option('limit') ? (int) $this->option('limit') : null, fn ($l) => $this->line(now()->format('H:i:s').' '.$l));
    $this->info(json_encode($out));
})->purpose('Attach published sale lots to horse records (registration number, or name + parentage), queue the rest for staff');

// Highpoint Performance stallions with sire, dam and the grandparents read from each pedigree chart.
// php artisan hf:harvest-highpoint [--delay=5]
Artisan::command('hf:harvest-highpoint {--delay=5}', function () {
    $out = (new \App\Harvest\HighpointHarvester((float) $this->option('delay'), fn ($l) => $this->line($l)))->run();
    $this->info('Highpoint: '.json_encode($out));
})->purpose('Collect Highpoint Performance stallions and pedigrees into horse records');

// Nebraska Ranch Horse Association stallion directory (sire and dam).
// php artisan hf:harvest-nerha [--delay=5]
Artisan::command('hf:harvest-nerha {--delay=5}', function () {
    $out = (new \App\Harvest\NerhaHarvester((float) $this->option('delay'), fn ($l) => $this->line($l)))->run();
    $this->info('NeRHA: '.json_encode($out));
})->purpose('Collect the Nebraska Ranch Horse Association stallion directory into horse records');

// Premier Sires: enrolled stallions, every nominated foal (lookup table) and The Premier futurity results.
// php artisan hf:harvest-premiersires [--delay=5]
Artisan::command('hf:harvest-premiersires {--delay=5}', function () {
    $out = (new \App\Harvest\PremierSiresHarvester((float) $this->option('delay'), fn ($l) => $this->line(now()->format('H:i:s').' '.$l)))->run();
    $this->info('Premier Sires: '.json_encode($out));
})->purpose('Collect Premier Sires stallions, nominated foals and futurity results');

// Import pedigrees read from published stallion pages (a JSON list made by an agent that checked each site's robots
// and terms). Each item: name, foal_year, registry, registration_number, color, sex, source_url, from_image, pedigree.
// php artisan hf:import-pedigrees storage/app/private/imports/stallion-pedigrees.json --source=stallion-sites
Artisan::command('hf:import-pedigrees {file} {--source=stallion-sites}', function () {
    $items = json_decode((string) file_get_contents($this->argument('file')), true);
    if (! is_array($items)) {
        return $this->error('Not a JSON list.');
    }
    $source = \App\Models\DataSource::firstOrCreate(['key' => $this->option('source')], [
        'name' => 'Stallion websites (pedigrees)', 'url' => 'https://premiersires.com/stallions/', 'permission' => 'no_terms_posted',
        'terms_note' => 'Oct 18 2026: pedigrees from the stallions\' own websites linked from Premier Sires; each site\'s robots.txt and terms were checked first and blocked or restricted sites skipped. Richard approved.',
    ]);
    $out = [];
    foreach ($items as $h) {
        $ped = collect($h['pedigree'] ?? [])->filter(fn ($n, $p) => preg_match('/^[sd]{1,5}$/', (string) $p) && trim((string) $n) !== '')->map(fn ($n) => \App\Harvest\NrchaHarvester::display(trim($n)))->all();
        if (trim((string) ($h['name'] ?? '')) === '' || ! $ped || empty($h['source_url'])) {
            $out['invalid'] = ($out['invalid'] ?? 0) + 1;
            continue;
        }
        $page = \App\Models\HarvestPage::firstOrNew(['url' => $h['source_url']], ['data_source_id' => $source->id, 'kind' => 'stallion_site']);
        $status = app(\App\Actions\Record\ImportPublishedHorse::class)([
            'name' => \App\Harvest\NrchaHarvester::display(trim($h['name'])), 'foal_year' => $h['foal_year'] ?? null,
            'registry' => isset($h['registry']) ? \App\Harvest\SuperSiresParser::registry((string) $h['registry']) : null,
            'registration_number' => $h['registration_number'] ?? null, 'color' => isset($h['color']) ? \App\Harvest\SuperSiresParser::color((string) $h['color']) : null,
            'sex' => $h['sex'] ?? 'stallion', 'birth_date' => null, 'pedigree' => $ped, 'results' => [],
        ], $h['source_url']);
        $page->fill(['status' => 'parsed', 'rows' => count($ped), 'fetched_at' => now(), 'error' => ! empty($h['from_image']) ? 'Read from a pedigree image' : null])->save();
        $out[$status] = ($out[$status] ?? 0) + 1;
    }
    $this->info(json_encode($out));
})->purpose('Import pedigrees read from published stallion pages into horse records');

// Possible duplicate names for staff review (spacing/punctuation variants and one-letter variants). Cron runs it nightly.
// php artisan hf:find-name-variants
Artisan::command('hf:find-name-variants', function () {
    $this->info(app(\App\Actions\Record\FindNameVariants::class)().' new pairs; '.\App\Models\NameReview::where('status', 'open')->count().' open.');
})->purpose('List records whose names are near-duplicates, for staff to decide');

// IFCE–SIRE "Fichier des équidés" (French national registry open data): bulk import, resumable.
// php -d memory_limit=1G artisan hf:import-ifce storage/app/private/harvest/ifce/donnees-equides-20260824.csv [--limit=5000]
Artisan::command('hf:import-ifce {file} {--url=https://static.data.gouv.fr/resources/fichier-des-equides/20260824-072057/donnees-equides.csv} {--limit=} {--chunk=2000}', function () {
    $out = (new \App\Harvest\IfceImporter(fn ($l) => $this->line(now()->format('H:i:s').' '.$l)))
        ->run($this->argument('file'), $this->option('url'), $this->option('limit') ? (int) $this->option('limit') : null, (int) $this->option('chunk'));
    $this->info('IFCE: '.json_encode($out));
})->purpose('Import the French national equine registry open-data file into horse records');

// Horse of My Dreams horses (Richard's own site), from the WordPress export made by bin/homd-export.php on the HOMD
// server. Fills blanks, adds pedigree, the listing, photos and video. Resumable: listings already imported are skipped.
// php artisan hf:import-homd storage/app/private/harvest/homd/homd-horses.json [--no-photos] [--limit=5] [--delay=0.5]
Artisan::command('hf:import-homd {file} {--no-photos} {--limit=} {--delay=0.5}', function (string $file) {
    $horses = json_decode(file_get_contents($file), true) ?? [];
    $import = app(\App\Actions\Record\ImportHomdHorse::class);
    $out = [];
    foreach (array_slice($horses, 0, $this->option('limit') ? (int) $this->option('limit') : null) as $i => $h) {
        $status = $import($h, ! $this->option('no-photos'), (float) $this->option('delay'), fn ($l) => $this->line($l));
        $out[$status] = ($out[$status] ?? 0) + 1;
        $this->line(now()->format('H:i:s').' '.($i + 1).'/'.count($horses).' '.$status.' '.($h['registered_name'] ?: $h['barn_name']));
    }
    $this->info('HOMD: '.json_encode($out));
})->purpose('Import Horse of My Dreams horses (identity, pedigree, listing, photos, video) into Horsefax records');

// Billings Livestock Commission horse sales: catalogs (registration numbers, pedigrees) and Top 20/40 price lists, from
// the live site and the Internet Archive. Resumable; cron runs it weekly with the other sale sources.
// php artisan hf:harvest-billings [--no-archive] [--delay=3] [--max-files=2]
Artisan::command('hf:harvest-billings {--no-archive} {--delay=3} {--max-files=}', function () {
    $out = (new \App\Harvest\BillingsHarvester((float) $this->option('delay'), fn ($l) => $this->line(now()->format('H:i:s').' '.$l)))
        ->run(! $this->option('no-archive'), $this->option('max-files') ? (int) $this->option('max-files') : null);
    $this->info('Billings: '.json_encode($out));
})->purpose('Collect Billings Livestock horse-sale catalogs and Top lists into the sale-lots index');

Artisan::command('hf:create-sale-records {source} {--dry-run}', function (string $source) {
    $out = app(\App\Actions\Record\CreateRecordsFromSaleLots::class)($source, (bool) $this->option('dry-run'), fn ($l) => $this->line(now()->format('H:i:s').' '.$l));
    $this->info(($this->option('dry-run') ? 'Dry run (nothing saved): ' : '').json_encode($out));
})->purpose('Create records for a sale source\'s identified horses that no record matches, with their sale entries');

// Sale lots flagged "same name, different horse" (every record of that name contradicts the lot): the lot's horse gets
// its own record when the lot identifies it (registration number or sire). Richard, Oct 19 2026.
// php artisan hf:split-sale-conflicts [--dry-run]
Artisan::command('hf:split-sale-conflicts {--dry-run}', function () {
    $out = app(\App\Actions\Record\CreateRecordsFromSaleLots::class)->splitConflicts((bool) $this->option('dry-run'), fn ($l) => $this->line(now()->format('H:i:s').' '.$l));
    $this->info(($this->option('dry-run') ? 'Dry run (nothing saved): ' : '').json_encode($out));
})->purpose('Give conflicting sale lots that identify their horse a record of their own');

Artisan::command('hf:harvest-amshows {--delay=3} {--max-files=} {--refresh}', function () {
    $out = (new \App\Harvest\AmShowsHarvester((float) $this->option('delay'), fn ($l) => $this->line(now()->format('H:i:s').' '.$l), (bool) $this->option('refresh')))
        ->run($this->option('max-files') ? (int) $this->option('max-files') : null);
    $this->info('A&M Show Services: '.json_encode($out));
})->purpose('Collect A&M Show Services (showmypony.com) reining results from its public Google Drive folders');

// Search-backed suggestions for the staff queues (pilot, Richard Oct 19 2026). Needs BRAVE_SEARCH_API_KEY in .env.
// Picks items with no suggestion yet: name pairs across priorities (same-letter pairs that disagree first) and sale lots
// waiting for review or in conflict. php artisan hf:search-suggest [--names=100] [--lots=100]
Artisan::command('hf:search-suggest {--names=100} {--lots=100}', function () {
    $web = new \App\Search\WebEvidence(fn ($l) => $this->line(now()->format('H:i:s').' '.$l));
    $suggest = new \App\Actions\Record\SuggestFromSearch($web);
    $done = \App\Models\ReviewSuggestion::get(['subject_type', 'subject_id'])->groupBy('subject_type')->map(fn ($g) => $g->pluck('subject_id')->all());
    $n = (int) $this->option('names');
    $clean = \App\Http\Controllers\Admin\NameReviewController::batchRows()->pluck('review.id')->all(); // those go to the batch page
    $pairs = collect();
    foreach ([1, 2, 3, 4] as $p) {
        $pairs = $pairs->merge(\App\Models\NameReview::with(['a', 'b'])->where('status', 'open')->where('priority', $p)
            ->whereNotIn('id', array_merge($clean, $done['name_review'] ?? []))->inRandomOrder()->limit((int) ceil($n / 4))->get());
    }
    $lotIds = collect(['conflict', 'queued'])->flatMap(fn ($st) => \App\Models\SaleLot::whereNull('horse_id')->where('review_status', $st)
        ->whereNotIn('id', $done['sale_lot'] ?? [])->inRandomOrder()->limit((int) ceil(((int) $this->option('lots')) / 2))->pluck('id'));
    $out = [];
    foreach ($pairs->take($n) as $r) {
        $s = $suggest->forPair($r);
        $out['name:'.$s->suggestion] = ($out['name:'.$s->suggestion] ?? 0) + 1;
        $this->line($r->a->registered_name.' → '.$s->suggestion);
    }
    foreach (\App\Models\SaleLot::whereIn('id', $lotIds->take((int) $this->option('lots')))->get() as $lot) {
        $s = $suggest->forLot($lot);
        $out['lot:'.$s->suggestion] = ($out['lot:'.$s->suggestion] ?? 0) + 1;
        $this->line($lot->horse_name.' → '.$s->suggestion);
    }
    $this->info(json_encode($out + ['searches' => $web->searches, 'pages_fetched' => $web->fetched]));
})->purpose('Search the web for name pairs and sale lots waiting for staff, and write suggested answers');

// How the search suggestions are doing: staff accepted vs rejected, and what they cost.
Artisan::command('hf:search-report', function () {
    $rows = \App\Models\ReviewSuggestion::selectRaw('subject_type, suggestion, status, count(*) n, sum(cost_cents) cents')->groupBy('subject_type', 'suggestion', 'status')->get();
    $this->table(['item', 'suggestion', 'status', 'count', 'cost ($)'], $rows->map(fn ($r) => [$r->subject_type, $r->suggestion, $r->status, $r->n, number_format($r->cents / 100, 2)]));
    $acc = $rows->where('status', 'accepted')->sum('n');
    $rej = $rows->where('status', 'rejected')->sum('n');
    $items = $rows->sum('n');
    $this->info('Items: '.$items.' · with an answer: '.$rows->where('suggestion', '!=', 'unsure')->sum('n').' · accepted '.$acc.', rejected '.$rej
        .($acc + $rej ? ' ('.round(100 * $acc / ($acc + $rej)).'% accepted)' : '').' · cost per item $'.($items ? number_format($rows->sum('cents') / 100 / $items, 4) : '0'));
})->purpose('Pilot report for search-backed review suggestions');
