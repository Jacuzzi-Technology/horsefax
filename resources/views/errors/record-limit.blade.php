<x-layouts.site title="Please slow down" robots="noindex, nofollow">
    <div class="wrap py-16 max-w-[640px]">
        <h1 class="text-[34px]">{{ $daily ? 'You\'ve reached today\'s limit' : 'Please slow down' }}</h1>
        @if ($daily)
            <p class="mt-4 text-[18px]">You've opened {{ number_format($limit) }} horse records today, the daily limit for {{ auth()->check() ? 'your plan' : 'visitors who aren\'t signed in' }}. The limit resets at midnight UTC.</p>
            @guest<p class="mt-3 text-[18px]">A free Horsefax account raises the limit. <a href="{{ route('register') }}" class="text-gold-dark">Create a free account</a> or <a href="{{ route('login') }}" class="text-gold-dark">sign in</a>.</p>@endguest
        @else
            <p class="mt-4 text-[18px]">That's a lot of records in a short time. Please wait a minute and try again.</p>
        @endif
        <p class="hint mt-6">Horsefax records may not be scraped or copied into another database (see our <a href="{{ route('terms') }}" class="text-gold-dark">terms</a>). Partners who need data access can <a href="{{ route('contact') }}" class="text-gold-dark">ask about a data licence</a>.</p>
    </div>
</x-layouts.site>
