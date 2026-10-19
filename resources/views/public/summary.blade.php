{{-- Signed-out visitors (Richard, Oct 18 2026): a horse's basic summary, and a free account to see the full record. --}}
@php
    $age = $horse->birth_year && $horse->birth_precision !== 'unknown' ? (int) now()->format('Y') - (int) $horse->birth_year : null; // horses age on Jan 1
    $basics = collect([
        'Age' => $age !== null ? ($age < 1 ? 'Foaled '.$horse->birth_year : $age.' '.Str::plural('year', $age).' old (foaled '.$horse->birth_year.')') : null,
        'Color' => $horse->color,
        'Size' => $horse->height_hands ? rtrim(rtrim(number_format((float) $horse->height_hands, 1), '0'), '.').' hands' : null,
        'Sex' => $horse->sex ? ucfirst($horse->sex) : null,
    ])->filter();
    $teaser = collect([
        $counts['results'] ? $counts['results'].' '.Str::plural('show result', $counts['results']) : null,
        $counts['sales'] ? $counts['sales'].' '.Str::plural('sale record', $counts['sales']) : null,
        $counts['pedigree'] ? 'pedigree' : null,
        $counts['photos'] ? $counts['photos'].' '.Str::plural('photo', $counts['photos']) : null,
        $counts['videos'] ? $counts['videos'].' '.Str::plural('video', $counts['videos']) : null,
    ])->filter();
@endphp
<x-layouts.site :title="$horse->displayName()" :canonical="route('horse.public', $horse)"
    :description="'Horsefax record '.$horse->horsefax_id.' for '.$horse->displayName().'. '.$basics->map(fn ($v, $k) => $k.': '.$v)->join(', ')">
    <section class="bg-forest text-cream">
        <div class="wrap py-12">
            <p class="label text-gold-soft">Horsefax record · {{ $horse->horsefax_id }}</p>
            <h1 class="text-cream text-[38px] sm:text-[50px] mt-3">{{ $horse->displayName() }}</h1>
            <p class="mt-4"><span class="{{ $horse->identity_status === 'verified' ? 'chip-verified' : ($horse->identity_status === 'under_review' ? 'chip-disputed' : 'chip-self') }}">{{ $horse->identityLabel() }}</span></p>
        </div>
    </section>
    <div class="wrap py-12 grid md:grid-cols-2 gap-6 max-w-[960px]">
        <div class="card p-6">
            <h2 class="text-[24px]">Summary</h2>
            @if ($basics->isNotEmpty())
                <dl class="mt-4 space-y-3">
                    @foreach ($basics as $label => $value)
                        <div class="flex justify-between gap-4 border-b border-line pb-2"><dt class="label text-[11px] text-muted">{{ $label }}</dt><dd class="text-[17px] text-right">{{ $value }}</dd></div>
                    @endforeach
                </dl>
            @else
                <p class="mt-2 text-[17px]">No basic details on this record yet.</p>
            @endif
        </div>
        <div class="card p-6">
            <h2 class="text-[24px]">See the full record</h2>
            <p class="mt-2 text-[17px]">Create a free account to see this horse's full Horsefax record: pedigree, show results, sale history, photos and videos.</p>
            @if ($teaser->isNotEmpty())<p class="mt-3 font-sans text-[14px] text-forest">This record has {{ $teaser->join(', ', ' and ') }}.</p>@endif
            <div class="mt-5 flex flex-wrap gap-3">
                <a href="{{ route('register') }}" class="btn-gold btn-small">Create a free account</a>
                <a href="{{ route('login') }}" class="btn-ghost btn-small">Sign in</a>
            </div>
            <p class="hint text-[13px] mt-3">Free during our introductory launch.</p>
        </div>
    </div>
</x-layouts.site>
