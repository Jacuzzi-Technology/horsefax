@php
    use App\Support\Record;
    use App\Support\Views;
    use App\Models\HorseEvent;
    $isReport = $audience === 'report';
    $sample = $sample ?? false;
    $q = $isReport ? ['r' => $link->token] : [];
    $byCat = $events->groupBy('category');
    $shows = $byCat->get('competition', collect());
    $sales = $byCat->get('ownership', collect());
    $timeline = $events->reject(fn ($e) => in_array($e->category, ['competition', 'ownership'], true));
    $identityFacts = collect(['breed', 'sex', 'birth', 'color', 'height', 'markings'])->map(fn ($f) => $facts->get($f))->filter();
    $summary = \App\Support\Summary::parts($horse, $facts, $identifiers, $events, $pedigree, $documents);
@endphp
<x-layouts.site :title="$horse->displayName()" :robots="($isReport && ! $sample) || $preview ? 'noindex, nofollow' : null" :canonical="$sample ? route('sample') : ($isReport ? null : route('horse.public', $horse))"
    :description="'Horsefax record '.$horse->horsefax_id.' for '.$horse->displayName().'. '.collect([$horse->breed, $horse->birth_year ? 'foaled '.$horse->birth_year : null])->filter()->join(', ')">

    @if ($sample)
        <div class="bg-gold text-cream"><div class="wrap py-3 font-sans text-[13px] font-semibold tracking-[0.06em]">SAMPLE REPORT. Every name, number, date and result on this page is fictional, for illustration only.</div></div>
    @endif
    @if ($preview)
        <div class="bg-ink text-cream"><div class="wrap py-3 font-sans text-[13px]">Preview: this public summary is <strong>off</strong>. Only you and Horsefax staff can see this page. Turn it on in <a href="{{ route('sharing', $horse) }}" class="text-gold-soft underline">Sharing</a>.</div></div>
    @endif

    {{-- Printed / PDF copies: a plain header saying what this is, where it came from and when. --}}
    <div class="hidden print:flex items-center justify-between border-b border-line pb-3 mb-4">
        <img src="/img/wordmark.svg" alt="Horsefax" class="h-[22px] w-auto">
        <p class="font-sans text-[10px] text-muted text-right">{{ $isReport ? 'Horsefax report' : 'Horsefax record' }} {{ $horse->horsefax_id }} · printed {{ now()->format('M j, Y') }} from {{ $isReport ? 'a shared report link' : route('horse.public', $horse) }}<br>Entries are labeled by source; only items marked Horsefax Verified have been checked by Horsefax.</p>
    </div>
    <style>
        @media print {
            @page { size: letter; margin: 0.55in; }
            body { background: #fff !important; }
            .bg-forest, .bg-ink { background: #fff !important; color: #13201A !important; }
            .bg-forest *, .bg-ink * { color: #13201A !important; }
            li, tr, .card, figure { break-inside: avoid; }
            a[href^="http"]:not([href*="horsefax.com"])::after { content: " (" attr(href) ")"; font-size: 9px; color: #666; word-break: break-all; }
            iframe, video { display: none !important; }
        }
    </style>
    <script>window.addEventListener('beforeprint', () => document.querySelectorAll('details[data-print-open]').forEach(d => d.open = true));</script>

    <section class="bg-forest text-cream">
        <div class="wrap py-10 sm:py-14 grid sm:grid-cols-[180px_1fr] gap-8 items-start">
            <div class="w-[180px] h-[180px] bg-ink/40 rounded-[3px] overflow-hidden border border-cream/10">
                @if ($photo)<img src="{{ route('files.show', [$photo] + $q) }}" alt="{{ $horse->shortName() }}" class="w-full h-full object-cover">
                @else<img src="/img/hf-tile.svg" alt="" class="w-full h-full">@endif
            </div>
            <div>
                <p class="label text-gold-soft">{{ $isReport ? 'Horsefax report' : 'Horsefax public summary' }} · {{ $horse->horsefax_id }}</p>
                <h1 class="text-cream text-[38px] sm:text-[52px] leading-[1.05] mt-3">{{ $horse->registered_name ?: $horse->barn_name }}</h1>
                @if ($horse->registered_name && $horse->barn_name && strcasecmp($horse->registered_name, $horse->barn_name))
                    <p class="text-[20px] italic text-cream/75 mt-1">Barn name "{{ $horse->barn_name }}"</p>
                @endif
                <p class="font-sans text-[14px] text-cream/75 mt-4">{{ collect([$horse->breed, $horse->sex ? ucfirst($horse->sex) : null, $horse->birthLabel(), $horse->color, $horse->heightLabel()])->filter()->join(' · ') }}</p>
                <x-record-summary :parts="$summary" dark class="mt-4" />
                @if ($isReport && ! $sample)
                    <p class="font-sans text-[12px] text-cream/60 mt-5">Shared by the owner{{ $link->label ? ' for "'.$link->label.'"' : '' }}. Viewed {{ now()->format('M j, Y') }}.{{ $link->expires_at ? ' This link expires '.$link->expires_at->format('M j, Y').'.' : '' }} The owner can revoke it at any time.</p>
                @endif
            </div>
        </div>
    </section>

    {{-- At a glance (Kate's launch review): what the record holds, before the detail. Only what is on record. --}}
    @php
        $glance = collect([
            ['Show results', $shows->count() ?: null],
            ['Known earnings', ($e = $shows->sum('earnings_cents')) ? HorseEvent::money($e) : null],
            ['Sale records', $sales->filter(fn ($s) => $s->isPriceEntry() || $s->type === 'sale')->count() ?: null],
            ['Pedigree', ($gen = (int) $pedigree->max(fn ($p) => strlen($p->position))) ? $gen.' '.Str::plural('generation', $gen) : null],
            ['Photos & videos', $media->count() ?: null],
            ['Last updated', collect([$horse->updated_at, $events->max('updated_at'), $media->max('updated_at')])->filter()->max()?->format('M j, Y')],
        ])->filter(fn ($g) => $g[1] !== null);
    @endphp
    @if ($glance->count() > 1)
        <div class="bg-paper border-b border-line"><div class="wrap py-5">
            <dl class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
                @foreach ($glance as [$label, $value])
                    <div><dt class="label text-muted text-[10px]">{{ $label }}</dt><dd class="text-[22px] text-ink leading-tight mt-1">{{ $value }}</dd></div>
                @endforeach
            </dl>
        </div></div>
    @endif

    @if (! $isReport && ($horse->fromResults() || $horse->fromPedigree() || in_array($horse->source, ['published', 'ifce'], true)) && ! $horse->activeStewards()->exists())
        <div class="bg-paper border-b border-line"><div class="wrap py-4 font-sans text-[14px] flex flex-wrap items-center gap-x-6 gap-y-2">
            <span>{{ match ($horse->source) { 'results' => 'Horsefax built this record from published show results.', 'published' => 'Horsefax built this record from published listings (sale listings, stallion directories or foal lists).', 'ifce' => 'From the French national equine database. Source: '.\App\Harvest\IfceImporter::CREDIT.'.', default => 'This record was created because the horse appears in a pedigree on Horsefax.' } }} It has no owner yet, and nothing on it is verified. More than one horse can share a name.</span>
            <a href="{{ route('horses.claim', $horse) }}" class="text-gold-dark font-semibold">Is this your horse? Claim the record</a>
        </div></div>
    @endif

    <div class="wrap py-12 grid lg:grid-cols-[1fr_300px] gap-12">
        <div class="space-y-14 min-w-0">
            {{-- Two ways to read the same record (Kate's Life Timeline): by section, or in order of the horse's life. --}}
            @php $timelineView = request('view') === 'timeline'; @endphp
            <nav class="flex gap-1 border-b border-line -mt-4 print:hidden" aria-label="Record view">
                <a href="{{ request()->fullUrlWithQuery(['view' => null]) }}" @if (! $timelineView) aria-current="page" @endif class="no-underline font-sans text-[12px] font-semibold tracking-[0.14em] uppercase px-4 py-3 border-b-2 {{ $timelineView ? 'border-transparent text-muted hover:text-ink' : 'border-gold text-ink' }}">Standard report</a>
                <a href="{{ request()->fullUrlWithQuery(['view' => 'timeline']) }}" @if ($timelineView) aria-current="page" @endif class="no-underline font-sans text-[12px] font-semibold tracking-[0.14em] uppercase px-4 py-3 border-b-2 {{ $timelineView ? 'border-gold text-ink' : 'border-transparent text-muted hover:text-ink' }}">Life timeline</a>
                @php
                    $pdfUrl = $sample ? route('sample.pdf') : ($isReport ? route('report.pdf', $link->token) : route('horse.pdf', $horse));
                    $pdfUrl .= $timelineView ? '?view=timeline' : '';
                @endphp
                <a href="{{ $pdfUrl }}" class="ml-auto no-underline font-sans text-[12px] font-semibold tracking-[0.14em] uppercase px-4 py-3 text-gold-dark hover:text-ink" data-pdf-link>Download PDF</a>
                <button type="button" onclick="window.print()" class="font-sans text-[12px] font-semibold tracking-[0.14em] uppercase px-3 py-3 text-muted hover:text-ink">Print</button>
            </nav>
            @if ($timelineView)
                @include('public.partials.timeline', ['timeline' => \App\Support\Timeline::build($horse, $events, $media, $ownership, fn ($e) => Views::showAmount($e, $audience, $link))])
            @else

            {{-- Identity --}}
            <section aria-labelledby="h-identity">
                <h2 id="h-identity" class="text-[28px] border-b border-line pb-2">Identity</h2>
                <table class="table-hf mt-3">
                    <tbody>
                    @if ($horse->registered_name)<tr><td class="w-[34%] label text-muted text-[10px] align-middle">Registered name</td><td class="text-[18px]">{{ $horse->registered_name }}</td><td class="text-right">@if ($facts->get('registered_name'))<x-claim :item="$facts->get('registered_name')" compact class="justify-end" />@endif</td></tr>@endif
                    @forelse ($identityFacts as $f)
                        <tr><td class="w-[34%] label text-muted text-[10px] align-middle">{{ $f->label() }}</td><td class="text-[18px]">{{ $f->display() }}</td><td class="text-right"><x-claim :item="$f" compact class="justify-end" /></td></tr>
                    @empty
                        <tr><td colspan="3" class="hint">No identity details have been shared.</td></tr>
                    @endforelse
                    </tbody>
                </table>
                @if ($names->isNotEmpty())
                    <p class="mt-4 text-[16px]"><span class="label text-muted text-[10px]">Also known as</span><br>{{ $names->map(fn ($n) => $n->name.($n->kind === 'previous' ? ' (previous name)' : ''))->join(', ') }}</p>
                @endif
                @if ($identifiers->isNotEmpty())
                    <table class="table-hf mt-6">
                        <thead><tr><th>Identifier</th><th>Number</th><th>How it is known</th></tr></thead>
                        <tbody>
                        @foreach ($identifiers as $i)
                            <tr><td>{{ $i->label() }}</td><td class="font-sans text-[15px] tracking-wide">{{ $i->displayValue() }}</td><td><x-claim :item="$i" :conflict="$i->in_conflict" /></td></tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </section>

            {{-- Pedigree --}}
            @if ($pedigree->isNotEmpty() || $offspring->isNotEmpty())
                <section aria-labelledby="h-pedigree">
                    <h2 id="h-pedigree" class="text-[28px] border-b border-line pb-2">Pedigree</h2>
                    @if ($pedigree->isNotEmpty())
                        @include('partials.pedigree-chart', ['pedigree' => $pedigree, 'horse' => $horse])
                    @endif
                    @if ($offspring->isNotEmpty())
                        <h3 class="text-[21px] mt-8">Offspring on Horsefax</h3>
                        <ul class="mt-3 divide-y divide-line border-y border-line">
                            @foreach ($offspring as $o)
                                <li class="py-2.5 flex justify-between gap-4"><a href="{{ route('horse.public', $o) }}" class="text-ink">{{ $o->displayName() }}</a><span class="font-sans text-[13px] text-muted">{{ collect([$o->sex ? ucfirst($o->sex) : null, $o->birth_year])->filter()->join(' · ') }}</span></li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endif

            {{-- Show record (patterned on the EquiStat individual horse report) --}}
            @if ($shows->isNotEmpty())
                @php
                    $byYear = $shows->groupBy(fn ($e) => $e->occurred_on?->format('Y') ?? 'Date unknown')->sortKeysDesc();
                    $lifetime = $shows->sum('earnings_cents');
                    $byRider = $shows->filter(fn ($e) => $e->rider)->groupBy('rider')->map(fn ($g) => ['money' => $g->sum('earnings_cents'), 'types' => $g->pluck('discipline')->filter()->unique()->join(', '), 'n' => $g->count()])->sortByDesc('money');
                    $sireLine = $pedigree->get('s'); $damLine = $pedigree->get('d'); $mgs = $pedigree->get('ds');
                @endphp
                <section aria-labelledby="h-shows">
                    <h2 id="h-shows" class="text-[28px] border-b border-line pb-2">Show record</h2>
                    @if ($sireLine || $damLine)
                        <p class="font-sans text-[13px] text-muted mt-3">{{ collect([$sireLine ? 'Sire: '.$sireLine->name : null, $damLine ? 'Dam: '.$damLine->name : null, $mgs ? 'Maternal grandsire: '.$mgs->name : null])->filter()->join(' · ') }}</p>
                    @endif
                    <div class="mt-4 grid grid-cols-3 gap-px bg-line border border-line text-center">
                        <div class="bg-paper p-4"><p class="label text-muted text-[10px]">Lifetime earnings</p><p class="text-[26px] text-ink mt-1">{{ HorseEvent::money($lifetime) ?? '$0' }}</p></div>
                        <div class="bg-paper p-4"><p class="label text-muted text-[10px]">Results</p><p class="text-[26px] text-ink mt-1">{{ $shows->count() }}</p></div>
                        <div class="bg-paper p-4"><p class="label text-muted text-[10px]">Seasons</p><p class="text-[26px] text-ink mt-1">{{ $byYear->keys()->filter(fn ($y) => is_numeric($y))->count() }}</p></div>
                    </div>
                    @foreach ($byYear as $year => $rows)
                        <h3 class="text-[20px] mt-8">{{ $year }}</h3>
                        <div class="overflow-x-auto">
                            <table class="table-hf mt-2 min-w-[720px] text-[15px] [&_td]:pr-3 [&_th]:pr-3">
                                <thead><tr><th>Date</th><th>Show</th><th>Event / class</th><th>Exhibitor</th><th>Round</th><th>Score</th><th>Place</th><th class="text-right">Money</th><th>Source</th></tr></thead>
                                <tbody>
                                @foreach ($rows as $e)
                                    <tr>
                                        <td class="whitespace-nowrap">{{ $e->occurred_on?->format('m/y') ?? '—' }}</td>
                                        <td class="min-w-[150px]">{{ $e->event_name ?: $e->title }}@if ($e->organization)<br><span class="font-sans text-[11px] text-muted">{{ $e->organization }}</span>@endif</td>
                                        <td>{{ $e->class_name ?: collect([$e->discipline, $e->division])->filter()->join(' · ') }}</td>
                                        <td>{{ $e->rider }}</td>
                                        <td>{{ $e->round }}</td>
                                        <td>{{ collect([$e->score, $e->points !== null ? rtrim(rtrim((string) $e->points, '0'), '.').' pts' : null])->filter()->join(' · ') }}</td>
                                        <td>{{ $e->placing }}</td>
                                        <td class="text-right whitespace-nowrap">{{ HorseEvent::money($e->earnings_cents) }}</td>
                                        <td><x-claim :item="$e" compact /></td>
                                    </tr>
                                @endforeach
                                <tr><td colspan="7" class="font-sans text-[12px] font-semibold tracking-[0.08em] uppercase text-muted">Total for {{ $year }}</td><td class="text-right font-medium">{{ HorseEvent::money($rows->sum('earnings_cents')) ?? '$0' }}</td><td></td></tr>
                                </tbody>
                            </table>
                        </div>
                    @endforeach
                    @if ($byRider->isNotEmpty())
                        <h3 class="text-[20px] mt-8">Summary by exhibitor</h3>
                        <table class="table-hf mt-2 text-[15px]">
                            <thead><tr><th>Exhibitor</th><th>Results</th><th>Event types</th><th class="text-right">Money</th></tr></thead>
                            <tbody>@foreach ($byRider as $name => $r)<tr><td>{{ $name }}</td><td>{{ $r['n'] }}</td><td>{{ $r['types'] }}</td><td class="text-right">{{ HorseEvent::money($r['money']) ?? '$0' }}</td></tr>@endforeach</tbody>
                        </table>
                    @endif
                    <p class="hint mt-3">Results entered by an owner are labeled owner reported until Horsefax checks them against the association's official records. Owners at the time of each show are not listed.</p>
                </section>
            @endif

            {{-- Health, care, training and other history --}}
            @if ($timeline->isNotEmpty())
                <section aria-labelledby="h-history">
                    <h2 id="h-history" class="text-[28px] border-b border-line pb-2">History</h2>
                    <ol class="mt-4 space-y-0">
                        @foreach ($timeline as $e)
                            <li class="grid sm:grid-cols-[130px_1fr] gap-x-6 gap-y-1 py-4 border-b border-line">
                                <p class="font-sans text-[13px] text-muted pt-1">{{ $e->dateLabel() }}</p>
                                <div>
                                    <p class="label text-gold-dark text-[10px]">{{ $e->categoryLabel() }}{{ $e->typeLabel() ? ' · '.$e->typeLabel() : '' }}</p>
                                    <p class="text-[19px] text-ink mt-1">{{ $e->title }}@if ($e->result) <span class="text-muted">· {{ $e->result }}</span>@endif</p>
                                    @if ($e->provider)<p class="font-sans text-[13px] text-muted mt-0.5">{{ $e->provider }}</p>@endif
                                    @if ($e->expires_on)<p class="font-sans text-[13px] text-muted mt-0.5">{{ $e->expires_on->isPast() ? 'Expired' : 'Valid until' }} {{ $e->expires_on->format('M j, Y') }}</p>@endif
                                    @if ($e->details)<p class="text-[16px] mt-2 whitespace-pre-line">{{ $e->details }}</p>@endif
                                    <div class="mt-2"><x-claim :item="$e" /></div>
                                    @include('partials.claim-dates', ['c' => $e])
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </section>
            @endif

            {{-- Ownership and sale history --}}
            @if ($ownership->isNotEmpty() || $sales->isNotEmpty())
                <section aria-labelledby="h-owners">
                    <h2 id="h-owners" class="text-[28px] border-b border-line pb-2">Ownership & sale history</h2>
                    <p class="hint mt-2">Horsefax shows when ownership changed, not who the owners were.@if ($sales->contains(fn ($e) => $e->isPriceEntry())) Auction results are shown as the sale published them; a reported sale means the auction reported it, not that Horsefax confirmed payment or transfer. {{ HorseEvent::PRICE_NOTE }}@endif</p>
                    <ul class="mt-4 divide-y divide-line border-y border-line">
                        @foreach ($sales->sortBy('occurred_on') as $e)
                            <li class="py-3 flex flex-wrap justify-between gap-3">
                                @if ($e->isPriceEntry())
                                    <div><p class="text-[18px]">{{ $e->priceLine(Views::showAmount($e, $audience, $link)) }}</p>
                                        <p class="font-sans text-[13px] text-muted mt-0.5">{{ $e->sale_venue }}@if ($e->sourceUrl()) · <a href="{{ $e->sourceUrl() }}" class="text-gold-dark" rel="nofollow noopener" target="_blank">Source</a>@endif</p></div>
                                @else
                                <div><p class="text-[18px]">{{ $e->source_type === 'historical' ? $e->title : ($e->typeLabel() ?? $e->title) }}@if ($e->sale_venue && ! str_contains($e->title, $e->sale_venue)) <span class="text-muted">· {{ $e->sale_venue }}</span>@endif @if ($e->sourceUrl())<a href="{{ $e->sourceUrl() }}" class="font-sans text-[13px] text-gold-dark" target="_blank" rel="noopener">See the listing</a>@endif</p>
                                    @if (Views::showAmount($e, $audience, $link))<p class="font-sans text-[14px] mt-0.5">{{ HorseEvent::money($e->amount_cents) }}</p>@endif</div>
                                @endif
                                <div class="text-right"><p class="font-sans text-[13px] text-muted">{{ $e->dateLabel() }}</p><x-claim :item="$e" class="mt-1 justify-end" /></div>
                            </li>
                        @endforeach
                        @foreach ($ownership as $s)
                            <li class="py-3 flex justify-between gap-3">
                                <p class="text-[17px]">{{ \App\Support\Roles::stewardLabel($s) }}</p>
                                <p class="font-sans text-[13px] text-muted">{{ $s->started_on?->format('M Y') ?? $s->created_at->format('M Y') }} – {{ $s->ended_at?->format('M Y') ?? 'present' }}</p>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            {{-- Media --}}
            @if ($media->isNotEmpty())
                <section aria-labelledby="h-media">
                    <h2 id="h-media" class="text-[28px] border-b border-line pb-2">Photos & videos</h2>
                    <div class="mt-4 grid sm:grid-cols-2 gap-4">
                        @foreach ($media as $m)
                            <figure class="card overflow-hidden">
                                @if ($m->embedUrl())
                                    <div class="aspect-video"><iframe src="{{ $m->embedUrl() }}" title="{{ $m->caption ?: 'Video' }}" class="w-full h-full" loading="lazy" allow="encrypted-media; picture-in-picture" allowfullscreen></iframe></div>
                                @elseif ($m->kind === 'video' && $m->document)
                                    <video controls preload="metadata" class="w-full aspect-video bg-ink"><source src="{{ route('files.show', [$m->document] + $q) }}" type="{{ $m->document->mime }}"></video>
                                @elseif ($m->document)
                                    <img src="{{ route('files.show', [$m->document] + $q) }}" alt="{{ $m->caption }}" class="w-full aspect-[4/3] object-cover" loading="lazy">
                                @endif
                                @if ($m->caption || $m->taken_on)<figcaption class="p-3 text-[15px]">{{ $m->caption }} <span class="font-sans text-[12px] text-muted">{{ $m->taken_on?->format('M Y') }}</span></figcaption>@endif
                            </figure>
                        @endforeach
                    </div>
                </section>
            @endif

            {{-- Documents --}}
            @if ($documents->isNotEmpty())
                <section aria-labelledby="h-docs">
                    <h2 id="h-docs" class="text-[28px] border-b border-line pb-2">Documents on file</h2>
                    <p class="hint mt-2">The owner keeps these documents with Horsefax. This list shows what exists; the files themselves are not shared here.</p>
                    <table class="table-hf mt-4">
                        <thead><tr><th>Document</th><th>Dated</th><th>How it is known</th></tr></thead>
                        <tbody>
                        @foreach ($documents as $d)
                            <tr><td>{{ $d->title }}<br><span class="font-sans text-[12px] text-muted">{{ $d->kindLabel() }}{{ $d->issuer ? ' · '.$d->issuer : '' }}</span></td><td class="whitespace-nowrap">{{ $d->issued_on?->format('M j, Y') ?? '—' }}</td><td><x-claim :item="$d" /></td></tr>
                        @endforeach
                        </tbody>
                    </table>
                </section>
            @endif

            @endif

            <section class="card p-6 bg-cream print:break-inside-avoid">
                <h2 class="text-[22px]">What this {{ $isReport ? 'report' : 'record' }} does not tell you</h2>
                <p class="mt-2 text-[16px] leading-relaxed">It shows what has been published about this horse, each entry labeled with its source, and what its owner has chosen to share. A missing record is not proof that something did not happen, and Horsefax does not certify a horse as sound, safe or suitable. Before buying, arrange your own pre-purchase exam and check registration details with the registry.</p>
            </section>
        </div>

        <aside class="space-y-6">
            <div class="card p-5">
                <p class="label text-muted text-[10px]">Horsefax ID</p>
                <p class="font-sans text-[20px] tracking-[0.08em] text-ink mt-1">{{ $horse->horsefax_id }}</p>
                <p class="hint">A permanent ID for this horse. It is never reused.</p>
                @unless ($isReport || $sample)
                    <button type="button" class="btn-ghost btn-small mt-3" data-copy="{{ route('horse.public', $horse) }}">Copy link</button>
                @endunless
            </div>
            <div class="card p-5">
                <p class="label text-muted text-[10px] mb-3">Labels used</p>
                <div class="flex flex-wrap gap-2"><span class="chip-self">Owner reported</span><span class="chip border-[#3b5b7a]/40 text-[#2c4a66] bg-[#3b5b7a]/[0.06]">Professional attested</span><span class="chip border-ink/40 text-ink bg-ink/[0.05]">Official source</span><span class="chip-verified">Horsefax Verified</span><span class="chip-disputed">Disputed</span></div>
                <p class="hint text-[13px] mt-2">Source and verification are separate: click a Horsefax Verified badge to see how and what it covers.</p>
                <a href="{{ route('how-it-works') }}#verification" class="block font-sans text-[12px] mt-3 text-gold-dark">What the labels mean</a>
            </div>
            @unless ($sample)
            <details class="card p-5" @if ($errors->any()) open @endif>
                <summary class="cursor-pointer text-[18px] text-ink">Something wrong? Request a correction</summary>
                <form method="POST" action="{{ route('corrections.store', $horse) }}" class="mt-4 space-y-3">
                    @csrf
                    <input type="text" name="website" class="hidden" tabindex="-1" autocomplete="off">
                    @guest
                        <x-input name="name" label="Your name" required />
                        <x-input name="email" type="email" label="Your email" required />
                    @endguest
                    <div>
                        <label class="field-label" for="c-subject">About</label>
                        <select name="subject" id="c-subject" class="field">
                            <option value="">The record in general</option>
                            @foreach ($identifiers as $i)<option value="identifier:{{ $i->id }}">{{ $i->label() }}</option>@endforeach
                            @foreach ($events as $e)<option value="event:{{ $e->id }}">{{ \Illuminate\Support\Str::limit($e->dateLabel().' · '.$e->title, 60) }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="field-label" for="c-statement">What is wrong, and how do you know?</label>
                        <textarea name="statement" id="c-statement" rows="4" class="field" required minlength="10">{{ old('statement') }}</textarea>
                        @error('statement')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <button class="btn-forest btn-small">Send to Horsefax</button>
                    <p class="hint text-[13px]">Staff review every request. See <a href="{{ route('record-corrections') }}" class="text-gold-dark">how corrections work</a>.</p>
                </form>
            </details>
            @else
            <div class="card p-5"><p class="text-[17px]">Ready to start your horse's record?</p><a href="{{ route('horses.create') }}" class="btn-gold btn-small mt-3">Create a Horse Record</a></div>
            @endunless
        </aside>
    </div>
</x-layouts.site>
