<x-layouts.site title="My horses" robots="noindex, nofollow">
    <div class="wrap py-12">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div><p class="label text-gold">Signed in as {{ auth()->user()->name }}</p><h1 class="text-[38px] mt-2">My horses</h1></div>
            <a href="{{ route('search') }}" class="btn-gold">Find or add a horse</a>
        </div>

        @if ($incoming->isNotEmpty())
            <div class="mt-8 space-y-3">
                @foreach ($incoming as $t)
                    <div class="card p-5 border-gold/50 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <p class="text-[18px]">You have been offered the Horsefax record for <strong>{{ $t->horse->displayName() }}</strong> ({{ $t->horse->horsefax_id }}).</p>
                        <div class="flex gap-2">
                            <form method="POST" action="{{ route('transfers.accept', $t) }}" data-confirm="Accept ownership of this record?">@csrf<button class="btn-gold btn-small">Accept</button></form>
                            <form method="POST" action="{{ route('transfers.decline', $t) }}">@csrf<button class="btn-ghost btn-small">Decline</button></form>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($invitations->isNotEmpty())
            <div class="mt-8 space-y-3">
                @foreach ($invitations as $inv)
                    <div class="card p-5 border-gold/50 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <p class="text-[18px]">{{ $inv->inviter?->name ?? 'An owner' }} invited you to contribute to the Horsefax record for <strong>{{ $inv->horse->displayName() }}</strong> ({{ $inv->horse->horsefax_id }}) as its {{ strtolower($inv->roleLabel()) }}.</p>
                        <div class="flex gap-2">
                            <form method="POST" action="{{ route('contributors.accept', $inv) }}">@csrf<button class="btn-gold btn-small">Accept</button></form>
                            <form method="POST" action="{{ route('contributors.decline', $inv) }}">@csrf<button class="btn-ghost btn-small">Decline</button></form>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($horses->isEmpty() && $contributing->isEmpty())
            <div class="card p-10 mt-8 text-center">
                <img src="/img/hf-mark.svg" alt="" class="w-[90px] mx-auto mb-6">
                <h2 class="text-[28px]">Start your first record</h2>
                <p class="mt-2 text-[18px] text-muted">Search for your horse first. If it isn't on Horsefax yet, you can add it.</p>
                <a href="{{ route('search') }}" class="btn-gold mt-6">Find or add a horse</a>
            </div>
        @elseif ($horses->isNotEmpty())
            <div class="grid md:grid-cols-2 gap-4 mt-8">
                @foreach ($horses as $h)
                    <x-horse-card :horse="$h" :href="route('horses.show', $h)" :photo="$h->profilePhoto ? route('files.show', $h->profilePhoto) : null" />
                @endforeach
            </div>
        @endif

        @if ($contributing->isNotEmpty())
            <h2 class="text-[24px] mt-12">Horses you contribute to</h2>
            <p class="hint">Records you were invited to as a professional. You can add entries and documents in your own name.</p>
            <div class="grid md:grid-cols-2 gap-4 mt-4">
                @foreach ($contributing as $c)
                    <x-horse-card :horse="$c->horse" :href="route('contribute', $c->horse)" />
                @endforeach
            </div>
        @endif

        @if ($claims->isNotEmpty())
            <h2 class="text-[24px] mt-12">Your ownership claims</h2>
            <table class="table-hf mt-3">
                <thead><tr><th>Horse</th><th>Submitted</th><th>Status</th></tr></thead>
                <tbody>@foreach ($claims as $c)<tr><td>{{ $c->horse?->displayName() }}</td><td>{{ $c->created_at->format('M j, Y') }}</td><td>{{ ['open' => 'Under review', 'approved' => 'Approved', 'rejected' => 'Not approved'][$c->status] }}@if ($c->status !== 'open' && $c->resolution_note)<br><span class="hint">{{ $c->resolution_note }}</span>@endif</td></tr>@endforeach</tbody>
            </table>
        @endif
    </div>
</x-layouts.site>
