@php use App\Models\HorseEvent; @endphp
<x-layouts.site title="Staff: sale history" robots="noindex, nofollow">
    <div class="wrap py-10">
        @include('admin.nav')
        <h1 class="text-[30px]">Sale history</h1>
        <p class="font-sans text-[14px] mt-2">{{ number_format($counts->total) }} published lots · {{ number_format($counts->matched) }} on records · {{ number_format($counts->queued) }} waiting for review · {{ number_format($counts->conflict) }} conflicts</p>
        <p class="hint text-[14px] mt-2">Lots attach on their own only by registration number, name + sire + dam, or name + foal year + a parent. Everything here needs a person: attach only when you've confirmed it's the same horse (papers, the owner, a registry lookup), and say how.</p>
        @foreach ($sources as $s)
            <p class="hint text-[13px] mt-1">{{ $s->name }}: {{ $s->terms_note }}</p>
        @endforeach
        @if (session('status'))<p class="mt-4 font-sans text-[14px] text-forest">{{ session('status') }}</p>@endif
        @error('horsefax_id')<p class="error mt-4">{{ $message }}</p>@enderror
        @error('suggestion')<p class="error mt-4">{{ $message }}</p>@enderror
        <div class="flex flex-wrap gap-2 mt-5">
            @foreach (['queued' => 'Waiting', 'conflict' => 'Conflicts', 'held' => 'Matched, not published yet', 'unmatched' => 'No record of that name', 'matched' => 'On records', 'dismissed' => 'Dismissed'] as $k => $label)
                <a href="{{ route('admin.sale-lots', ['status' => $k, 'q' => $q]) }}" class="btn-ghost btn-small {{ $status === $k ? 'bg-gold/15' : '' }}">{{ $label }}</a>
            @endforeach
        </div>
        <form method="GET" class="flex gap-2 max-w-[520px] mt-4"><input type="hidden" name="status" value="{{ $status }}"><input name="q" value="{{ $q }}" class="field" placeholder="Horse name"><button class="btn-forest btn-small">Search</button></form>
        <table class="table-hf mt-5">
            <thead><tr><th>Horse (as published)</th><th>Sale</th><th>What the sale reported</th><th>Records with this name</th><th></th></tr></thead>
            <tbody>
            @foreach ($lots as $l)
                <tr>
                    <td>
                        <p class="text-ink">{{ $l->horse_name }}</p>
                        <p class="font-sans text-[12px] text-muted">{{ collect([$l->registration_number ? ($l->registry ?? 'Reg').' '.$l->registration_number : null, $l->foal_year ? 'foaled '.$l->foal_year : null, $l->sex, $l->color])->filter()->join(' · ') }}</p>
                        @if ($l->sire_name || $l->dam_name)<p class="font-sans text-[12px] text-muted">{{ $l->sire_name ?? '?' }} × {{ $l->dam_name ?? '?' }}{{ $l->dam_sire_name ? ' ('.$l->dam_sire_name.')' : '' }}</p>@endif
                    </td>
                    <td class="font-sans text-[13px]">{{ $l->sale_name }}{{ $l->lot ? ', lot '.$l->lot : '' }}<br><span class="text-muted">{{ $l->sale_date?->format($l->date_precision === 'day' ? 'M j, Y' : 'M Y') ?? $l->sale_year }}</span> · <a href="{{ $l->source_url }}" class="text-gold-dark" target="_blank" rel="noopener">source</a></td>
                    <td class="font-sans text-[13px]">{{ ['sold' => 'Reported sale', 'high_bid' => 'High bid, sale unconfirmed', 'no_sale' => 'No sale'][$l->status] ?? $l->status }} {{ HorseEvent::money($l->price_cents) }}@if ($l->note)<br><span class="text-muted">{{ $l->note }}</span>@endif</td>
                    <td class="font-sans text-[12px]">
                        @if ($l->review_note)<p class="text-muted mb-1">{{ $l->review_note }}</p>@endif
                        @foreach ($candidates->get($l->name_key, collect()) as $h)
                            @php $p = $h->pedigree->keyBy('position'); @endphp
                            <p><a href="{{ route('admin.horse', $h) }}" class="text-gold-dark">{{ $h->horsefax_id }}</a> {{ $h->birth_year ? 'foaled '.$h->birth_year : 'foal year unknown' }}@if ($p->isNotEmpty()) · {{ $p['s']->name ?? '?' }} × {{ $p['d']->name ?? '?' }}@endif</p>
                        @endforeach
                    </td>
                    <td>
                        @if ($l->horse_id)
                            <a href="{{ route('admin.horse', $l->horse) }}" class="font-sans text-[12px] text-gold-dark">{{ $l->horse?->horsefax_id }}</a> <span class="font-sans text-[11px] text-muted">{{ $l->match_tier }}</span>
                        @else
                            <form method="POST" action="{{ route('admin.sale-lots.attach', $l) }}" class="flex flex-wrap gap-1" data-confirm="Attach this sale to that horse's public record?">
                                @csrf
                                <input name="horsefax_id" placeholder="HF-XXXX-XXXX" class="font-sans text-[12px] border border-line rounded px-2 py-1 w-32" required>
                                <input name="note" placeholder="How you confirmed it" class="font-sans text-[12px] border border-line rounded px-2 py-1 w-40" required>
                                <button class="btn-ghost btn-small">Attach</button>
                            </form>
                            @if ($status !== 'dismissed')
                                <form method="POST" action="{{ route('admin.sale-lots.dismiss', $l) }}" class="flex gap-1 mt-1">
                                    @csrf
                                    <input name="note" placeholder="Why not" class="font-sans text-[12px] border border-line rounded px-2 py-1 w-40" required>
                                    <button class="btn-ghost btn-small">Dismiss</button>
                                </form>
                            @endif
                        @endif
                    </td>
                </tr>
                @if (! $l->horse_id && in_array($status, ['queued', 'conflict'], true))
                    <tr><td colspan="5" class="pt-0">@include('admin.partials.suggestion', ['s' => $suggestions[$l->id] ?? null, 'name' => $l->horse_name])</td></tr>
                @endif
            @endforeach
            </tbody>
        </table>
        <div class="mt-6">{{ $lots->links() }}</div>
    </div>
</x-layouts.site>
