@php use App\Support\Record; @endphp
<x-layouts.site title="Edit identity" robots="noindex, nofollow">
    <div class="wrap py-12 max-w-[820px]">
        <a href="{{ route('horses.show', $horse) }}" class="font-sans text-[13px] text-gold-dark">← {{ $horse->displayName() }}</a>
        <h1 class="text-[36px] mt-3">Edit identity details</h1>
        <p class="hint">Every change is recorded in the record history with the old and new values.</p>
        <form method="POST" action="{{ route('horses.update', $horse) }}" class="card p-6 mt-6 space-y-5">
            @csrf @method('PUT')
            <div class="grid sm:grid-cols-2 gap-5"><x-input name="registered_name" label="Registered name" :value="$horse->registered_name" /><x-input name="barn_name" label="Barn name" :value="$horse->barn_name" /></div>
            @error('barn_name')<p class="error">{{ $message }}</p>@enderror
            <div class="grid sm:grid-cols-3 gap-5">
                <x-choice-select name="breed" label="Breed" :options="Record::BREEDS" :value="$horse->breed" />
                <div><label class="field-label" for="f-sex">Sex</label><select id="f-sex" name="sex" class="field"><option value="">Unknown</option>@foreach (Record::SEXES as $k => $l)<option value="{{ $k }}" @selected(old('sex', $horse->sex) === $k)>{{ $l }}</option>@endforeach</select></div>
                <x-choice-select name="color" label="Color" :options="Record::COLORS" :value="$horse->color" />
            </div>
            <div class="grid sm:grid-cols-3 gap-5">
                <div><label class="field-label" for="f-bp">Foaling date</label><select id="f-bp" name="birth_precision" class="field">@foreach (['unknown' => 'Unknown', 'year' => 'Year only', 'day' => 'Exact date'] as $k => $l)<option value="{{ $k }}" @selected(old('birth_precision', $horse->birth_precision) === $k)>{{ $l }}</option>@endforeach</select></div>
                <x-input name="birth_year" label="Foaling year" :value="$horse->birth_year" />
                <x-input name="birth_date" type="date" label="Foal date" :value="$horse->birth_date?->format('Y-m-d')" />
            </div>
            <div class="grid sm:grid-cols-2 gap-5"><x-height-select :value="$horse->height_hands" /><x-input name="height_measured_on" type="date" label="Measured on" :value="$horse->height_measured_on?->format('Y-m-d')" /></div>
            <div><label class="field-label" for="f-markings">Markings & distinguishing features</label><textarea id="f-markings" name="markings" rows="2" class="field">{{ old('markings', $horse->markings) }}</textarea></div>
            <button class="btn-gold">Save</button>
        </form>
    </div>
</x-layouts.site>
