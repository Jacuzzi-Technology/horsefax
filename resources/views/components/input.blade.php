@props(['name', 'label', 'type' => 'text', 'value' => null, 'hint' => null, 'required' => false])
<div>
    <label class="field-label" for="f-{{ $name }}">{{ $label }}@if($required) <span class="text-gold-dark">*</span>@endif</label>
    <input type="{{ $type }}" name="{{ $name }}" id="f-{{ $name }}" value="{{ old($name, $value) }}" @required($required) {{ $attributes->merge(['class' => 'field']) }}>
    @if ($hint)<p class="hint">{{ $hint }}</p>@endif
    @error($name)<p class="error">{{ $message }}</p>@enderror
</div>
