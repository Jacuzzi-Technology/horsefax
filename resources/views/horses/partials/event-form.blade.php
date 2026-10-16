@php
    use App\Support\Record;
    $ev = $event;
    $val = fn ($k, $d = null) => old($k, $ev?->{$k} ?? $d);
    $cat = $val('category', 'health');
    $pro = $pro ?? false; // a professional contributor: no prices, and the owner sets visibility
@endphp
<details class="card p-6" @if ($ev || $errors->any()) open @endif>
    <summary class="cursor-pointer text-[20px] text-ink">{{ $ev ? 'Correct this entry' : 'Add a history entry' }}</summary>
    <form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="mt-5 space-y-5" data-event-form>
        @csrf
        <div class="grid sm:grid-cols-3 gap-4">
            <div>
                <label class="field-label" for="e-cat">Category</label>
                <select id="e-cat" name="category" class="field" data-category>
                    @foreach (Record::EVENT_CATEGORIES as $k => $l)<option value="{{ $k }}" data-default-vis="{{ Record::DEFAULT_EVENT_VISIBILITY[$k] }}" @selected($cat === $k)>{{ $l }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="field-label" for="e-type">Type</label>
                <select id="e-type" name="type" class="field" data-type>
                    <option value="">—</option>
                    @foreach (Record::EVENT_TYPES as $c => $types)
                        @foreach ($types as $k => $l)<option value="{{ $k }}" data-cat="{{ $c }}" @selected($val('type') === $k && $cat === $c)>{{ $l }}</option>@endforeach
                    @endforeach
                </select>
            </div>
            <x-input name="title" label="Title" :value="$val('title')" required />
        </div>
        <div class="grid sm:grid-cols-3 gap-4">
            <x-input name="occurred_on" type="date" label="Date" :value="$ev?->occurred_on?->format('Y-m-d')" />
            <div>
                <label class="field-label" for="e-prec">Date accuracy</label>
                <select id="e-prec" name="date_precision" class="field">@foreach (['day' => 'Exact day', 'month' => 'Month', 'year' => 'Year', 'unknown' => 'Unknown'] as $k => $l)<option value="{{ $k }}" @selected($val('date_precision', 'day') === $k)>{{ $l }}</option>@endforeach</select>
            </div>
            <x-input name="provider" label="Vet / farrier / trainer / show" :value="$val('provider')" />
        </div>

        <div class="grid sm:grid-cols-3 gap-4" data-for="health care">
            <x-input name="result" label="Result (e.g. Coggins negative)" :value="$val('result')" />
            <x-input name="expires_on" type="date" label="Expires (Coggins, certificates)" :value="$ev?->expires_on?->format('Y-m-d')" />
        </div>

        <div class="grid sm:grid-cols-3 gap-4" data-for="competition">
            <x-input name="organization" label="Association" :value="$val('organization')" placeholder="AQHA, NRHA, open show…" />
            <x-input name="event_name" label="Show / event" :value="$val('event_name')" />
            <x-input name="class_name" label="Class" :value="$val('class_name')" />
            <x-input name="placing" label="Placing" :value="$val('placing')" placeholder="1, 3T (tied)…" />
            <x-input name="score" label="Score / time" :value="$val('score')" />
            <x-input name="points" label="Points" :value="$val('points')" inputmode="decimal" />
            <x-input name="earnings" label="Earnings ($)" :value="$ev?->earnings_cents !== null && $ev ? $ev->earnings_cents / 100 : null" inputmode="decimal" />
            <x-input name="rider" label="Exhibitor / rider" :value="$val('rider')" />
            <x-input name="discipline" label="Event type" :value="$val('discipline')" placeholder="Reining, cutting, ranch riding…" />
            <x-input name="division" label="Division" :value="$val('division')" placeholder="Open, non-pro, novice…" />
            <x-input name="round" label="Round" :value="$val('round')" placeholder="Finals, go-round 1…" />
        </div>

        @unless ($pro)
        <div class="grid sm:grid-cols-3 gap-4" data-for="ownership">
            <x-input name="amount" label="Price ($, optional)" :value="$ev?->amount_cents !== null && $ev ? $ev->amount_cents / 100 : null" inputmode="decimal" />
            <div>
                <label class="field-label" for="e-amtvis">Who can see the price</label>
                <select id="e-amtvis" name="amount_visibility" class="field">@foreach (Record::VISIBILITY as $k => $l)<option value="{{ $k }}" @selected($val('amount_visibility', 'private') === $k)>{{ $l }}</option>@endforeach</select>
            </div>
            <x-input name="sale_venue" label="Where (private sale, auction & lot)" :value="$val('sale_venue')" />
        </div>
        @endunless

        <div>
            <label class="field-label" for="e-details">Details</label>
            <textarea id="e-details" name="details" rows="3" class="field">{{ $val('details') }}</textarea>
        </div>
        <div class="grid sm:grid-cols-3 gap-4">
            <div class="sm:col-span-1"><label class="field-label" for="e-doc">Supporting document (optional)</label><input id="e-doc" type="file" name="document" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic" class="field"></div>
            <div><label class="field-label" for="e-dockind">Document kind</label><select id="e-dockind" name="document_kind" class="field">@foreach (Record::DOCUMENT_KINDS as $k => $l)@continue($k === 'photo')<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
            @unless ($pro)<x-visibility-select :value="$val('visibility', Record::DEFAULT_EVENT_VISIBILITY[$cat])" id-suffix="event" />@endunless
        </div>
        <p class="hint">@if ($pro)Your entry is added in your name and starts private; the owner decides who else sees it.@else Health entries start private.@endif With a document attached, the entry is labeled "Document supplied."</p>
        <button class="btn-forest">{{ $button }}</button>
    </form>
</details>
