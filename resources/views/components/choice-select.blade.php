@props(['name', 'label', 'options', 'value' => null])
@php
    $current = old($name.'_choice') === '__other' ? '__other' : old($name, $value);
    $isOther = $current === '__other' || ($current !== null && $current !== '' && ! in_array($current, $options, true));
    $otherText = old($name.'_other', $isOther && $current !== '__other' ? $current : '');
@endphp
<div data-choice>
    <label class="field-label" for="f-{{ $name }}">{{ $label }}</label>
    <select id="f-{{ $name }}" name="{{ $name }}_choice" class="field" data-choice-select>
        <option value="">Not known</option>
        @foreach ($options as $opt)<option value="{{ $opt }}" @selected(! $isOther && $current === $opt)>{{ $opt }}</option>@endforeach
        <option value="__other" @selected($isOther)>Other (type it in)</option>
    </select>
    <input type="text" name="{{ $name }}_other" value="{{ $otherText }}" class="field mt-2" placeholder="Type the {{ strtolower($label) }}" aria-label="Other {{ strtolower($label) }}" data-choice-other @if (! $isOther) hidden @endif>
    @error($name)<p class="error">{{ $message }}</p>@enderror
</div>
