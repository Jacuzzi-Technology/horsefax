{{-- Three-generation pedigree chart (allbreedpedigree-style brackets). Sire lines on top, dam lines below. --}}
@php
    $cells = [
        ['s', 1, 4, 1], ['d', 5, 4, 1],
        ['ss', 1, 2, 2], ['sd', 3, 2, 2], ['ds', 5, 2, 2], ['dd', 7, 2, 2],
        ['sss', 1, 1, 3], ['ssd', 2, 1, 3], ['sds', 3, 1, 3], ['sdd', 4, 1, 3], ['dss', 5, 1, 3], ['dsd', 6, 1, 3], ['dds', 7, 1, 3], ['ddd', 8, 1, 3],
    ];
@endphp
<div class="mt-5 overflow-x-auto">
    <div class="grid grid-cols-3 grid-rows-8 gap-1.5 min-w-[600px] text-[15px]" role="table" aria-label="Pedigree">
        @foreach ($cells as [$pos, $row, $span, $col])
            @php $e = $pedigree->get($pos); $male = str_ends_with($pos, 's'); @endphp
            <div role="cell" style="grid-row: {{ $row }} / span {{ $span }}; grid-column: {{ $col }}"
                 class="flex flex-col justify-center px-3 py-2 rounded-[2px] border {{ $male ? 'bg-forest/[0.04] border-forest/15' : 'bg-gold/[0.06] border-gold/20' }}">
                @if ($e)
                    @if ($e->linkedHorse && $e->linkedHorse->public_summary)
                        <a href="{{ route('horse.public', $e->linkedHorse) }}" class="text-ink {{ $col === 1 ? 'text-[18px]' : '' }}">{{ $e->name }}</a>
                    @else
                        <span class="text-ink {{ $col === 1 ? 'text-[18px]' : '' }}">{{ $e->name }}</span>
                    @endif
                    <span class="font-sans text-[11px] text-muted">{{ collect([$e->registry && $e->registration_number ? $e->registry.' '.$e->registration_number : null, $e->foal_year, $e->color])->filter()->join(' · ') }}</span>
                @else
                    <span class="text-muted/60 italic text-[14px]">{{ \App\Support\Record::PEDIGREE_POSITIONS[$pos] !== $pos ? \App\Support\Record::PEDIGREE_POSITIONS[$pos] : '' }} not recorded</span>
                @endif
            </div>
        @endforeach
    </div>
</div>
