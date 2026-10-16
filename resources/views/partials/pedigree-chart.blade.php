{{-- Pedigree chart (allbreedpedigree-style brackets), as many generations as have been entered (at least three). --}}
@php
    use App\Support\Record;
    $depth = min(Record::PEDIGREE_GENERATIONS, max(3, $pedigree->keys()->map(fn ($p) => strlen($p))->max() ?? 0));
    $rows = 2 ** $depth;
@endphp
<div class="mt-5 overflow-x-auto">
    <div class="grid gap-1 text-[15px]" style="grid-template-columns: repeat({{ $depth }}, minmax(150px, 1fr)); grid-template-rows: repeat({{ $rows }}, minmax({{ $depth >= 5 ? 22 : ($depth === 4 ? 30 : 44) }}px, auto)); min-width: {{ $depth * 170 }}px" role="table" aria-label="Pedigree, {{ $depth }} generations">
        @foreach (Record::pedigreePositions() as $pos => $label)
            @continue(strlen($pos) > $depth)
            @php
                $g = strlen($pos);
                $index = bindec(strtr($pos, ['s' => '0', 'd' => '1']));
                $span = 2 ** ($depth - $g);
                $e = $pedigree->get($pos);
                $male = str_ends_with($pos, 's');
                $small = $g >= 4;
            @endphp
            <div role="cell" style="grid-row: {{ $index * $span + 1 }} / span {{ $span }}; grid-column: {{ $g }}"
                 class="flex flex-col justify-center rounded-[2px] border {{ $small ? 'px-2 py-0.5' : 'px-3 py-2' }} {{ $male ? 'bg-forest/[0.04] border-forest/15' : 'bg-gold/[0.06] border-gold/20' }}">
                @if ($e)
                    @if ($e->linkedHorse && ($e->linkedHorse->public_summary || $e->linkedHorse->listed))
                        <a href="{{ route('horse.public', $e->linkedHorse) }}" class="text-ink {{ $g === 1 ? 'text-[18px]' : ($small ? 'text-[12.5px] leading-tight' : '') }}">{{ $e->name }}</a>
                    @else
                        <span class="text-ink {{ $g === 1 ? 'text-[18px]' : ($small ? 'text-[12.5px] leading-tight' : '') }}">{{ $e->name }}</span>
                    @endif
                    @unless ($small)
                        <span class="font-sans text-[11px] text-muted">{{ collect([$e->registry && $e->registration_number ? $e->registry.' '.$e->registration_number : null, $e->foal_year, $e->color])->filter()->join(' · ') }}</span>
                    @endunless
                @else
                    <span class="text-muted/60 italic {{ $small ? 'text-[11px]' : 'text-[14px]' }}">{{ $g <= 2 ? $label.' not recorded' : '—' }}</span>
                @endif
            </div>
        @endforeach
    </div>
</div>
