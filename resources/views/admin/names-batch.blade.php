<x-layouts.site title="Staff: same-letter names, batch" robots="noindex, nofollow">
    <div class="wrap py-10">
        @include('admin.nav')
        <h1 class="text-[30px]">Same letters, nothing disagrees</h1>
        <p class="hint text-[14px] mt-2 max-w-[60em]">Pairs whose names have the same letters, apart from spacing or punctuation, where nothing on either record disagrees: no registration number, sire, dam, foal year or sex that differs, and neither record is owned. Each row is set to “same horse” and keeps the suggested spelling: the record with a registration number, else the show-results spelling (shows print the registered name), else the one with more results. Switch any row you're unsure of to the other spelling, “different horses” or “skip”, then apply. A merge keeps the old record as a pointer and its spelling as an alias, as on the one-by-one list.</p>
        @if (session('status'))<p class="mt-4 font-sans text-[14px] text-forest">{{ session('status') }}</p>@endif
        @if ($errors->any())<p class="error mt-4">{{ $errors->first() }}</p>@endif
        <p class="font-sans text-[14px] mt-4">{{ number_format($total) }} pairs ready{{ $total > $rows->count() ? ', '.$rows->count().' shown' : '' }}. <a href="{{ route('admin.names', ['p' => 1]) }}" class="text-gold-dark">All {{ number_format($counts[1] ?? 0) }} open same-letter pairs, one by one</a></p>

        @if ($rows->isNotEmpty())
        <form method="POST" action="{{ route('admin.names.batch.decide') }}" class="mt-5">
            @csrf
            <div class="flex flex-wrap gap-2 items-center mb-3 font-sans text-[13px]">
                <span class="text-muted">Set every row to:</span>
                <button type="button" data-all="suggested" class="btn-ghost btn-small">Same horse (suggested spelling)</button>
                <button type="button" data-all="skip" class="btn-ghost btn-small">Skip</button>
            </div>
            <div class="space-y-2">
            @foreach ($rows as $row)
                @php $r = $row['review']; $f = $row['check']->facts; @endphp
                <div class="card p-3 sm:p-4" data-row data-suggested="{{ $row['keep'] }}">
                    <div class="grid md:grid-cols-2 gap-3">
                        @foreach (['a' => $r->a, 'b' => $r->b] as $side => $h)
                            @php $par = $f['parents'][$h->id] ?? collect(); $sire = $par->firstWhere('position', 's')?->name; $dam = $par->firstWhere('position', 'd')?->name; $y = $f['shown'][$h->id] ?? null; @endphp
                            <label class="block border border-line rounded-[3px] p-2.5 cursor-pointer has-[:checked]:border-forest has-[:checked]:bg-forest/5">
                                <input type="radio" name="decisions[{{ $r->id }}]" value="keep_{{ $side }}" @checked($row['keep'] === 'keep_'.$side) class="mr-1">
                                <span class="text-[18px]">{{ $h->registered_name }}</span>
                                <span class="font-sans text-[12px] text-muted ml-1">keep this spelling{{ $row['keep'] === 'keep_'.$side ? ' (suggested)' : '' }}</span>
                                <span class="block font-sans text-[12px] text-muted mt-1"><a href="{{ route('admin.horse', $h) }}" class="text-gold-dark" target="_blank">{{ $h->horsefax_id }}</a>
                                    · {{ ['results' => 'from show results', 'pedigree' => 'from a pedigree', 'published' => 'from a published listing'][$h->source] ?? 'created by an owner' }}
                                    · {{ collect([$h->birth_precision !== 'unknown' ? 'foaled '.$h->birth_year : null, $h->sex, $h->color, $sire || $dam ? ($sire ?? '?').' × '.($dam ?? '?') : null])->filter()->join(' · ') ?: 'no identity details' }}
                                    · {{ number_format($f['results'][$h->id] ?? 0) }} results{{ $y && $y->a ? ' ('.($y->a == $y->b ? $y->a : $y->a.'–'.$y->b).')' : '' }}
                                    @foreach ($f['regs'][$h->id] ?? [] as $reg) · {{ $reg->registry }} {{ $reg->value }}@endforeach</span>
                            </label>
                        @endforeach
                    </div>
                    <div class="flex gap-4 mt-2 font-sans text-[13px]">
                        <label><input type="radio" name="decisions[{{ $r->id }}]" value="different" class="mr-1">Different horses</label>
                        <label><input type="radio" name="decisions[{{ $r->id }}]" value="skip" class="mr-1">Skip (leave on the list)</label>
                    </div>
                </div>
            @endforeach
            </div>
            <button class="btn-forest mt-5">Apply these {{ $rows->count() }} decisions</button>
        </form>
        <script>
            document.querySelectorAll('[data-all]').forEach(b => b.addEventListener('click', () => {
                document.querySelectorAll('[data-row]').forEach(row => {
                    const v = b.dataset.all === 'suggested' ? row.dataset.suggested : 'skip';
                    const input = row.querySelector('input[value="' + v + '"]');
                    if (input) input.checked = true;
                });
            }));
        </script>
        @else
            <p class="hint mt-6">Nothing waiting here.</p>
        @endif
    </div>
</x-layouts.site>
