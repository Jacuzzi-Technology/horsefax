@props(['horse', 'href', 'match' => null, 'photo' => null])
<a href="{{ $href }}" class="card flex gap-4 p-4 no-underline hover:border-gold/60 transition-colors">
    <div class="w-20 h-20 shrink-0 bg-forest rounded-[2px] overflow-hidden flex items-center justify-center">
        @if ($photo)<img src="{{ $photo }}" alt="" class="w-full h-full object-cover">@else<img src="/img/hf-tile.svg" alt="" class="w-full h-full">@endif
    </div>
    <div class="min-w-0">
        <p class="text-[20px] text-ink leading-tight truncate">{{ $horse->displayName() }}</p>
        <p class="font-sans text-[13px] text-muted mt-1">{{ collect([$horse->breed, $horse->sex ? ucfirst($horse->sex) : null, $horse->birth_year ? 'Foaled '.$horse->birth_year : null, $horse->color])->filter()->join(' · ') ?: 'Details not added yet' }}</p>
        <p class="font-sans text-[11px] tracking-[0.12em] text-gold-dark mt-2">{{ $horse->horsefax_id }}@if ($match) <span class="text-muted">· matched on {{ $match }}</span>@endif</p>
    </div>
</a>
