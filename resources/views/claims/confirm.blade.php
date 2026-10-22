<x-layouts.site title="Confirm a sale" robots="noindex, nofollow">
    <div class="wrap py-12 max-w-[640px]">
        <p class="label text-gold">Seller confirmation</p>
        <h1 class="text-[34px] mt-2">Did you sell {{ $c->reviewItem->horse?->displayName() }}?</h1>
        <p class="font-sans text-[14px] text-muted mt-1">{{ $c->reviewItem->horse?->horsefax_id }}</p>
        @if (! empty($done) || $c->responded_at)
            <div class="card p-6 mt-6"><p class="text-[18px]">Thank you. You {{ $c->response === 'confirmed' ? 'confirmed' : 'denied' }} this sale on {{ $c->responded_at->format('M j, Y') }}. Horsefax staff will take it into account.</p></div>
        @else
            <p class="mt-4 text-[18px]"><strong>{{ $c->reviewItem->submitter?->name ?? 'A Horsefax member' }}</strong> has asked Horsefax to record them as this horse's owner and named you, {{ $c->seller_name }}, as the seller.</p>
            <form method="POST" action="{{ route('claims.confirm.respond', $token) }}" class="card p-6 mt-6 space-y-4">
                @csrf
                <div><label class="field-label" for="note">Anything Horsefax should know (optional)</label><textarea id="note" name="note" rows="3" class="field"></textarea></div>
                <div class="flex flex-wrap gap-3">
                    <button name="response" value="confirmed" class="btn-forest">Yes, I sold this horse to them</button>
                    <button name="response" value="denied" class="btn-ghost">No / I don't know about this</button>
                </div>
            </form>
            <p class="hint mt-4">No account is needed. Your answer goes only to Horsefax staff reviewing the claim.</p>
        @endif
    </div>
</x-layouts.site>
