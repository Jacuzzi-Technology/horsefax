{{-- Pedigree entry, one tab per generation. Each later generation is grouped under the horse whose parents it holds. --}}
@php
    use App\Support\Record;
    $positions = Record::pedigreePositions();
    $byGen = collect($positions)->groupBy(fn ($l, $p) => strlen($p), true);
    $filled = fn ($g) => $byGen[$g]->keys()->filter(fn ($p) => old("lines.$p.name", $pedigree->get($p)?->name))->count();
@endphp
<form method="POST" action="{{ route('record.pedigree', $horse) }}" class="mt-5" data-pedigree-form data-lookup="{{ route('pedigree.lookup') }}" data-ancestry="{{ route('pedigree.ancestry') }}">
    @csrf
    <p class="hint">Start typing a name. If Horsefax already knows the horse, pick it from the list and its own pedigree fills in behind it, in the later tabs. Change anything before saving. Horses new to Horsefax get their own record, marked identity unverified.</p>

    <div class="mt-5 flex flex-wrap gap-1 border-b border-line" role="tablist" aria-label="Generations">
        @foreach (Record::GENERATION_NAMES as $g => $name)
            <button type="button" role="tab" data-ped-tab="{{ $g }}" aria-selected="{{ $g === 1 ? 'true' : 'false' }}"
                class="px-4 py-2.5 font-sans text-[11.5px] font-semibold tracking-[0.1em] uppercase rounded-t-[3px] border border-b-0 cursor-pointer {{ $g === 1 ? 'bg-paper border-line text-ink' : 'border-transparent text-muted hover:text-ink' }}">
                {{ $name }} <span class="ml-1 font-normal text-gold-dark" data-ped-count="{{ $g }}">{{ $filled($g) }}/{{ 2 ** $g }}</span>
            </button>
        @endforeach
    </div>

    @foreach ($byGen as $g => $group)
        <div class="pt-5 space-y-5" role="tabpanel" data-ped-panel="{{ $g }}" @if ($g !== 1) hidden @endif>
            @foreach ($group->keys()->chunk(2) as $pair)
                @php $child = $g > 1 ? substr($pair->first(), 0, -1) : null; @endphp
                <div class="{{ $g > 1 ? 'card p-4' : 'space-y-3' }}">
                    @if ($child)
                        <p class="font-sans text-[12px] text-muted mb-3">Parents of the <strong class="text-ink">{{ strtolower(Record::pedigreeLabel($child)) }}</strong>: <span class="text-ink" data-ped-child="{{ $child }}">{{ old("lines.$child.name", $pedigree->get($child)?->name) ?: 'not entered yet' }}</span></p>
                    @endif
                    <div class="space-y-3">
                        @foreach ($pair as $pos)
                            @php $e = $pedigree->get($pos); $label = $positions[$pos]; @endphp
                            <div class="grid sm:grid-cols-[150px_1fr_130px_130px_80px] gap-2 items-end rounded-[3px] transition-colors" data-ped-row="{{ $pos }}">
                                <p class="font-sans text-[12px] font-semibold tracking-[0.06em] uppercase text-ink pb-3">{{ str_ends_with($pos, 's') ? 'Sire' : 'Dam' }}<span class="block normal-case tracking-normal font-normal text-muted text-[11px]">{{ $label }}</span><span class="block normal-case tracking-normal font-normal text-gold-dark text-[11px]" data-ped-note></span></p>
                                <div class="relative">
                                    <input name="lines[{{ $pos }}][name]" value="{{ old("lines.$pos.name", $e?->name) }}" class="field" placeholder="Name" aria-label="{{ $label }} name" autocomplete="off" data-field="name" role="combobox" aria-autocomplete="list" aria-expanded="false">
                                    <ul class="absolute z-30 left-0 right-0 top-full mt-1 bg-white border border-line rounded-[3px] shadow-xl max-h-80 overflow-auto" role="listbox" hidden data-ped-list></ul>
                                </div>
                                <select name="lines[{{ $pos }}][registry]" class="field" aria-label="{{ $label }} registry" data-field="registry"><option value="">Registry</option>@foreach (Record::REGISTRIES as $k => $l)<option value="{{ $k }}" @selected(old("lines.$pos.registry", $e?->registry) === $k)>{{ $k }}</option>@endforeach</select>
                                <input name="lines[{{ $pos }}][registration_number]" value="{{ old("lines.$pos.registration_number", $e?->registration_number) }}" class="field" placeholder="Reg. #" aria-label="{{ $label }} registration number" data-field="registration_number">
                                <input name="lines[{{ $pos }}][foal_year]" value="{{ old("lines.$pos.foal_year", $e?->foal_year) }}" class="field" placeholder="Year" inputmode="numeric" maxlength="4" aria-label="{{ $label }} foaling year" data-field="foal_year">
                                <input type="hidden" name="lines[{{ $pos }}][color]" value="{{ old("lines.$pos.color", $e?->color) }}" data-field="color">
                                <input type="hidden" name="lines[{{ $pos }}][linked_horse_id]" value="{{ old("lines.$pos.linked_horse_id", $e?->linked_horse_id) }}" data-field="linked_horse_id">
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
            <div class="flex flex-wrap items-center gap-3 pt-1">
                @if ($g > 1)<button type="button" class="btn-ghost btn-small" data-ped-go="{{ $g - 1 }}">← {{ Record::GENERATION_NAMES[$g - 1] }}</button>@endif
                @if ($g < Record::PEDIGREE_GENERATIONS)<button type="button" class="btn-ghost btn-small" data-ped-go="{{ $g + 1 }}">{{ Record::GENERATION_NAMES[$g + 1] }} →</button>@endif
            </div>
        </div>
    @endforeach

    <div class="mt-6 pt-5 border-t border-line flex flex-wrap items-end gap-4">
        <div class="w-full max-w-[360px]"><x-visibility-select value="{{ $pedigree->first()?->visibility ?? 'public' }}" id-suffix="ped" label="Who can see the pedigree" /></div>
        <button class="btn-forest">Save pedigree</button>
    </div>
</form>
