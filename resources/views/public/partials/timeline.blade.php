{{-- Life Timeline: the report's own entries by year (App\Support\Timeline). Filters work without reloading; with no
     JavaScript everything is simply shown. --}}
@php use App\Models\HorseEvent; @endphp
<section aria-labelledby="h-timeline" data-timeline>
    <h2 id="h-timeline" class="text-[28px] border-b border-line pb-2">Life timeline</h2>
    <p class="hint mt-2">The entries on this record in order, newest first. A date shown as a year alone means only the year is known. Undated entries are listed at the end.</p>
    @if (count($timeline['categories']) > 1)
        <div class="mt-4 flex flex-wrap gap-2 print:hidden" role="group" aria-label="Show categories">
            @foreach ($timeline['categories'] as $key => $label)
                <button type="button" data-cat-toggle="{{ $key }}" aria-pressed="true" class="chip border-forest/30 text-forest bg-forest/[0.06] aria-[pressed=false]:opacity-45 aria-[pressed=false]:line-through">{{ $label }}</button>
            @endforeach
        </div>
    @endif

    @forelse ($timeline['years'] as $year => $items)
        <div class="mt-8 grid grid-cols-[64px_1fr] sm:grid-cols-[88px_1fr] gap-4" data-year>
            <p class="text-[26px] text-gold-dark leading-none pt-1">{{ $year }}</p>
            <ol class="border-l border-line pl-5 space-y-4">
                @foreach ($items as $it)
                    <li data-cat="{{ $it['category'] }}" class="relative">
                        <span class="absolute -left-[25px] top-2 w-2 h-2 rotate-45 {{ $it['kind'] === 'milestone' ? 'bg-gold' : 'bg-forest/50' }}"></span>
                        <p class="label text-[10px] text-muted">{{ \App\Support\Timeline::CATEGORIES[$it['category']] ?? '' }} · {{ $it['when'] }}@if (! empty($it['badge'])) · <span class="text-gold-dark">{{ $it['badge'] }}</span>@endif</p>
                        @if ($it['kind'] === 'career')
                            <details class="mt-1" data-print-open>
                                <summary class="cursor-pointer text-[18px] text-ink">{{ $it['title'] }}@if ($it['earnings']) · {{ HorseEvent::money($it['earnings']) }} earnings @endif @if ($it['best']) · best placing {{ $it['best'] }}@endif</summary>
                                @if ($it['organizations'])<p class="font-sans text-[13px] text-muted mt-1">{{ implode(', ', $it['organizations']) }}</p>@endif
                                <ul class="mt-2 space-y-1 font-sans text-[14px]">
                                    @foreach ($it['entries'] as $e)
                                        <li>{{ $e['title'] }}@if ($e['detail']) <span class="text-muted">· {{ $e['detail'] }}</span>@endif @if ($e['source'])<a href="{{ $e['source'] }}" class="text-gold-dark" target="_blank" rel="noopener nofollow">source</a>@endif</li>
                                    @endforeach
                                </ul>
                            </details>
                        @else
                            <p class="text-[18px] text-ink mt-0.5">{{ $it['title'] }}@if (! empty($it['count']) && $it['kind'] !== 'media') ({{ $it['count'] }})@endif</p>
                            @if (! empty($it['detail']))<p class="font-sans text-[13px] text-muted">{{ $it['detail'] }}</p>@endif
                            <div class="flex flex-wrap items-center gap-3 mt-1">
                                @if (! empty($it['event']))<x-claim :item="$it['event']" />@endif
                                @if (! empty($it['source']))<a href="{{ $it['source'] }}" class="font-sans text-[13px] text-gold-dark" target="_blank" rel="noopener nofollow">Source</a>@endif
                                @if ($it['kind'] === 'media')<a href="{{ request()->fullUrlWithQuery(['view' => null]) }}#h-media" class="font-sans text-[13px] text-gold-dark">See them on the report</a>@endif
                            </div>
                        @endif
                    </li>
                @endforeach
            </ol>
        </div>
    @empty
        @if (! $timeline['undated'])<p class="hint mt-6">Nothing dated on this record yet.</p>@endif
    @endforelse

    @if ($timeline['undated'])
        <div class="mt-8 grid grid-cols-[64px_1fr] sm:grid-cols-[88px_1fr] gap-4" data-year>
            <p class="text-[16px] text-muted leading-tight pt-1">Undated</p>
            <ol class="border-l border-line pl-5 space-y-3">
                @foreach ($timeline['undated'] as $it)
                    <li data-cat="{{ $it['category'] }}"><p class="text-[17px]">{{ $it['title'] }}</p>@if (! empty($it['detail']))<p class="font-sans text-[13px] text-muted">{{ $it['detail'] }}</p>@endif</li>
                @endforeach
            </ol>
        </div>
    @endif
</section>
<script>
    (() => {
        const root = document.querySelector('[data-timeline]');
        if (!root) return;
        const hidden = new Set();
        root.querySelectorAll('[data-cat-toggle]').forEach(b => b.addEventListener('click', () => {
            const c = b.dataset.catToggle;
            hidden.has(c) ? hidden.delete(c) : hidden.add(c);
            b.setAttribute('aria-pressed', hidden.has(c) ? 'false' : 'true');
            root.querySelectorAll('[data-cat]').forEach(li => li.hidden = hidden.has(li.dataset.cat));
            root.querySelectorAll('[data-year]').forEach(y => y.hidden = ![...y.querySelectorAll('[data-cat]')].some(li => !li.hidden));
        }));
    })();
</script>
