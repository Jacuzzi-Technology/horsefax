<x-layouts.site title="Staff: horses" robots="noindex, nofollow">
    <div class="wrap py-10">
        @include('admin.nav')
        <form method="GET" class="flex gap-2 max-w-[600px] mb-6"><input name="q" value="{{ $q }}" class="field" placeholder="Name, Horsefax ID, registration or chip number"><button class="btn-forest btn-small">Search</button></form>
        <table class="table-hf">
            <thead><tr><th>Horse</th><th>ID</th><th>Identity</th><th>Listed / public</th><th>Created</th></tr></thead>
            <tbody>@foreach ($horses as $h)<tr><td><a href="{{ route('admin.horse', $h) }}" class="text-ink">{{ $h->displayName() }}</a></td><td class="font-sans text-[13px]">{{ $h->horsefax_id }}</td><td>{{ $h->identityLabel() }}@if ($h->fromPedigree())<br><span class="font-sans text-[11px] text-muted">from a pedigree</span>@endif</td><td class="font-sans text-[13px]">{{ $h->listed ? 'Listed' : '—' }} / {{ $h->public_summary ? 'Public' : '—' }}</td><td class="font-sans text-[13px]">{{ $h->created_at->format('M j, Y') }}</td></tr>@endforeach</tbody>
        </table>
        <div class="mt-6">{{ $horses->links() }}</div>
    </div>
</x-layouts.site>
