@props(['parts', 'dark' => false])
<p {{ $attributes->merge(['class' => 'font-sans text-[13px] '.($dark ? 'text-cream/80' : 'text-charcoal/80')]) }} aria-label="What has been established">
    @foreach ($parts as $p)<span class="whitespace-nowrap">{{ $p }}</span>@if (! $loop->last)<span class="mx-2 {{ $dark ? 'text-gold-soft' : 'text-gold' }}">·</span>@endif @endforeach
</p>
