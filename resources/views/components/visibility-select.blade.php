@props(['name' => 'visibility', 'value' => 'private', 'label' => 'Who can see this'])
<div>
    <label class="field-label" for="{{ $name }}-{{ $attributes->get('id-suffix', 'v') }}">{{ $label }}</label>
    <select name="{{ $name }}" id="{{ $name }}-{{ $attributes->get('id-suffix', 'v') }}" class="field">
        @foreach (\App\Support\Record::VISIBILITY as $k => $v)
            <option value="{{ $k }}" @selected(old($name, $value) === $k)>{{ $v }}</option>
        @endforeach
    </select>
</div>
