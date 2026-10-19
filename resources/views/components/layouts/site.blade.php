@props(['title' => null, 'description' => 'Horsefax brings a horse\'s history together in one record: registration, pedigree, competition, sales and ownership, with every fact labeled by where it came from.', 'robots' => null, 'canonical' => null])
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title.' | Horsefax' : 'Horsefax | Because Every Horse Has A History.' }}</title>
    <meta name="description" content="{{ $description }}">
    @if ($robots)<meta name="robots" content="{{ $robots }}">@endif
    @if ($canonical)<link rel="canonical" href="{{ $canonical }}">@endif
    <meta property="og:site_name" content="Horsefax">
    <meta property="og:title" content="{{ $title ?? 'Horsefax' }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:image" content="{{ url('/img/og-card.png') }}">
    <meta name="theme-color" content="#203123">
    <link rel="icon" href="/favicon.ico" sizes="48x48">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    {{ Vite::fonts() }}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen flex flex-col">
<a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 btn-gold">Skip to content</a>
<header class="bg-forest text-cream print:hidden">
    <div class="wrap flex items-center justify-between gap-6 py-4">
        <a href="{{ route('home') }}" class="shrink-0" aria-label="Horsefax home"><img src="/img/wordmark-reverse.svg" alt="Horsefax" class="h-[26px] sm:h-[30px] w-auto"></a>
        <nav class="hidden lg:flex items-center gap-6 whitespace-nowrap font-sans text-[11px] font-semibold tracking-[0.16em] uppercase" aria-label="Main">
            <a href="{{ route('search') }}" class="no-underline hover:text-gold-soft">Search Horses</a>
            <a href="{{ route('how-it-works') }}" class="no-underline hover:text-gold-soft">How It Works</a>
            <a href="{{ route('for-owners') }}" class="no-underline hover:text-gold-soft">For Owners</a>
            <a href="{{ route('for-professionals') }}" class="no-underline hover:text-gold-soft">For Professionals</a>
            <a href="{{ route('partners') }}" class="no-underline hover:text-gold-soft">Partners</a>
        </nav>
        <div class="flex items-center gap-4 whitespace-nowrap">
            @auth
                @if (auth()->user()->isStaff())<a href="{{ route('admin.queue') }}" class="hidden sm:inline font-sans text-[11px] font-semibold tracking-[0.16em] uppercase no-underline text-gold-soft hover:text-cream">Staff</a>@endif
                <a href="{{ route('dashboard') }}" class="font-sans text-[11px] font-semibold tracking-[0.16em] uppercase no-underline hover:text-gold-soft">My Horses</a>
                <a href="{{ route('account') }}" class="hidden sm:inline font-sans text-[11px] font-semibold tracking-[0.16em] uppercase no-underline text-cream/70 hover:text-cream">Account</a>
            @else
                <a href="{{ route('login') }}" class="font-sans text-[11px] font-semibold tracking-[0.16em] uppercase no-underline hover:text-gold-soft">Sign In</a>
                <a href="{{ route('register') }}" class="btn-gold btn-small hidden sm:inline-flex">Create a Record</a>
            @endauth
            <details class="lg:hidden relative">
                <summary class="list-none cursor-pointer p-2 -mr-2" aria-label="Menu"><svg width="22" height="22" viewBox="0 0 22 22" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 6h16M3 11h16M3 16h16"/></svg></summary>
                <div class="absolute right-0 top-11 z-40 w-64 bg-forest border border-cream/15 shadow-xl p-4 flex flex-col gap-3 font-sans text-[12px] font-semibold tracking-[0.14em] uppercase">
                    <a href="{{ route('search') }}" class="no-underline">Search Horses</a>
                    <a href="{{ route('how-it-works') }}" class="no-underline">How It Works</a>
                    <a href="{{ route('for-owners') }}" class="no-underline">For Owners</a>
                    <a href="{{ route('for-professionals') }}" class="no-underline">For Professionals</a>
                    <a href="{{ route('partners') }}" class="no-underline">Partners</a>
                    @auth
                        <a href="{{ route('account') }}" class="no-underline">Account</a>
                        <form method="POST" action="{{ route('logout') }}">@csrf<button class="uppercase tracking-[0.14em] cursor-pointer">Sign out</button></form>
                    @else
                        <a href="{{ route('register') }}" class="no-underline text-gold-soft">Create a Record</a>
                    @endauth
                </div>
            </details>
        </div>
    </div>
</header>

@if (session('status'))
    <div class="bg-gold/15 border-b border-gold/30" role="status"><div class="wrap py-3 font-sans text-[14px] text-ink">{{ session('status') }}</div></div>
@endif

<main id="main" class="flex-1">{{ $slot }}</main>

<footer class="bg-forest text-cream/80 mt-auto print:hidden">
    <div class="wrap py-14 grid gap-10 md:grid-cols-[1.4fr_1fr_1fr_1fr]">
        <div>
            <img src="/img/wordmark-reverse.svg" alt="Horsefax" class="w-[180px] h-auto">
            <p class="label text-[9.5px] tracking-[0.3em] text-cream/75 mt-3">Because Every Horse Has A History.™</p>
            <p class="mt-6 text-[16px] text-cream/70 max-w-[22em]">Discovering, preserving and connecting the records that tell a horse's story, from registration and competition to ownership and beyond.</p>
        </div>
        <div class="font-sans text-[13px] space-y-2.5">
            <p class="label text-gold-soft text-[10px]">Horsefax</p>
            <a href="{{ route('search') }}" class="block no-underline hover:text-cream">Search horses</a>
            <a href="{{ route('how-it-works') }}" class="block no-underline hover:text-cream">How it works</a>
            <a href="{{ route('sample') }}" class="block no-underline hover:text-cream">Sample report</a>
            <a href="{{ route('about') }}" class="block no-underline hover:text-cream">About</a>
        </div>
        <div class="font-sans text-[13px] space-y-2.5">
            <p class="label text-gold-soft text-[10px]">For</p>
            <a href="{{ route('for-owners') }}" class="block no-underline hover:text-cream">Owners & breeders</a>
            <a href="{{ route('for-professionals') }}" class="block no-underline hover:text-cream">Vets, farriers & trainers</a>
            <a href="{{ route('partners') }}" class="block no-underline hover:text-cream">Partners & affiliates</a>
            <a href="{{ route('register') }}" class="block no-underline hover:text-cream">Create a record</a>
        </div>
        <div class="font-sans text-[13px] space-y-2.5">
            <p class="label text-gold-soft text-[10px]">Trust</p>
            <a href="{{ route('record-corrections') }}" class="block no-underline hover:text-cream">Record corrections</a>
            <a href="{{ route('factual-record-policy') }}" class="block no-underline hover:text-cream">Factual record policy</a>
            <a href="{{ route('privacy') }}" class="block no-underline hover:text-cream">Privacy</a>
            <a href="{{ route('terms') }}" class="block no-underline hover:text-cream">Terms of use</a>
            <a href="{{ route('contact') }}" class="block no-underline hover:text-cream">Contact</a>
        </div>
    </div>
    <div class="border-t border-cream/10">
        <div class="wrap py-6 font-sans text-[11px] text-cream/50 flex flex-col sm:flex-row gap-2 justify-between">
            <p>© {{ now()->year }} {{ config('horsefax.operator') }}. All rights reserved.</p>
            <p>Horsefax™ and Because Every Horse Has A History.™ are trademarks of {{ config('horsefax.operator') }}.</p>
        </div>
    </div>
</footer>
</body>
</html>
