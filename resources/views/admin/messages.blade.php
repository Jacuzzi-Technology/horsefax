<x-layouts.site title="Staff: messages" robots="noindex, nofollow">
    <div class="wrap py-10">
        @include('admin.nav')
        <div class="space-y-4">
            @forelse ($messages as $m)
                <div class="card p-5"><p class="font-sans text-[13px] text-muted">{{ $m->created_at->setTimezone('America/Chicago')->format('M j, Y g:ia') }} CT · {{ $m->topic }} · #{{ $m->id }}</p>
                    <p class="mt-1"><strong>{{ $m->name }}</strong> &lt;<a href="mailto:{{ $m->email }}" class="text-gold-dark">{{ $m->email }}</a>&gt;</p>
                    <p class="mt-2 whitespace-pre-line text-[16px]">{{ $m->message }}</p></div>
            @empty<p class="hint">No messages.</p>@endforelse
        </div>
        <div class="mt-6">{{ $messages->links() }}</div>
    </div>
</x-layouts.site>
