@php use App\Support\Record; use App\Models\HorseEvent; @endphp
<x-layouts.site :title="$horse->displayName()" robots="noindex, nofollow">
    <section class="bg-forest text-cream">
        <div class="wrap py-10">
            <p class="label text-gold-soft">Horsefax record · {{ $horse->horsefax_id }}</p>
            <h1 class="text-cream text-[34px] sm:text-[44px] leading-tight mt-2">{{ $horse->displayName() }}</h1>
            <p class="mt-3 flex flex-wrap gap-2 items-center">
                <span class="chip-vis">You contribute as {{ strtolower($contributor->roleLabel()) }}</span>
                <span class="{{ $horse->identity_status === 'verified' ? 'chip-verified' : ($horse->identity_status === 'under_review' ? 'chip-disputed' : 'chip-self') }}">{{ $horse->identityLabel() }}</span>
            </p>
        </div>
    </section>

    <div class="wrap py-10">
        @if ($errors->any())
            <div class="card p-4 mb-6 border-red-800/40 font-sans text-[14px] text-red-800">{{ $errors->first() }}</div>
        @endif
        <div class="grid lg:grid-cols-[1fr_340px] gap-10">
            <div class="space-y-10">
                <section>
                    @include('horses.partials.event-form', ['horse' => $horse, 'event' => null, 'action' => route('contribute.events', $horse), 'button' => 'Add entry', 'pro' => true])
                </section>

                <section>
                    <h2 class="text-[26px] border-b border-line pb-2">Your entries</h2>
                    @forelse ($events as $e)
                        <div class="grid sm:grid-cols-[130px_1fr_auto] gap-x-6 gap-y-2 py-4 border-b border-line">
                            <p class="font-sans text-[13px] text-muted pt-1">{{ $e->dateLabel() }}</p>
                            <div>
                                <p class="label text-gold-dark text-[10px]">{{ $e->categoryLabel() }}{{ $e->typeLabel() ? ' · '.$e->typeLabel() : '' }}</p>
                                <p class="text-[19px] text-ink mt-1">{{ $e->title }}@if ($e->result) <span class="text-muted">· {{ $e->result }}</span>@endif</p>
                                @if ($e->provider)<p class="font-sans text-[13px] text-muted mt-0.5">{{ $e->provider }}</p>@endif
                                @if ($e->expires_on)<p class="font-sans text-[13px] text-muted mt-0.5">{{ $e->expires_on->isPast() ? 'Expired' : 'Valid until' }} {{ $e->expires_on->format('M j, Y') }}</p>@endif
                                @if ($e->details)<p class="text-[16px] mt-2 whitespace-pre-line">{{ $e->details }}</p>@endif
                                @if ($e->document)<p class="mt-2"><a href="{{ route('files.show', $e->document) }}" class="font-sans text-[13px] text-gold-dark" target="_blank">View attached document</a></p>@endif
                                <p class="font-sans text-[12px] text-muted mt-2">Added {{ $e->created_at->format('M j, Y') }}@if ($e->superseded_by) · superseded @endif</p>
                            </div>
                            <div class="flex sm:flex-col items-start sm:items-end gap-2">
                                <x-status :status="$e->superseded_by ? 'superseded' : $e->status" />
                                <x-visibility :value="$e->visibility" />
                            </div>
                        </div>
                    @empty
                        <p class="hint mt-3">You haven't added anything yet.</p>
                    @endforelse
                </section>
            </div>

            <aside class="space-y-6">
                <div class="card p-5">
                    <h2 class="text-[20px]">Identity</h2>
                    <dl class="mt-2">
                        @foreach (['Registered name' => $horse->registered_name, 'Barn name' => $horse->barn_name, 'Breed' => $horse->breed, 'Sex' => $horse->sex ? ucfirst($horse->sex) : null, 'Foaled' => $horse->birthLabel(), 'Color' => $horse->color, 'Markings' => $horse->markings] as $k => $val)
                            @if ($val)<div class="py-1.5 border-b border-line"><dt class="label text-muted text-[10px]">{{ $k }}</dt><dd class="text-[16px]">{{ $val }}</dd></div>@endif
                        @endforeach
                    </dl>
                    <p class="hint text-[13px] mt-3">You see this horse's identity and what you add. The owner's own records stay private to them.</p>
                </div>

                <details class="card p-5">
                    <summary class="cursor-pointer text-[20px] text-ink">Upload a document</summary>
                    <form method="POST" action="{{ route('contribute.documents', $horse) }}" enctype="multipart/form-data" class="mt-4 space-y-3">
                        @csrf
                        <div><label class="field-label" for="cd-file">File (PDF or image, up to 20 MB)</label><input id="cd-file" type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png,.webp,.heic" class="field"></div>
                        <div><label class="field-label" for="cd-kind">Kind</label><select id="cd-kind" name="kind" class="field">@foreach (Record::DOCUMENT_KINDS as $k => $l)@continue($k === 'photo')<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                        {{-- Not <x-input>: the entry form above already uses its ids. --}}
                        <div><label class="field-label" for="cd-title">Title</label><input id="cd-title" name="title" class="field"></div>
                        <div><label class="field-label" for="cd-issuer">Issued by</label><input id="cd-issuer" name="issuer" class="field"></div>
                        <div class="grid grid-cols-2 gap-3">
                            <div><label class="field-label" for="cd-issued">Dated</label><input id="cd-issued" type="date" name="issued_on" class="field"></div>
                            <div><label class="field-label" for="cd-expires">Expires</label><input id="cd-expires" type="date" name="expires_on" class="field"></div>
                        </div>
                        <button class="btn-forest btn-small">Upload</button>
                    </form>
                </details>

                <div class="card p-5">
                    <h2 class="text-[20px]">Your documents</h2>
                    <ul class="mt-2 space-y-2">
                        @forelse ($documents as $d)
                            <li class="font-sans text-[14px]"><a href="{{ route('files.show', $d) }}" target="_blank" class="text-ink">{{ $d->title }}</a><br><span class="text-muted text-[12px]">{{ $d->kindLabel() }} · {{ $d->created_at->format('M j, Y') }} · </span><x-visibility :value="$d->visibility" /></li>
                        @empty
                            <li class="hint">None yet.</li>
                        @endforelse
                    </ul>
                </div>
            </aside>
        </div>
    </div>
</x-layouts.site>
