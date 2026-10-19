@php use App\Models\RadiographSet; @endphp
<x-layouts.site title="Radiographs · {{ $horse->displayName() }}" robots="noindex, nofollow">
    <div class="wrap py-12 max-w-[920px]">
        <a href="{{ route('horses.show', $horse) }}" class="font-sans text-[13px] text-gold-dark">← {{ $horse->displayName() }}</a>
        <h1 class="text-[38px] mt-2">Radiographs</h1>
        <p class="mt-3 text-[18px] max-w-[40em]">Sale and pre-purchase radiographs become a dated part of this horse's record, credited to the clinic that took them. They're private until you open them to buyers: on request, for a sale period, or in a report link.</p>
        @if (session('status'))<p class="mt-4 font-sans text-[14px] text-forest">{{ session('status') }}</p>@endif

        <div class="mt-8 space-y-3">
            @forelse ($sets as $s)
                <a href="{{ route('radiographs.manage', [$horse, $s]) }}" class="card p-5 flex flex-wrap justify-between gap-3 no-underline hover:border-gold">
                    <div><p class="text-[20px] text-ink">{{ $s->title() }}</p><p class="font-sans text-[13px] text-muted">{{ $s->attribution() }} · {{ $s->files_count }} {{ Str::plural('file', $s->files_count) }}</p></div>
                    <div class="font-sans text-[13px] text-right"><p>{{ ['private' => 'Private', 'on_request' => 'On request', 'sale' => $s->saleOpen() ? 'Sale access until '.$s->sale_access_until?->format('M j') : 'Sale access ended'][$s->access] }}</p>
                        <p class="text-muted">{{ $s->views_log_count }} buyer {{ Str::plural('view', $s->views_log_count) }}@if ($s->pending_count) · <span class="text-gold-dark font-semibold">{{ $s->pending_count }} waiting</span>@endif</p></div>
                </a>
            @empty
                <p class="hint">No radiographs yet.</p>
            @endforelse
        </div>

        @if ($canAdd)
            <form method="POST" action="{{ route('radiographs.store', $horse) }}" class="card p-6 mt-8 space-y-4">
                @csrf
                <h2 class="text-[24px]">Add a set of radiographs</h2>
                <div class="grid sm:grid-cols-2 gap-4">
                    <x-input name="taken_on" type="date" label="Date taken" required />
                    <div><label class="field-label" for="purpose">Taken for</label><select id="purpose" name="purpose" class="field">@foreach (RadiographSet::PURPOSES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                    <x-input name="clinic" label="Clinic" />
                    <x-input name="veterinarian" label="Veterinarian" />
                </div>
                <x-input name="views" label="Views taken (e.g. front feet lateral and DP, hocks)" />
                <p class="hint text-[13px]">Then add the files: DICOM (.dcm) straight from the clinic, or JPEG/PNG exports and the PDF report. Horsefax keeps the clinic's record as it was issued; it doesn't interpret it.</p>
                <button class="btn-forest btn-small">Create the set</button>
            </form>
        @endif
    </div>
</x-layouts.site>
