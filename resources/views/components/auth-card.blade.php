@props(['title', 'lede' => null])
<x-layouts.site :title="$title" robots="noindex, nofollow">
    <div class="wrap py-14 sm:py-20">
        <div class="max-w-[460px] mx-auto">
            <img src="/img/hf-mark.svg" alt="" class="w-[84px] mx-auto mb-6">
            <h1 class="text-[34px] text-center">{{ $title }}</h1>
            @if ($lede)<p class="text-center text-[17px] text-muted mt-2">{{ $lede }}</p>@endif
            <div class="card p-6 sm:p-8 mt-8">{{ $slot }}</div>
        </div>
    </div>
</x-layouts.site>
