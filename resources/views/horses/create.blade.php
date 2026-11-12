@php
    use App\Support\Record;
    $old = $old ?? [];
    $v = fn ($k, $d = null) => old($k, $old[$k] ?? $d);
@endphp
<x-layouts.site title="Add a horse" robots="noindex, nofollow">
    <div class="wrap py-12 max-w-[860px]">
        <p class="label text-gold">New Horsefax record</p>
        <h1 class="text-[38px] mt-2">Add a horse</h1>
        <p class="mt-2 text-[18px] text-muted">Enter what identifies the horse. You can add history, pedigree, documents and photos next. The record starts as <strong>identity unverified</strong> until it has been cross-checked.</p>

        @isset($matches)
            <div class="card p-6 mt-8 border-gold/60">
                <h2 class="text-[24px]">This horse may already be on Horsefax</h2>
                <p class="mt-2 text-[17px]">We found {{ $matches->count() === 1 ? 'a record' : 'records' }} with the same registered name or number. If one of them is your horse, claim it instead of creating a duplicate.</p>
                <div class="mt-4 space-y-3">
                    @foreach ($matches as $m)
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border border-line rounded-[2px] p-4 bg-white">
                            <div><p class="text-[19px] text-ink">{{ $m->displayName() }}</p><p class="font-sans text-[13px] text-muted">{{ $m->horsefax_id }} · {{ collect([$m->breed, $m->sex ? ucfirst($m->sex) : null, $m->birth_year ? 'Foaled '.$m->birth_year : null])->filter()->join(' · ') }}</p></div>
                            <a href="{{ route('horses.claim', $m) }}" class="btn-ghost btn-small">This is my horse: claim it</a>
                        </div>
                    @endforeach
                </div>
                <p class="mt-4 font-sans text-[13px] text-muted">Not the same horse? Check the details below and choose <strong>Create a separate record</strong>.</p>
            </div>
        @endisset

        <form method="POST" action="{{ route('horses.store') }}" class="mt-8 space-y-8">
            @csrf
            @isset($matches)<input type="hidden" name="confirm_new" value="1">@endisset

            <section class="card p-6 space-y-5">
                <h2 class="text-[24px]">Names</h2>
                <div class="grid sm:grid-cols-2 gap-5">
                    <x-input name="registered_name" label="Registered name" :value="$v('registered_name')" hint="Exactly as on the papers." />
                    <x-input name="barn_name" label="Barn name" :value="$v('barn_name')" />
                </div>
                @error('barn_name')<p class="error">{{ $message }}</p>@enderror
            </section>

            <section class="card p-6 space-y-5">
                <h2 class="text-[24px]">Registration & microchip</h2>
                @php $regs = old('registrations', $old['registrations'] ?? []) ?: [['registry' => '', 'number' => '']]; @endphp
                <datalist id="registry-list">@foreach (Record::REGISTRIES as $k => $l)@continue($k === 'Other')<option value="{{ $l }}">@endforeach</datalist>
                <div class="space-y-3" data-registrations>
                    @foreach ($regs as $i => $r)
                        <div class="grid sm:grid-cols-[1.3fr_1fr_auto] gap-3 items-end" data-registration-row>
                            <div><label class="field-label">Registry or association</label><input name="registrations[{{ $i }}][registry]" value="{{ $r['registry'] ?? '' }}" list="registry-list" class="field" placeholder="Start typing, e.g. AQHA" autocomplete="off"></div>
                            <div><label class="field-label">Registration number</label><input name="registrations[{{ $i }}][number]" value="{{ $r['number'] ?? '' }}" class="field"></div>
                            <button type="button" class="btn-ghost btn-small mb-1" data-remove-registration aria-label="Remove this registration">Remove</button>
                        </div>
                    @endforeach
                </div>
                @error('registrations.*.number')<p class="error">Enter the registration number for each registry you add.</p>@enderror
                <button type="button" class="btn-ghost btn-small" data-add-registration>+ Add another registration</button>
                <p class="hint">A horse can be registered with several associations, for example AQHA, APHA and ARHA. Add each one.</p>
                <div class="border-l-2 border-gold pl-4">
                    <x-input name="microchip" label="Microchip number" :value="$v('microchip')" inputmode="numeric" hint="A microchip is the most reliable way to tell one horse from another. It stays private and is always masked when shared." />
                </div>
            </section>

            <section class="card p-6 space-y-5">
                <h2 class="text-[24px]">Description</h2>
                <div class="grid sm:grid-cols-3 gap-5">
                    <x-choice-select name="breed" label="Breed" :options="Record::BREEDS" :value="$v('breed')" />
                    <div>
                        <label class="field-label" for="f-sex">Sex</label>
                        <select id="f-sex" name="sex" class="field"><option value="">Unknown</option>@foreach (Record::SEXES as $k => $l)<option value="{{ $k }}" @selected($v('sex') === $k)>{{ $l }}</option>@endforeach</select>
                    </div>
                    <x-choice-select name="color" label="Color" :options="Record::COLORS" :value="$v('color')" />
                </div>
                <div class="grid sm:grid-cols-3 gap-5">
                    <div>
                        <label class="field-label" for="f-bp">Foaling date</label>
                        <select id="f-bp" name="birth_precision" class="field">
                            <option value="unknown" @selected($v('birth_precision', 'unknown') === 'unknown')>Unknown</option>
                            <option value="year" @selected($v('birth_precision') === 'year')>Year only</option>
                            <option value="day" @selected($v('birth_precision') === 'day')>Exact date</option>
                        </select>
                    </div>
                    <x-input name="birth_year" label="Foaling year" :value="$v('birth_year')" inputmode="numeric" maxlength="4" />
                    <x-input name="birth_date" type="date" label="Foal date" :value="$v('birth_date')" />
                </div>
                <div class="grid sm:grid-cols-2 gap-5">
                    <x-height-select :value="$v('height_hands')" />
                    <x-input name="height_measured_on" type="date" label="Measured on" :value="$v('height_measured_on')" />
                </div>
                <div>
                    <label class="field-label" for="f-markings">Markings & distinguishing features</label>
                    <textarea id="f-markings" name="markings" rows="2" class="field">{{ $v('markings') }}</textarea>
                </div>
                <x-input name="breeder" label="Breeder (as the registry records it)" :value="$v('breeder')" />
            </section>

            <label class="flex items-start gap-2 font-sans text-[14px]"><input type="checkbox" name="attest" value="1" class="accent-gold mt-1" @checked(old('attest'))> <span>The information I enter is true to the best of my knowledge, and I own this horse or act for its owner.</span></label>
            @error('attest')<p class="error">{{ $message }}</p>@enderror
            <button class="btn-gold">{{ isset($matches) ? 'Create a separate record' : 'Create record' }}</button>
        </form>
    </div>
</x-layouts.site>
