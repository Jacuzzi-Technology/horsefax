@props(['eyebrow' => null, 'title', 'lede' => null])
<section class="bg-forest text-cream relative overflow-hidden">
    <div class="absolute -right-24 -bottom-24 w-[420px] aspect-[782/899] bg-[url('/img/x-mark.svg')] bg-contain bg-no-repeat opacity-[0.07] pointer-events-none" aria-hidden="true"></div>
    <div class="wrap relative py-16 sm:py-20">
        @if ($eyebrow)<p class="label text-gold-soft mb-4">{{ $eyebrow }}</p>@endif
        <h1 class="text-cream text-[40px] sm:text-[54px] leading-[1.06] max-w-[16em]">{{ $title }}</h1>
        @if ($lede)<p class="mt-5 text-[20px] sm:text-[22px] text-cream/80 max-w-[34em] italic">{{ $lede }}</p>@endif
        {{ $slot }}
    </div>
</section>
