@php use App\Http\Controllers\Admin\NameReviewController as N; @endphp
<x-layouts.site title="Staff: duplicate names" robots="noindex, nofollow">
    <div class="wrap py-10">
        @include('admin.nav')
        <h1 class="text-[30px]">Possible duplicate names</h1>
        <p class="hint text-[14px] mt-2 max-w-[60em]">Records whose names are the same apart from spacing, or a letter or two apart. Many are two real horses, because registries don't allow duplicate names and owners pick near ones. Check the names in the registry's name search. If one spelling isn't registered, it's a misspelling: keep the registered one and the other merges into it. Enter the registration number the lookup shows, if any. Records someone owns can't be merged here.</p>
        @if (session('status'))<p class="mt-4 font-sans text-[14px] text-forest">{{ session('status') }}</p>@endif
        @if ($errors->any())<p class="error mt-4">{{ $errors->first() }}</p>@endif

        <div class="flex flex-wrap gap-2 mt-5">
            <a href="{{ route('admin.names') }}" class="btn-ghost btn-small {{ $status === 'open' && ! $p ? 'bg-gold/15' : '' }}">All open ({{ number_format($counts->sum()) }})</a>
            @foreach (N::PRIORITIES as $k => $label)
                <a href="{{ route('admin.names', ['p' => $k]) }}" class="btn-ghost btn-small {{ $p === $k ? 'bg-gold/15' : '' }}" title="{{ $label }}">{{ $k }}. {{ \Illuminate\Support\Str::limit($label, 44) }} ({{ number_format($counts[$k] ?? 0) }})</a>
            @endforeach
            <a href="{{ route('admin.names', ['status' => 'different_horses']) }}" class="btn-ghost btn-small {{ $status === 'different_horses' ? 'bg-gold/15' : '' }}">Different horses</a>
            <a href="{{ route('admin.names', ['status' => 'merged']) }}" class="btn-ghost btn-small {{ $status === 'merged' ? 'bg-gold/15' : '' }}">Merged</a>
        </div>

        <div class="mt-6 space-y-4">
        @forelse ($reviews as $r)
            <form method="POST" action="{{ route('admin.names.decide', $r) }}" class="card p-4 sm:p-5">
                @csrf
                <p class="font-sans text-[11px] font-semibold tracking-[0.14em] uppercase text-muted">{{ N::PRIORITIES[$r->priority] ?? '' }}</p>
                <div class="grid md:grid-cols-2 gap-4 mt-3">
                    @foreach (['a' => $r->a, 'b' => $r->b] as $side => $h)
                        @php
                            $par = $facts['parents'][$h->id] ?? collect();
                            $s = $par->firstWhere('position', 's')?->name; $d = $par->firstWhere('position', 'd')?->name;
                            $y = $facts['shown'][$h->id] ?? null;
                        @endphp
                        <div class="border border-line rounded-[3px] p-3">
                            <p class="text-[21px] leading-tight">{{ $h->registered_name }}
                                <button type="button" data-copy="{{ $h->registered_name }}" class="font-sans text-[11px] text-gold-dark ml-2 align-middle">Copy</button></p>
                            <p class="font-sans text-[12px] text-muted mt-1"><a href="{{ route('admin.horse', $h) }}" class="text-gold-dark">{{ $h->horsefax_id }}</a>
                                · {{ ['results' => 'from show results', 'pedigree' => 'from a pedigree', 'published' => 'from a published listing'][$h->source] ?? 'created by an owner' }}
                                @isset($facts['owned'][$h->id]) · <strong>owned</strong>@endisset</p>
                            <p class="font-sans text-[13px] mt-2">{{ collect([$h->birth_year ? 'Foaled '.$h->birth_year : null, $h->sex ? ucfirst($h->sex) : null, $h->color])->filter()->join(' · ') ?: 'No identity details' }}</p>
                            @if ($s || $d)<p class="font-sans text-[13px]">{{ $s ?? '?' }} × {{ $d ?? '?' }}</p>@endif
                            <p class="font-sans text-[13px] text-muted">{{ number_format($facts['results'][$h->id] ?? 0) }} results{{ $y && $y->a ? ' ('.($y->a == $y->b ? $y->a : $y->a.'–'.$y->b).')' : '' }} · in {{ number_format($facts['charts'][$h->id] ?? 0) }} pedigree charts</p>
                            @foreach ($facts['regs'][$h->id] ?? [] as $reg)<p class="font-sans text-[13px]">{{ $reg->registry }} {{ $reg->value }}</p>@endforeach
                            @if ($r->status === 'open')
                                <label class="field-label mt-3" for="reg-{{ $r->id }}-{{ $side }}">Registration # from the lookup (optional)</label>
                                <input id="reg-{{ $r->id }}-{{ $side }}" name="reg_{{ $side }}" class="field" inputmode="numeric" placeholder="e.g. 3491928">
                            @endif
                        </div>
                    @endforeach
                </div>
                @if ($r->status === 'open')
                    <div class="flex flex-wrap items-end gap-2 mt-4">
                        <input name="note" class="field flex-1 min-w-[220px]" placeholder="Note (optional), e.g. AQHA name search Oct 18">
                        <button name="decision" value="different" class="btn-ghost btn-small">Different horses</button>
                        <button name="decision" value="keep_a" class="btn-forest btn-small" @isset($facts['owned'][$r->horse_b_id]) disabled title="The other record is owned" @endisset>Same horse: keep left{{ strcasecmp($r->a->registered_name, $r->b->registered_name) ? ' “'.\Illuminate\Support\Str::limit($r->a->registered_name, 24).'”' : '' }} ({{ $r->a->horsefax_id }})</button>
                        <button name="decision" value="keep_b" class="btn-forest btn-small" @isset($facts['owned'][$r->horse_a_id]) disabled title="The other record is owned" @endisset>Same horse: keep right{{ strcasecmp($r->a->registered_name, $r->b->registered_name) ? ' “'.\Illuminate\Support\Str::limit($r->b->registered_name, 24).'”' : '' }} ({{ $r->b->horsefax_id }})</button>
                    </div>
                @else
                    <p class="font-sans text-[13px] mt-3 text-muted">{{ $r->status === 'merged' ? 'Merged, kept '.optional(App\Models\Horse::find($r->kept_horse_id))->registered_name : 'Different horses' }}{{ $r->registration_number ? ' · reg '.$r->registration_number : '' }}{{ $r->note ? ' · '.$r->note : '' }} · {{ $r->decided_at?->format('M j, Y') }}</p>
                @endif
            </form>
        @empty
            <p class="hint">Nothing here.</p>
        @endforelse
        </div>
        <div class="mt-6">{{ $reviews->links() }}</div>
    </div>
</x-layouts.site>
