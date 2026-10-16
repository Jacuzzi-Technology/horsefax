@php
    use App\Support\Record;
    use App\Support\HorseSearch;
    $narrowing = $started && $count > HorseSearch::MAX_LIST;
    $showMore = $narrowing || collect($c)->except('name')->isNotEmpty();
    $createQuery = array_filter([
        'registered_name' => $c['name'] ?? null, 'registry' => $c['registry'] ?? null, 'registration_number' => $c['registration_number'] ?? null,
        'microchip' => $c['microchip'] ?? null, 'birth_year' => $c['foal_year'] ?? null, 'sex' => $c['sex'] ?? null, 'color' => $c['color'] ?? null, 'breed' => $c['breed'] ?? null,
    ]);
@endphp
<x-layouts.site title="Search horses" :robots="$started ? 'noindex, follow' : null">
    <section class="bg-forest text-cream">
        <div class="wrap py-12">
            <p class="label text-gold-soft">Search Horsefax</p>
            <h1 class="text-cream text-[36px] sm:text-[46px] mt-3">Find a horse</h1>
            <form action="{{ route('search') }}" method="GET" class="mt-6 max-w-[900px] bg-cream/95 text-charcoal p-4 sm:p-5 rounded-[3px]" role="search">
                <div class="flex flex-col sm:flex-row gap-2">
                    <div class="flex-1">
                        <label for="s-name" class="field-label">Registered name or barn name</label>
                        <input id="s-name" name="name" value="{{ $c['name'] ?? '' }}" type="search" class="field" placeholder="e.g. Juniper Creek Lark">
                    </div>
                    <button class="btn-gold sm:self-end">Search</button>
                </div>

                <details class="mt-4" @if ($showMore) open @endif>
                    <summary class="cursor-pointer font-sans text-[12px] font-semibold tracking-[0.14em] uppercase text-gold-dark">Add more details to narrow the search</summary>
                    <div class="mt-4 grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        <div>
                            <label class="field-label" for="s-registry">Registry</label>
                            <select id="s-registry" name="registry" class="field">
                                <option value="">Any</option>
                                @foreach (Record::REGISTRIES as $k => $v)<option value="{{ $k }}" @selected(($c['registry'] ?? '') === $k)>{{ $v }}</option>@endforeach
                            </select>
                        </div>
                        <div><label class="field-label" for="s-reg">Registration number</label><input id="s-reg" name="registration_number" value="{{ $c['registration_number'] ?? '' }}" class="field"></div>
                        <div><label class="field-label" for="s-chip">Microchip number</label><input id="s-chip" name="microchip" value="{{ $c['microchip'] ?? '' }}" class="field" inputmode="numeric"></div>
                        <div><label class="field-label" for="s-year">Foaling year</label><input id="s-year" name="foal_year" value="{{ $c['foal_year'] ?? '' }}" class="field" inputmode="numeric" maxlength="4"></div>
                        <div>
                            <label class="field-label" for="s-sex">Sex</label>
                            <select id="s-sex" name="sex" class="field"><option value="">Any</option>@foreach (Record::SEXES as $k => $v)<option value="{{ $k }}" @selected(($c['sex'] ?? '') === $k)>{{ $v }}</option>@endforeach</select>
                        </div>
                        <div><label class="field-label" for="s-color">Color</label><select id="s-color" name="color" class="field"><option value="">Any</option>@foreach (Record::COLORS as $o)<option value="{{ $o }}" @selected(($c['color'] ?? '') === $o)>{{ $o }}</option>@endforeach</select></div>
                        <div><label class="field-label" for="s-breed">Breed</label><select id="s-breed" name="breed" class="field"><option value="">Any</option>@foreach (Record::BREEDS as $o)<option value="{{ $o }}" @selected(($c['breed'] ?? '') === $o)>{{ $o }}</option>@endforeach</select></div>
                        <div><label class="field-label" for="s-sire">Sire</label><input id="s-sire" name="sire" value="{{ $c['sire'] ?? '' }}" class="field"></div>
                        <div><label class="field-label" for="s-dam">Dam</label><input id="s-dam" name="dam" value="{{ $c['dam'] ?? '' }}" class="field"></div>
                    </div>
                    <p class="hint">Registration and microchip numbers must match exactly. They are never shown in results.</p>
                </details>
            </form>
        </div>
    </section>

    <div class="wrap py-10">
        @if (! $started)
            <p class="text-[18px] text-muted">Start with the horse's registered name or barn name.</p>
        @elseif ($narrowing)
            <div class="card p-7 max-w-[720px]">
                <p class="label text-gold">{{ number_format($count) }} horses match</p>
                <h2 class="text-[26px] mt-2">Add more details to narrow it down</h2>
                <p class="mt-2 text-[17px]">We show the list once {{ HorseSearch::MAX_LIST }} or fewer horses match. A registration or microchip number usually finds the horse straight away; foaling year, sex, color, sire or dam also help.</p>
            </div>
        @elseif ($results->isEmpty())
            <div class="card p-8 max-w-[680px]">
                <h2 class="text-[26px]">No horse on Horsefax matches</h2>
                <p class="mt-3 text-[17px]">The horse may not be on Horsefax yet, or its owner has chosen not to list it. You can start its record now. It begins as <strong>identity unverified</strong> until it has been cross-checked.</p>
                <a href="{{ route('horses.create', $createQuery) }}" class="btn-gold mt-6">Add this horse</a>
            </div>
        @else
            <p class="font-sans text-[13px] text-muted mb-4">{{ $results->count() }} {{ Str::plural('horse', $results->count()) }} {{ $results->count() === 1 ? 'matches' : 'match' }}. Select the right one, or add a new record if none of them is your horse.</p>
            <div class="grid md:grid-cols-2 gap-4">
                @foreach ($results as $h)
                    @php
                        $photo = $h->public_summary ? \App\Support\Views::photo($h, 'public') : null;
                        $sire = $h->pedigree->firstWhere('position', 's'); $dam = $h->pedigree->firstWhere('position', 'd');
                    @endphp
                    <a href="{{ route('horse.public', $h) }}" class="card flex gap-4 p-4 no-underline hover:border-gold/60 transition-colors">
                        <div class="w-20 h-20 shrink-0 bg-forest rounded-[2px] overflow-hidden">
                            @if ($photo)<img src="{{ route('files.show', $photo) }}" alt="" class="w-full h-full object-cover">@else<img src="/img/hf-tile.svg" alt="" class="w-full h-full">@endif
                        </div>
                        <div class="min-w-0">
                            <p class="text-[20px] text-ink leading-tight">{{ $h->displayName() }}</p>
                            <p class="font-sans text-[13px] text-muted mt-1">{{ collect([$h->breed, $h->sex ? ucfirst($h->sex) : null, $h->birth_year ? 'Foaled '.$h->birth_year : null, $h->color])->filter()->join(' · ') ?: 'Details not added' }}</p>
                            @if (($sire && $sire->visibility !== 'private') || ($dam && $dam->visibility !== 'private'))
                                <p class="font-sans text-[12px] text-muted mt-0.5">{{ collect([$sire && $sire->visibility !== 'private' ? 'By '.$sire->name : null, $dam && $dam->visibility !== 'private' ? 'out of '.$dam->name : null])->filter()->join(' ') }}</p>
                            @endif
                            <p class="mt-2 flex flex-wrap items-center gap-2"><span class="font-sans text-[11px] tracking-[0.12em] text-gold-dark">{{ $h->horsefax_id }}</span>
                                <span class="{{ $h->identity_status === 'verified' ? 'chip-verified' : ($h->identity_status === 'under_review' ? 'chip-disputed' : 'chip-self') }}">{{ $h->identityLabel() }}</span></p>
                        </div>
                    </a>
                @endforeach
            </div>
            <div class="mt-8 card p-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4 max-w-[860px]">
                <p class="text-[18px]">None of these is the horse you're looking for?</p>
                <a href="{{ route('horses.create', $createQuery) }}" class="btn-forest">Add a new record</a>
            </div>
        @endif
    </div>
</x-layouts.site>
