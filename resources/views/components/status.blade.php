@props(['status'])
@php
    $class = match ($status) {
        'independently_verified' => 'chip-verified', 'document_supplied' => 'chip-doc', 'disputed' => 'chip-disputed',
        'superseded' => 'chip-superseded', default => 'chip-self',
    };
@endphp
<span {{ $attributes->merge(['class' => $class]) }} title="How Horsefax knows this">
    @if ($status === 'independently_verified')<svg width="10" height="10" viewBox="0 0 10 10" aria-hidden="true"><path d="M1.5 5.2 4 7.6 8.6 2.4" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>@endif
    {{ \App\Support\Record::STATUS[$status] ?? $status }}
</span>
