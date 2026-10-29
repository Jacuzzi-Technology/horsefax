@php use App\Models\HorseEvent; $pct = fn ($a, $b) => $b ? round(100 * $a / $b, 1).'%' : '—'; @endphp
<x-layouts.site title="Staff: offspring earnings" robots="noindex, nofollow">
    <div class="wrap py-10">
        @include('admin.nav')
        <h1 class="text-[30px]">Offspring earnings</h1>
        <p class="hint text-[14px] mt-2">Staff only. Computed by Horsefax from the show results we've harvested, not NRHA's or EquiStat's figures. Not shown on public horse pages until the totals roughly match NRHA's published milestones (below); Richard decides when (Oct 22 2026). A foal counts only when its results are on its record and its pedigree names the parent.</p>
        @if (! $run)
            <p class="mt-4 font-sans text-[14px]">Not computed yet: <code>php artisan hf:offspring-earnings</code>.</p>
        @else
            @php $s = $run->stats; $nrha = collect($s['organizations'])->firstWhere('org', 'NRHA'); @endphp
            <p class="font-sans text-[14px] mt-3">Last run {{ \Illuminate\Support\Carbon::parse($run->created_at)->setTimezone('America/Chicago')->format('M j g:ia') }} ({{ $run->seconds }}s) · {{ number_format($s['foals_with_results']) }} horses with results · {{ number_format($s['foals_with_sire']) }} with a known sire ({{ $pct($s['foals_with_sire'], $s['foals_with_results']) }}) · {{ number_format($s['foals_with_dam']) }} with a known dam · {{ number_format($s['unlinked_parent_lines']) }} parent lines not tied to a record</p>

            <h2 class="text-[24px] mt-8">Coverage</h2>
            <p class="hint text-[13px] mt-1">Money on records whose sire we know, versus money we can't credit yet: no sire line on the horse's record, a sire named but not tied to a record, results whose name isn't matched to a horse, or results held for staff.</p>
            <table class="table-hf mt-2 text-[13px]">
                <thead><tr><th>Organization</th><th class="text-right">All harvested $</th><th class="text-right">Credited to a sire</th><th class="text-right">No sire line</th><th class="text-right">Sire not tied</th><th class="text-right">Name unmatched</th><th class="text-right">Held</th><th class="text-right">Earners with sire</th></tr></thead>
                <tbody>@foreach ($s['organizations'] as $o)
                    @php $total = ($o['attached_cents'] ?? 0) + ($o['unmatched_cents'] ?? 0) + ($o['held_cents'] ?? 0); @endphp
                    @continue($total <= 0)
                    <tr>
                        <td>{{ $o['org'] }}</td>
                        <td class="text-right">{{ HorseEvent::money($total) }}</td>
                        <td class="text-right">{{ HorseEvent::money($o['sire_cents'] ?? 0) }} <span class="text-muted">{{ $pct($o['sire_cents'] ?? 0, $total) }}</span></td>
                        <td class="text-right">{{ $pct($o['no_sire_line_cents'] ?? 0, $total) }}</td>
                        <td class="text-right">{{ $pct($o['sire_named_unlinked_cents'] ?? 0, $total) }}</td>
                        <td class="text-right">{{ $pct($o['unmatched_cents'] ?? 0, $total) }}</td>
                        <td class="text-right">{{ $pct($o['held_cents'] ?? 0, $total) }}</td>
                        <td class="text-right">{{ number_format($o['earners_with_sire'] ?? 0) }} / {{ number_format($o['earners'] ?? 0) }}</td>
                    </tr>
                @endforeach</tbody>
            </table>
            @php $years = collect($s['nrha_years']); $thin = $years->filter(fn ($y) => $y['results'] < 10000)->keys(); @endphp
            <p class="hint text-[13px] mt-2">NRHA years harvested: {{ $years->filter(fn ($y) => $y['results'] >= 10000)->keys()->min() }}–{{ $years->keys()->max() }} in full{{ $thin->isNotEmpty() ? '; only a few results so far for '.$thin->implode(', ') : '' }}. The harvest runs newest year first, back to 1974; NRHA's milestones count every year, so the older sires read lowest.</p>
        @endif

        <div class="flex flex-wrap gap-2 mt-8">
            @foreach (['sire' => 'Sires', 'dam' => 'Dams'] as $k => $label)
                <a href="{{ route('admin.offspring', ['role' => $k, 'org' => $org]) }}" class="btn-ghost btn-small {{ $role === $k ? 'bg-gold/15' : '' }}">{{ $label }}</a>
            @endforeach
            <span class="w-4"></span>
            @foreach ($orgs->prepend('all') as $o)
                <a href="{{ route('admin.offspring', ['role' => $role, 'org' => $o]) }}" class="btn-ghost btn-small {{ $org === $o ? 'bg-gold/15' : '' }}">{{ $o === 'all' ? 'All organizations' : $o }}</a>
            @endforeach
        </div>

        <h2 class="text-[24px] mt-8">NRHA benchmark</h2>
        <p class="hint text-[13px] mt-1">NRHA's Million Dollar Earners page lists the highest $1M tier each {{ $role }} has passed, with no dollar amounts, so the milestone is a floor (read Oct 22 2026). "Ours" is NRHA results only. More than one record means the same horse is on file twice and should be merged.</p>
        <table class="table-hf mt-2 text-[13px]">
            <thead><tr><th>{{ ucfirst($role) }}</th><th class="text-right">NRHA passed</th><th class="text-right">Ours (NRHA)</th><th class="text-right">Share</th><th class="text-right">NRHA earners</th><th class="text-right">Foals on record</th><th class="text-right">All organizations</th><th>Records</th></tr></thead>
            <tbody>@foreach ($benchmark as $b)
                <tr>
                    <td>@if ($b['horse_id'])<a href="{{ route('admin.horse', \App\Models\Horse::find($b['horse_id'])) }}" class="text-ink">{{ $b['name'] }}</a>@else{{ $b['name'] }}@endif</td>
                    <td class="text-right">${{ $b['milestone_millions'] }}M ({{ $b['milestone_year'] }})</td>
                    <td class="text-right">{{ HorseEvent::money($b['nrha_cents']) }}</td>
                    <td class="text-right">{{ round(100 * $b['share_of_milestone'], 1) }}%</td>
                    <td class="text-right">{{ number_format($b['nrha_earners']) }}</td>
                    <td class="text-right">{{ number_format($b['offspring']) }}</td>
                    <td class="text-right">{{ HorseEvent::money($b['all_cents']) }}</td>
                    <td class="{{ $b['records'] === 1 ? '' : 'text-red-700' }}">{{ $b['records'] }}</td>
                </tr>
            @endforeach</tbody>
        </table>

        <h2 class="text-[24px] mt-10">Leading {{ $role }}s: {{ $org === 'all' ? 'all organizations' : $org }}</h2>
        <table class="table-hf mt-2 text-[13px]">
            <thead><tr><th>#</th><th>{{ ucfirst($role) }}</th><th class="text-right">Earnings</th><th class="text-right">Earners</th><th class="text-right">Starters</th><th class="text-right">Foals on record</th><th class="text-right">All organizations</th><th>Years</th></tr></thead>
            <tbody>@forelse ($leaders as $i => $l)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>@if ($l->horse)<a href="{{ route('admin.horse', $l->horse) }}" class="text-ink">{{ $l->horse->displayName() }}</a> <span class="text-muted text-[11px]">{{ $l->horse->horsefax_id }}</span>@endif</td>
                    <td class="text-right">{{ HorseEvent::money($l->earnings_cents) }}</td>
                    <td class="text-right">{{ number_format($l->earners) }}</td>
                    <td class="text-right">{{ number_format($l->starters) }}</td>
                    <td class="text-right">{{ number_format($totals[$l->horse_id]->offspring ?? $l->offspring) }}</td>
                    <td class="text-right">{{ HorseEvent::money($totals[$l->horse_id]->earnings_cents ?? 0) }}</td>
                    <td>{{ $l->first_year === $l->last_year ? $l->first_year : $l->first_year.'–'.$l->last_year }}</td>
                </tr>
            @empty<tr><td class="hint" colspan="8">None.</td></tr>@endforelse</tbody>
        </table>
    </div>
</x-layouts.site>
