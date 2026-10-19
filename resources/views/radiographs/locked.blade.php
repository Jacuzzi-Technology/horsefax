<x-layouts.site title="Radiographs · {{ $horse->displayName() }}" robots="noindex, nofollow">
    <div class="wrap py-12 max-w-[680px]">
        <a href="{{ route('horse.public', $horse) }}" class="font-sans text-[13px] text-gold-dark">← {{ $horse->displayName() }}</a>
        <h1 class="text-[34px] mt-2">{{ $set->title() }}</h1>
        <p class="font-sans text-[14px] text-muted mt-1">{{ $set->attribution() }}</p>
        @if (session('status'))<p class="mt-4 font-sans text-[14px] text-forest">{{ session('status') }}</p>@endif
        <div class="card p-6 mt-6">
            @guest
                <p class="text-[18px]">Sign in or create a free account to {{ $set->access === 'on_request' ? 'ask the owner for access to' : 'view' }} these radiographs.</p>
                <div class="mt-4 flex gap-3"><a href="{{ route('register') }}" class="btn-gold btn-small">Create a free account</a><a href="{{ route('login') }}" class="btn-ghost btn-small">Sign in</a></div>
            @else
                @if ($set->access === 'on_request')
                    @if ($mine && $mine->status === 'requested')
                        <p class="text-[18px]">Your request is with the owner. You'll get an email when they decide.</p>
                    @elseif ($mine && $mine->status === 'denied')
                        <p class="text-[18px]">The owner declined this request. You can contact the seller directly.</p>
                    @else
                        <p class="text-[18px]">The owner shares these radiographs on request.</p>
                        <form method="POST" action="{{ route('radiographs.request', [$horse, $set]) }}" class="mt-4 space-y-3">
                            @csrf
                            <div><label class="field-label" for="message">Message to the owner (optional)</label><textarea id="message" name="message" rows="3" class="field" placeholder="e.g. I'm interested in this horse and my vet would like to review them."></textarea></div>
                            <button class="btn-forest btn-small">Request access</button>
                        </form>
                    @endif
                @else
                    <p class="text-[18px]">The owner's sale access for these radiographs has ended. Contact the seller.</p>
                @endif
            @endguest
        </div>
    </div>
</x-layouts.site>
