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
    $facts = array_filter([
        'Breed' => $horse->breed,
        'Sex' => $horse->sex ? ucfirst($horse->sex) : null,
        'Foaled' => $horse->birthLabel(),
        'Color' => $horse->color,
        'Height' => $horse->heightLabel() ? $horse->heightLabel().($horse->height_measured_on ? ' (measured '.$horse->height_measured_on->format('M Y').')' : '') : null,
        'Markings' => $horse->markings,
    ]);
@endphp
<x-layouts.site :title="$horse->displayName()" :robots="($isReport && ! $sample) || $preview ? 'noindex, nofollow' : null" :canonical="$sample ? route('sample') : ($isReport ? null : route('horse.public', $horse))"
    :description="'Horsefax record '.$horse->horsefax_id.' for '.$horse->displayName().'. '.collect([$horse->breed, $horse->birth_year ? 'foaled '.$horse->birth_year : null])->filter()->join(', ')">

    @if ($sample)
        <div class="bg-gold text-cream"><div class="wrap py-3 font-sans text-[13px] font-semibold tracking-[0.06em]">SAMPLE REPORT. Every name, number, date and result on this page is fictional, for illustration only.</div></div>
    @endif
    @if ($preview)
        <div class="bg-ink text-cream"><div class="wrap py-3 font-sans text-[13px]">Preview: this public summary is <strong>off</strong>. Only you and Horsefax staff can see this page. Turn it on in <a href="{{ route('sharing', $horse) }}" class="text-gold-soft underline">Sharing</a>.</div></div>
    @endif

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
                <p class="mt-4"><span class="{{ $horse->identity_status === 'verified' ? 'chip-verified' : ($horse->identity_status === 'under_review' ? 'chip-disputed' : 'chip-self') }}">{{ $horse->identityLabel() }}</span></p>
                @if ($isReport && ! $sample)
                    <p class="font-sans text-[12px] text-cream/60 mt-5">Shared by the owner{{ $link->label ? ' for "'.$link->label.'"' : '' }}. Viewed {{ now()->format('M j, Y') }}.{{ $link->expires_at ? ' This link expires '.$link->expires_at->format('M j, Y').'.' : '' }} The owner can revoke it at any time.</p>
                @endif
            </div>
        </div>
    </section>

    <div class="wrap py-12 grid lg:grid-cols-[1fr_300px] gap-12">
        <div class="space-y-14 min-w-0">

            {{-- Identity --}}
            <section aria-labelledby="h-identity">
                <h2 id="h-identity" class="text-[28px] border-b border-line pb-2">Identity</h2>
                <dl class="mt-4 grid sm:grid-cols-2 gap-x-8">
                    @forelse ($facts as $k => $v)
                        <div class="py-2.5 border-b border-line"><dt class="label text-muted text-[10px]">{{ $k }}</dt><dd class="text-[18px] mt-0.5">{{ $v }}</dd></div>
                    @empty
                        <p class="hint">No identity details have been shared.</p>
                    @endforelse
                </dl>
                @if ($names->isNotEmpty())
                    <p class="mt-4 text-[16px]"><span class="label text-muted text-[10px]">Also known as</span><br>{{ $names->map(fn ($n) => $n->name.($n->kind === 'previous' ? ' (previous name)' : ''))->join(', ') }}</p>
                @endif
                @if ($identifiers->isNotEmpty())
                    <table class="table-hf mt-6">
                        <thead><tr><th>Identifier</th><th>Number</th><th>How it is known</th></tr></thead>
                        <tbody>
                        @foreach ($identifiers as $i)
                            <tr><td>{{ $i->label() }}</td><td class="font-sans text-[15px] tracking-wide">{{ $i->displayValue() }}</td><td><x-status :status="$i->in_conflict ? 'disputed' : $i->status" /></td></tr>
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
                                        <td><x-status :status="$e->status" /></td>
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
                    <p class="hint mt-3">Results entered by an owner are labeled self-reported until checked against the association's official records. Owners at the time of each show are not listed.</p>
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
                                    <div class="mt-2"><x-status :status="$e->status" /></div>
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
                    <p class="hint mt-2">Horsefax shows when ownership changed, not who the owners were.</p>
                    <ul class="mt-4 divide-y divide-line border-y border-line">
                        @foreach ($sales as $e)
                            <li class="py-3 flex flex-wrap justify-between gap-3">
                                <div><p class="text-[18px]">{{ $e->typeLabel() ?? $e->title }}@if ($e->sale_venue) <span class="text-muted">· {{ $e->sale_venue }}</span>@endif</p>
                                    @if (Views::showAmount($e, $audience, $link))<p class="font-sans text-[14px] mt-0.5">{{ HorseEvent::money($e->amount_cents) }}</p>@endif</div>
                                <div class="text-right"><p class="font-sans text-[13px] text-muted">{{ $e->dateLabel() }}</p><x-status :status="$e->status" class="mt-1" /></div>
                            </li>
                        @endforeach
                        @foreach ($ownership as $s)
                            <li class="py-3 flex justify-between gap-3">
                                <p class="text-[17px]">{{ ucfirst($s->role) }} on Horsefax{{ $s->status === 'verified' ? ' (ownership verified by Horsefax)' : '' }}</p>
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
                            <tr><td>{{ $d->title }}<br><span class="font-sans text-[12px] text-muted">{{ $d->kindLabel() }}{{ $d->issuer ? ' · '.$d->issuer : '' }}</span></td><td class="whitespace-nowrap">{{ $d->issued_on?->format('M j, Y') ?? '—' }}</td><td><x-status :status="$d->status" /></td></tr>
                        @endforeach
                        </tbody>
                    </table>
                </section>
            @endif

            <section class="card p-6 bg-cream">
                <h2 class="text-[22px]">What this {{ $isReport ? 'report' : 'summary' }} does not tell you</h2>
                <p class="mt-2 text-[16px] leading-relaxed">It shows only what the owner has chosen to share. A missing record is not proof that something did not happen, and Horsefax does not certify a horse as sound, safe or suitable. Before buying, arrange your own pre-purchase exam and check registration details with the registry.</p>
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
                <div class="flex flex-wrap gap-2"><span class="chip-self">Self-reported</span><span class="chip-doc">Document supplied</span><span class="chip-verified">Independently verified</span><span class="chip-disputed">Disputed</span></div>
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
