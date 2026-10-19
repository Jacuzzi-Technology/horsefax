<x-layouts.site title="Staff: messages" robots="noindex, nofollow">
    <div class="wrap py-10">
        @include('admin.nav')
        <div class="space-y-4">
            @forelse ($messages as $m)
                <div class="card p-5"><p class="font-sans text-[13px] text-muted">{{ $m->created_at->setTimezone('America/Chicago')->format('M j, Y g:ia') }} CT · {{ $m->topic }} · #{{ $m->id }}</p>
                    <p class="mt-1"><strong>{{ $m->name }}</strong> &lt;<a href="mailto:{{ $m->email }}" class="text-gold-dark">{{ $m->email }}</a>&gt;</p>
                    @if ($m->details)
                        <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-4 gap-y-0.5 font-sans text-[14px]">
                            @foreach ($m->details as $k => $v)<dt class="text-muted">{{ ucfirst($k) }}</dt><dd>{{ $v }}</dd>@endforeach
                        </dl>
                    @endif
                    <p class="mt-2 whitespace-pre-line text-[16px]">{{ $m->message }}</p></div>
            @empty<p class="hint">No messages.</p>@endforelse
        </div>
        <div class="mt-6">{{ $messages->links() }}</div>
    </div>
</x-layouts.site>
