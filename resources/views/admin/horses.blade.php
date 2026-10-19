<x-layouts.site title="Staff: horses" robots="noindex, nofollow">
    <div class="wrap py-10">
        @include('admin.nav')
        @php
            $sourceLabels = ['ifce' => 'French registry (IFCE)', 'results' => 'From show results', 'pedigree' => 'From a pedigree', 'published' => 'From a published listing', 'owner' => 'Owner-created', '(none)' => 'Owner-created (older)'];
            $filters = ['registry' => 'Registry (registration number on file)', 'breed' => 'Breed', 'sex' => 'Sex', 'color' => 'Color', 'source' => 'Source'];
        @endphp
        <form method="GET" class="mb-6 space-y-3">
            <div class="flex gap-2 max-w-[600px]"><input name="q" value="{{ $q }}" class="field" placeholder="Name, Horsefax ID, registration or chip number"><button class="btn-forest btn-small">Search</button></div>
            <div class="flex flex-wrap gap-3 items-end font-sans text-[13px]">
                @foreach ($filters as $key => $label)
                    <label class="flex flex-col gap-1">{{ $label }}
                        <select name="{{ $key }}" class="border border-line rounded px-2 py-1 bg-cream max-w-[220px]" onchange="this.form.submit()">
                            <option value="">Any</option>
                            @if ($key === 'source')<option value="not_ifce" @selected($f['source'] === 'not_ifce')>Everything except IFCE</option>@endif
                            @foreach ($options[$key] as $value => $n)
                                <option value="{{ $value }}" @selected($f[$key] === (string) $value)>{{ $key === 'source' ? ($sourceLabels[$value] ?? $value) : ($key === 'sex' && $value !== '(none)' ? ucfirst($value) : $value) }} ({{ number_format($n) }})</option>
                            @endforeach
                        </select>
                    </label>
                @endforeach
                @if (array_filter($f) || $q !== '')<a href="{{ route('admin.horses') }}" class="btn-ghost btn-small">Clear</a>@endif
            </div>
            <p class="hint text-[12px]">Counts beside each choice are for all records and refresh hourly. "AQHA" under Registry means an AQHA registration number is on the record; Breed shows the breed as recorded (most "Quarter Horse" breed records come from the French registry).</p>
        </form>
        <table class="table-hf">
            <thead><tr><th>Horse</th><th>ID</th><th>Identity</th><th>Listed / public</th><th>Created</th></tr></thead>
            <tbody>@foreach ($horses as $h)<tr><td><a href="{{ route('admin.horse', $h) }}" class="text-ink">{{ $h->displayName() }}</a></td><td class="font-sans text-[13px]">{{ $h->horsefax_id }}</td><td>{{ $h->identityLabel() }}@if ($h->fromPedigree())<br><span class="font-sans text-[11px] text-muted">from a pedigree</span>@elseif ($h->fromResults())<br><span class="font-sans text-[11px] text-muted">from show results</span>@elseif ($h->source === 'published')<br><span class="font-sans text-[11px] text-muted">from a published listing</span>@elseif ($h->source === 'ifce')<br><span class="font-sans text-[11px] text-muted">French registry (IFCE)</span>@endif</td><td class="font-sans text-[13px]">{{ $h->listed ? 'Listed' : '—' }} / {{ $h->public_summary ? 'Public' : '—' }}</td><td class="font-sans text-[13px]">{{ $h->created_at->format('M j, Y') }}</td></tr>@endforeach</tbody>
        </table>
        <p class="mt-4 font-sans text-[15px] font-semibold">{{ number_format($horses->total()) }} {{ $horses->total() === 1 ? 'horse' : 'horses' }}{{ array_filter($f) || $q !== '' ? ' match' : ' in all' }}</p>
        <div class="mt-4">{{ $horses->links() }}</div>
    </div>
</x-layouts.site>
