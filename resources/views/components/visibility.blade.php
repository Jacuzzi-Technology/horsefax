@props(['value'])
<span {{ $attributes->merge(['class' => 'chip-vis']) }}>{{ ['private' => 'Private', 'shared' => 'Shared', 'public' => 'Public'][$value] ?? $value }}</span>
