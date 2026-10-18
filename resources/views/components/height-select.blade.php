@props(['value' => null])
@php $v = old('height_hands', $value !== null && $value !== '' ? rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.') : ''); if ($v !== '' && ! str_contains($v, '.')) { $v .= '.0'; } @endphp
<div>
    <label class="field-label" for="f-height">Height (hands)</label>
    <select id="f-height" name="height_hands" class="field">
        <option value="">Not measured</option>
        @foreach (\App\Support\Record::heights() as $h)<option value="{{ $h }}" @selected($v === $h)>{{ $h }} hh</option>@endforeach
    </select>
    @error('height_hands')<p class="error">{{ $message }}</p>@enderror
</div>
