<x-layouts.site title="Staff: review queue" robots="noindex, nofollow">
    <div class="wrap py-10">
        <h1 class="text-[34px] mb-4">Staff</h1>
        @include('admin.nav')
        <div class="flex flex-wrap gap-3 items-center mb-4 font-sans text-[13px]">
            @foreach (['open' => 'Open', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $k => $l)
                <a href="{{ route('admin.queue', ['status' => $k]) }}" class="{{ $status === $k ? 'text-ink font-semibold' : 'text-gold-dark' }}">{{ $l }}</a>
            @endforeach
            <span class="text-muted">· Open: @foreach (\App\Models\ReviewItem::KINDS as $k => $l){{ $l }} {{ $counts[$k] ?? 0 }}@if (! $loop->last), @endif @endforeach · {{ $newMessages }} new messages</span>
        </div>
        <table class="table-hf">
            <thead><tr><th>Received</th><th>Kind</th><th>Horse</th><th>From</th><th>Summary</th></tr></thead>
            <tbody>
            @forelse ($items as $i)
                <tr>
                    <td class="whitespace-nowrap font-sans text-[13px]"><a href="{{ route('admin.review', $i) }}" class="text-gold-dark">{{ $i->created_at->setTimezone('America/Chicago')->format('M j, g:ia') }} CT</a></td>
                    <td>{{ $i->kindLabel() }}</td>
                    <td>{{ $i->horse?->displayName() }}<br><span class="font-sans text-[12px] text-muted">{{ $i->horse?->horsefax_id }}</span></td>
                    <td class="font-sans text-[13px]">{{ $i->submitter?->name ?? $i->submitter_name }}<br>{{ $i->submitter?->email ?? $i->submitter_email }}</td>
                    <td class="text-[15px]">{{ \Illuminate\Support\Str::limit($i->statement, 140) }}</td>
                </tr>
            @empty<tr><td colspan="5" class="hint">Nothing here.</td></tr>@endforelse
            </tbody>
        </table>
        <div class="mt-6">{{ $items->links() }}</div>
    </div>
</x-layouts.site>
