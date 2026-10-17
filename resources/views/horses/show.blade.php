@php
    use App\Support\Record;
    use App\Models\HorseEvent;
    $tab = in_array(request('tab'), ['overview', 'pedigree', 'history', 'documents', 'media', 'ownership', 'contributors'], true) ? request('tab') : 'overview';
    $tabs = ['overview' => 'Identity', 'pedigree' => 'Pedigree', 'history' => 'History & show record', 'documents' => 'Documents', 'media' => 'Photos & videos', 'ownership' => 'Ownership & sales', 'contributors' => 'Contributors'];
@endphp
<x-layouts.site :title="$horse->displayName()" robots="noindex, nofollow">
    <section class="bg-forest text-cream">
        <div class="wrap py-10 flex flex-col sm:flex-row gap-6 sm:items-center">
            <div class="w-[120px] h-[120px] bg-ink/40 rounded-[3px] overflow-hidden border border-cream/10 shrink-0">
                @if ($horse->profilePhoto)<img src="{{ route('files.show', $horse->profilePhoto) }}" alt="" class="w-full h-full object-cover">@else<img src="/img/hf-tile.svg" alt="" class="w-full h-full">@endif
            </div>
            <div class="flex-1 min-w-0">
                <p class="label text-gold-soft">Horsefax record · {{ $horse->horsefax_id }}</p>
                <h1 class="text-cream text-[34px] sm:text-[44px] leading-tight mt-2">{{ $horse->displayName() }}</h1>
                <p class="mt-3 flex flex-wrap gap-2 items-center">
                    <span class="chip-vis">{{ $horse->public_summary ? 'Public summary on' : 'Public summary off' }}</span>
                    <span class="chip-vis">{{ $horse->listed ? 'Listed in search' : 'Not listed in search' }}</span>
                </p>
                <x-record-summary :parts="\App\Support\Summary::parts($horse, $facts, $identifiers, $events, $pedigree, $documents)" dark class="mt-3" />
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($canEdit)<a href="{{ route('sharing', $horse) }}" class="btn-gold btn-small">Share</a>@endif
                <a href="{{ route('horse.public', $horse) }}" class="btn-ghost-light btn-small">{{ $horse->public_summary ? 'View public summary' : 'Preview public summary' }}</a>
                <a href="{{ route('horses.audit', $horse) }}" class="btn-ghost-light btn-small">Record history</a>
            </div>
        </div>
        <nav class="wrap flex gap-1 overflow-x-auto" aria-label="Record sections">
            @foreach ($tabs as $k => $label)
                <a href="{{ route('horses.show', ['horse' => $horse, 'tab' => $k]) }}" class="shrink-0 px-4 py-3 font-sans text-[11.5px] font-semibold tracking-[0.12em] uppercase no-underline rounded-t-[3px] {{ $tab === $k ? 'bg-cream text-ink' : 'text-cream/75 hover:text-cream' }}" @if ($tab === $k) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
    </section>

    <div class="wrap py-10">
        @if ($openReviews->isNotEmpty())
            <div class="card p-4 mb-6 border-gold/50 font-sans text-[14px]">Horsefax staff are reviewing {{ $openReviews->count() === 1 ? 'an item' : $openReviews->count().' items' }} on this record ({{ $openReviews->map->kindLabel()->unique()->join(', ') }}). Nothing changes until the review is complete.</div>
        @endif
        @if ($errors->any())
            <div class="card p-4 mb-6 border-red-800/40 font-sans text-[14px] text-red-800">{{ $errors->first() }}</div>
        @endif

        @if ($canEdit && ($resultSuggestions ?? collect())->isNotEmpty())
            @foreach ($resultSuggestions as $sr)
                <div class="card p-5 mb-6 border-gold/60 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <p class="text-[17px]">We found <a href="{{ route('show-records.show', $sr) }}" class="text-gold-dark">{{ $sr->appearances }} published show {{ Str::plural('result', $sr->appearances) }}</a> under "{{ $sr->display_name }}" ({{ $sr->yearsLabel() }}). Are they this horse's?</p>
                    <form method="POST" action="{{ route('show-records.claim', [$horse, $sr]) }}">@csrf<button class="btn-gold btn-small">Yes, ask Horsefax to attach them</button></form>
                </div>
            @endforeach
        @endif
        @switch($tab)
        @case('overview')
            <div class="grid lg:grid-cols-[1fr_340px] gap-10">
                <div class="space-y-10">
                    <section>
                        <div class="flex items-center justify-between border-b border-line pb-2"><h2 class="text-[26px]">Identity</h2>@if ($canEdit)<a href="{{ route('horses.edit', $horse) }}" class="btn-ghost btn-small">Edit</a>@endif</div>
                        <table class="table-hf mt-2">
                            <tbody>
                            @foreach (\App\Models\HorseFact::FIELDS as $field => $label)
                                @php $f = $facts->get($field); $past = $factHistory->get($field, collect()); @endphp
                                <tr>
                                    <td class="w-[28%] label text-muted text-[10px] align-top pt-3.5">{{ $label }}</td>
                                    <td class="text-[18px]">{{ $f?->display() ?? '—' }}
                                        @if ($f)@include('partials.claim-dates', ['c' => $f])@endif
                                        @if ($past->isNotEmpty())
                                            <details class="mt-1"><summary class="cursor-pointer font-sans text-[12px] text-gold-dark">History ({{ $past->count() }} earlier or held {{ Str::plural('value', $past->count()) }})</summary>
                                                <ul class="mt-1 font-sans text-[12.5px] space-y-1">
                                                    @foreach ($past as $old)
                                                        <li>{{ $old->display() }} · {{ $old->sourceLabel() }} · {{ $old->created_at->format('M j, Y') }}{{ $old->superseded_by ? ' · replaced' : ' · held for staff review' }}</li>
                                                    @endforeach
                                                </ul>
                                            </details>
                                        @endif
                                    </td>
                                    <td class="text-right align-top pt-3">@if ($f)<x-claim :item="$f" compact class="justify-end" />@endif</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                        <p class="hint">Each value keeps its source and history. Basic identity (names, breed, sex, foaling year, color) appears in Horsefax search while the horse is listed. A change to a Horsefax Verified or professionally supplied value goes to staff review instead of replacing it.</p>
                    </section>

                    <section>
                        <h2 class="text-[26px] border-b border-line pb-2">Registration, microchip & other identifiers</h2>
                        @if ($identifiers->isEmpty())<p class="hint mt-3">None yet. A microchip number is the most reliable identifier.</p>@endif
                        <table class="table-hf mt-3">
                            @if ($identifiers->isNotEmpty())<thead><tr><th>Identifier</th><th>Number</th><th>Status</th><th>Visible to</th></tr></thead>@endif
                            <tbody>
                            @foreach ($identifiers as $i)
                                <tr>
                                    <td>{{ $i->label() }}</td>
                                    <td class="font-sans text-[15px]">{{ $i->displayValue(true) }}@if ($i->kind === 'microchip')<br><span class="hint text-[13px]">Shown to others as {{ $i->displayValue() }}</span>@endif</td>
                                    <td><x-claim :item="$i" :conflict="$i->in_conflict" />@if ($i->in_conflict)<br><span class="hint text-[13px]">Also on another record; under review</span>@endif</td>
                                    <td>@include('horses.partials.visibility', ['type' => 'identifier', 'item' => $i])</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                        @if ($canEdit)
                            <details class="card p-5 mt-4">
                                <summary class="cursor-pointer text-[18px] text-ink">Add an identifier</summary>
                                <form method="POST" action="{{ route('record.identifiers', $horse) }}" enctype="multipart/form-data" class="mt-4 grid sm:grid-cols-2 gap-4">
                                    @csrf
                                    <div><label class="field-label" for="i-kind">Type</label><select id="i-kind" name="kind" class="field">@foreach (Record::IDENTIFIER_KINDS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                                    <div><label class="field-label" for="i-reg">Registry or association (for registrations)</label><input id="i-reg" name="registry" list="registry-list-2" class="field" placeholder="Start typing, e.g. ARHA" autocomplete="off"><datalist id="registry-list-2">@foreach (Record::REGISTRIES as $k => $l)@continue($k === 'Other')<option value="{{ $k }}">{{ $l }}</option>@endforeach</datalist></div>
                                    <x-input name="value" label="Number" required />
                                    <x-visibility-select value="shared" id-suffix="ident" />
                                    <div class="sm:col-span-2"><label class="field-label" for="i-proof">Proof (optional: papers, chip scan record)</label><input id="i-proof" type="file" name="proof" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic" class="field"></div>
                                    <div class="sm:col-span-2"><button class="btn-forest btn-small">Add identifier</button></div>
                                </form>
                            </details>
                        @endif
                    </section>

                    <section>
                        <h2 class="text-[26px] border-b border-line pb-2">Other names</h2>
                        <ul class="mt-3 space-y-2">
                            @forelse ($names as $n)
                                <li class="flex items-center justify-between gap-3"><span class="text-[18px]">{{ $n->name }} <span class="hint">{{ $n->kind === 'previous' ? 'previous name' : 'alias' }}</span></span>@include('horses.partials.visibility', ['type' => 'name', 'item' => $n])</li>
                            @empty<li class="hint">None recorded.</li>@endforelse
                        </ul>
                        @if ($canEdit)
                            <form method="POST" action="{{ route('record.names', $horse) }}" class="mt-4 grid sm:grid-cols-[1fr_160px_200px_auto] gap-3 items-end">
                                @csrf
                                <x-input name="name" label="Add a name" />
                                <div><label class="field-label" for="n-kind">Kind</label><select id="n-kind" name="kind" class="field"><option value="alias">Alias / barn name</option><option value="previous">Previous name</option></select></div>
                                <x-visibility-select value="shared" id-suffix="name" />
                                <button class="btn-forest btn-small">Add</button>
                            </form>
                        @endif
                    </section>
                </div>

                <aside class="space-y-6">
                    @if ($canEdit)
                        <div class="card p-5">
                            <h2 class="text-[20px]">Profile photo</h2>
                            <form method="POST" action="{{ route('record.photo', $horse) }}" enctype="multipart/form-data" class="mt-3 space-y-3">
                                @csrf
                                <input type="file" name="photo" accept=".jpg,.jpeg,.png,.webp" class="field" required>
                                <x-visibility-select value="public" id-suffix="photo" />
                                <button class="btn-forest btn-small">Upload photo</button>
                            </form>
                        </div>
                    @endif
                    <div class="card p-5">
                        <h2 class="text-[20px]">At a glance</h2>
                        <ul class="mt-3 font-sans text-[14px] space-y-1.5">
                            <li>{{ $events->count() }} history {{ Str::plural('entry', $events->count()) }}@if ($superseded) ({{ $superseded }} corrected)@endif</li>
                            <li>{{ $documents->count() }} {{ Str::plural('document', $documents->count()) }}</li>
                            <li>{{ $pedigree->count() }} pedigree {{ Str::plural('line', $pedigree->count()) }} · {{ $offspring->count() }} offspring on Horsefax</li>
                            <li>{{ $media->count() }} photos & videos</li>
                        </ul>
                    </div>
                </aside>
            </div>
        @break

        @case('pedigree')
            @include('partials.pedigree-chart', ['pedigree' => $pedigree, 'horse' => $horse])
            @if ($canEdit)
                <details class="card p-6 mt-8" @if ($pedigree->isEmpty()) open @endif>
                    <summary class="cursor-pointer text-[20px] text-ink">{{ $pedigree->isEmpty() ? 'Add the pedigree' : 'Edit the pedigree' }}</summary>
                    @include('horses.partials.pedigree-form', ['horse' => $horse, 'pedigree' => $pedigree])
                </details>
            @endif
            <h2 class="text-[26px] mt-10 border-b border-line pb-2">Offspring on Horsefax</h2>
            <ul class="mt-3 divide-y divide-line">
                @forelse ($offspring as $o)
                    <li class="py-2.5 flex justify-between"><span class="text-[18px]">{{ $o->displayName() }}</span><span class="font-sans text-[13px] text-muted">{{ collect([$o->horsefax_id, $o->sex ? ucfirst($o->sex) : null, $o->birth_year])->filter()->join(' · ') }}</span></li>
                @empty<li class="hint py-2">No offspring linked yet. Offspring appear automatically when their records name this horse as sire or dam.</li>@endforelse
            </ul>
        @break

        @case('history')
            @if ($canEdit)
                @include('horses.partials.event-form', ['horse' => $horse, 'event' => null, 'action' => route('record.events', $horse), 'button' => 'Add entry'])
            @endif
            <div class="mt-8 space-y-0">
                @forelse ($events as $e)
                    <div class="grid sm:grid-cols-[130px_1fr_auto] gap-x-6 gap-y-2 py-4 border-b border-line">
                        <p class="font-sans text-[13px] text-muted pt-1">{{ $e->dateLabel() }}</p>
                        <div>
                            <p class="label text-gold-dark text-[10px]">{{ $e->categoryLabel() }}{{ $e->typeLabel() ? ' · '.$e->typeLabel() : '' }}</p>
                            <p class="text-[19px] text-ink mt-1">{{ $e->title }}@if ($e->result) <span class="text-muted">· {{ $e->result }}</span>@endif</p>
                            @if ($e->category === 'competition' && ($e->event_name || $e->class_name || $e->placing))
                                <p class="font-sans text-[13px] mt-1">{{ collect([$e->organization, $e->event_name, $e->class_name, $e->placing ? 'Placed '.$e->placing : null, $e->score ? 'Score '.$e->score : null, $e->points !== null ? $e->points.' pts' : null, HorseEvent::money($e->earnings_cents), $e->rider ? 'Rider: '.$e->rider : null])->filter()->join(' · ') }}</p>
                            @endif
                            @if ($e->amount_cents !== null)<p class="font-sans text-[13px] mt-1">Price {{ HorseEvent::money($e->amount_cents) }}{{ $e->sale_venue ? ' · '.$e->sale_venue : '' }} · price visible to: @include('horses.partials.visibility', ['type' => 'amount', 'item' => $e, 'field' => 'amount_visibility'])</p>@endif
                            @if ($e->provider)<p class="font-sans text-[13px] text-muted mt-0.5">{{ $e->provider }}</p>@endif
                            @if ($e->expires_on)<p class="font-sans text-[13px] text-muted mt-0.5">{{ $e->expires_on->isPast() ? 'Expired' : 'Valid until' }} {{ $e->expires_on->format('M j, Y') }}</p>@endif
                            @if ($e->details)<p class="text-[16px] mt-2 whitespace-pre-line">{{ $e->details }}</p>@endif
                            @if ($e->document)<p class="mt-2"><a href="{{ route('files.show', $e->document) }}" class="font-sans text-[13px] text-gold-dark" target="_blank">View attached document</a></p>@endif
                            <p class="font-sans text-[12px] text-muted mt-2">Added by {{ $e->contributor_id === auth()->id() ? 'you' : ($e->contributor?->name ?? 'a contributor') }} ({{ Record::CONTRIBUTOR_ROLES[$e->contributor_role] ?? $e->contributor_role }})@if ($e->supersedes) · correction of an earlier entry @endif</p>
                            @include('partials.claim-dates', ['c' => $e])
                        </div>
                        <div class="flex sm:flex-col items-start sm:items-end gap-2">
                            <x-claim :item="$e" class="sm:justify-end" />
                            @include('horses.partials.visibility', ['type' => 'event', 'item' => $e])
                            @if ($canEdit && ($e->contributor_id === auth()->id() || auth()->user()->isStaff()))<a href="{{ route('record.events.correct', [$horse, $e]) }}" class="font-sans text-[12px] text-gold-dark">Correct</a>
                            @elseif ($e->contributor_id !== auth()->id())
                                <details class="sm:text-right">
                                    <summary class="cursor-pointer font-sans text-[12px] text-gold-dark">Dispute</summary>
                                    <form method="POST" action="{{ route('corrections.store', $horse) }}" class="mt-2 w-[260px] space-y-2 text-left">
                                        @csrf
                                        <input type="hidden" name="subject" value="event:{{ $e->id }}">
                                        <label class="sr-only" for="disp-{{ $e->id }}">What is wrong with this entry</label>
                                        <textarea id="disp-{{ $e->id }}" name="statement" rows="3" required minlength="10" class="field text-[14px]" placeholder="What is wrong, and how you know"></textarea>
                                        <p class="hint text-[12px]">Entries added by someone else can't be edited. Horsefax staff review the dispute; the entry stays until they decide.</p>
                                        <button class="btn-ghost btn-small">Send to Horsefax staff</button>
                                    </form>
                                </details>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="hint">No history yet. Add a Coggins test, vaccinations, farrier visits, training, show results or a sale.</p>
                @endforelse
            </div>
        @break

        @case('documents')
            @if ($canEdit)
                <details class="card p-6" open>
                    <summary class="cursor-pointer text-[20px] text-ink">Upload a document</summary>
                    <form method="POST" action="{{ route('record.documents', $horse) }}" enctype="multipart/form-data" class="mt-4 grid sm:grid-cols-2 gap-4">
                        @csrf
                        <div class="sm:col-span-2"><label class="field-label" for="d-file">File (PDF or image, up to 20 MB)</label><input id="d-file" type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png,.webp,.heic" class="field"></div>
                        <div><label class="field-label" for="d-kind">Kind</label><select id="d-kind" name="kind" class="field">@foreach (Record::DOCUMENT_KINDS as $k => $l)@continue($k === 'photo')<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                        <x-input name="title" label="Title" />
                        <x-input name="issuer" label="Issued by (vet, registry, show)" />
                        <div class="grid grid-cols-2 gap-3"><x-input name="issued_on" type="date" label="Dated" /><x-input name="expires_on" type="date" label="Expires" /></div>
                        <x-visibility-select value="private" id-suffix="doc" label="Who can see it's on file" />
                        <div class="sm:col-span-2"><p class="hint">The file itself is only ever shown to you, other stewards and Horsefax staff. "Shared" or "Public" lists its title and date in reports.</p><button class="btn-forest btn-small mt-3">Upload</button></div>
                    </form>
                </details>
            @endif
            <table class="table-hf mt-8">
                <thead><tr><th>Document</th><th>Dated</th><th>Status</th><th>Listed to</th></tr></thead>
                <tbody>
                @forelse ($documents as $d)
                    <tr>
                        <td><a href="{{ route('files.show', $d) }}" target="_blank" class="text-ink">{{ $d->title }}</a><br><span class="font-sans text-[12px] text-muted">{{ $d->kindLabel() }}{{ $d->issuer ? ' · '.$d->issuer : '' }} · {{ number_format($d->size / 1024) }} KB · uploaded {{ $d->created_at->format('M j, Y') }}@if ($d->contributor_role) by {{ $d->uploader?->name }} ({{ strtolower(Record::CONTRIBUTOR_ROLES[$d->contributor_role] ?? $d->contributor_role) }})@endif</span></td>
                        <td class="whitespace-nowrap">{{ $d->issued_on?->format('M j, Y') ?? '—' }}@if ($d->expires_on)<br><span class="hint text-[13px]">{{ $d->expires_on->isPast() ? 'expired' : 'expires' }} {{ $d->expires_on->format('M j, Y') }}</span>@endif</td>
                        <td><x-claim :item="$d" /></td>
                        <td>@include('horses.partials.visibility', ['type' => 'document', 'item' => $d])</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="hint">No documents yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        @break

        @case('media')
            @if ($canEdit)
                <details class="card p-6" open>
                    <summary class="cursor-pointer text-[20px] text-ink">Add a photo or video</summary>
                    <form method="POST" action="{{ route('record.media', $horse) }}" enctype="multipart/form-data" class="mt-4 grid sm:grid-cols-2 gap-4">
                        @csrf
                        <div><label class="field-label" for="m-file">Upload a photo or short video (up to 100 MB)</label><input id="m-file" type="file" name="file" accept=".jpg,.jpeg,.png,.webp,.heic,.mp4,.mov" class="field"></div>
                        <x-input name="url" label="…or a YouTube / Vimeo link" placeholder="https://" />
                        <x-input name="caption" label="Caption" />
                        <x-input name="taken_on" type="date" label="Taken on" />
                        <div class="sm:col-span-2 border-l-2 border-gold pl-4 space-y-3">
                            <label class="flex items-start gap-2 font-sans text-[14px]"><input type="checkbox" name="rights_attested" value="1" class="accent-gold mt-1"> <span>I hold the rights to share this, including signed releases from the photographer or videographer and from any person shown.</span></label>
                            <div><label class="field-label" for="m-release">Release form (optional, kept private)</label><input id="m-release" type="file" name="release" accept=".pdf,.jpg,.jpeg,.png" class="field"></div>
                            <p class="hint">Without this confirmation the item stays private.</p>
                        </div>
                        <x-visibility-select value="shared" id-suffix="media" />
                        <div class="sm:col-span-2"><button class="btn-forest btn-small">Add</button></div>
                    </form>
                </details>
            @endif
            <div class="mt-8 grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @forelse ($media as $m)
                    <figure class="card overflow-hidden">
                        @if ($m->embedUrl())<div class="aspect-video"><iframe src="{{ $m->embedUrl() }}" title="{{ $m->caption ?: 'Video' }}" class="w-full h-full" loading="lazy" allowfullscreen></iframe></div>
                        @elseif ($m->kind === 'video' && $m->document)<video controls preload="metadata" class="w-full aspect-video bg-ink"><source src="{{ route('files.show', $m->document) }}" type="{{ $m->document->mime }}"></video>
                        @elseif ($m->document)<img src="{{ route('files.show', $m->document) }}" alt="{{ $m->caption }}" class="w-full aspect-[4/3] object-cover" loading="lazy">@endif
                        <figcaption class="p-3 space-y-2">
                            <p class="text-[15px]">{{ $m->caption ?: 'Untitled' }} <span class="font-sans text-[12px] text-muted">{{ $m->taken_on?->format('M j, Y') }}</span></p>
                            <div class="flex flex-wrap gap-2 items-center">
                                <span class="{{ $m->rights_attested ? 'chip-verified' : 'chip-self' }}">{{ $m->rights_attested ? 'Rights confirmed' : 'No release: private' }}</span>
                                @include('horses.partials.visibility', ['type' => 'media', 'item' => $m])
                            </div>
                        </figcaption>
                    </figure>
                @empty
                    <p class="hint">No photos or videos yet.</p>
                @endforelse
            </div>
        @break

        @case('ownership')
            <div class="grid lg:grid-cols-[1fr_360px] gap-10">
                <section>
                    <h2 class="text-[26px] border-b border-line pb-2">Stewardship on Horsefax</h2>
                    <table class="table-hf mt-3">
                        <thead><tr><th>Person</th><th>Role</th><th>From</th><th>Until</th></tr></thead>
                        <tbody>@foreach ($stewardships as $s)<tr><td>{{ $s->user->name }}{{ $s->user_id === auth()->id() ? ' (you)' : '' }}</td><td>{{ \App\Support\Roles::stewardLabel($s) }}</td><td>{{ $s->started_on?->format('M j, Y') }}</td><td>{{ $s->ended_at?->format('M j, Y') ?? 'present' }}@if ($s->ended_reason)<br><span class="hint text-[13px]">{{ $s->ended_reason }}</span>@endif</td></tr>@endforeach</tbody>
                    </table>
                    <p class="hint">Record created by {{ $horse->creator?->id === auth()->id() ? 'you' : ($horse->creator?->name ?? '—') }}{{ $horse->fromPedigree() ? ' (from a pedigree)' : '' }}. Creating a record doesn't make someone the verified owner: a claimed owner becomes a verified owner after Horsefax staff review proof of ownership. Readers of reports see roles and dates only, never names.</p>
                    <h2 class="text-[26px] border-b border-line pb-2 mt-10">Sale history</h2>
                    <p class="hint mt-2">Add sales, purchases, auctions and leases from the <a href="{{ route('horses.show', ['horse' => $horse, 'tab' => 'history']) }}" class="text-gold-dark">History</a> tab (category "Ownership & custody"). Prices are private unless you share them.</p>
                </section>
                <aside>
                    @if ($canEdit && $horse->owner()?->id === auth()->id())
                        <div class="card p-5">
                            <h2 class="text-[20px]">Transfer to a new owner</h2>
                            @if ($pendingTransfer)
                                <p class="mt-2 text-[16px]">Waiting for <strong>{{ $pendingTransfer->to_email }}</strong> to accept.</p>
                                <form method="POST" action="{{ route('transfers.cancel', $pendingTransfer) }}" class="mt-3">@csrf<button class="btn-ghost btn-small">Cancel transfer</button></form>
                            @else
                                <p class="mt-2 text-[16px]">When the horse sells, the new owner accepts the record from their Horsefax account. Your entries stay attributed to you; you lose editing.</p>
                                <form method="POST" action="{{ route('transfers.start', $horse) }}" class="mt-3 space-y-3" data-confirm="Start a transfer of this record?">
                                    @csrf
                                    <x-input name="email" type="email" label="New owner's email" required />
                                    <label class="flex items-start gap-2 font-sans text-[13px]"><input type="checkbox" name="confirm" value="1" class="accent-gold mt-0.5"> I have sold or transferred this horse to this person.</label>
                                    @error('confirm')<p class="error">{{ $message }}</p>@enderror
                                    <button class="btn-forest btn-small">Start transfer</button>
                                </form>
                            @endif
                        </div>
                    @endif
                </aside>
            </div>
        @break
        @case('contributors')
            <div class="grid lg:grid-cols-[1fr_360px] gap-10">
                <section>
                    <h2 class="text-[26px] border-b border-line pb-2">Professional contributors</h2>
                    <p class="hint mt-2">Vets, farriers, trainers and breeders you invite add entries and documents in their own name. Their entries arrive private: you decide who sees them, but you can't edit them. If you disagree with one, dispute it from the History tab and Horsefax staff will review it.</p>
                    <table class="table-hf mt-3">
                        <thead><tr><th>Person</th><th>Role</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        @forelse ($contributors as $c)
                            <tr>
                                <td>{{ $c->user?->name ?? $c->email }}@if ($c->user)<br><span class="hint text-[13px]">{{ $c->email }}</span>@endif</td>
                                <td>{{ \App\Support\Roles::contributorLabel($c) }}</td>
                                <td>{{ $c->statusLabel() }}<br><span class="hint text-[13px]">@if ($c->status === 'active')since {{ $c->accepted_at?->format('M j, Y') }}@elseif ($c->status === 'pending')invited {{ $c->created_at->format('M j, Y') }}@else{{ $c->ended_at?->format('M j, Y') }}@endif</span></td>
                                <td class="text-right">
                                    @if ($canEdit && in_array($c->status, ['pending', 'active'], true))
                                        <form method="POST" action="{{ route('contributors.revoke', [$horse, $c]) }}" data-confirm="{{ $c->status === 'pending' ? 'Cancel this invitation?' : 'Remove this person\'s access? Entries they already added stay on the record.' }}">@csrf<button class="btn-ghost btn-small">{{ $c->status === 'pending' ? 'Cancel invite' : 'Remove access' }}</button></form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="hint">No one invited yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                    <p class="hint">Removing access keeps everything they added; the record history shows who added what and when.</p>
                </section>
                <aside>
                    @if ($canEdit)
                        <div class="card p-5">
                            <h2 class="text-[20px]">Invite a professional</h2>
                            <form method="POST" action="{{ route('contributors.invite', $horse) }}" class="mt-3 space-y-3">
                                @csrf
                                <x-input name="email" type="email" label="Their email" required />
                                <div><label class="field-label" for="c-role">Role</label><select id="c-role" name="role" class="field">@foreach (Record::CONTRIBUTOR_ROLES as $k => $l)<option value="{{ $k }}" @selected(old('role') === $k)>{{ $l }}</option>@endforeach</select></div>
                                @error('role')<p class="error">{{ $message }}</p>@enderror
                                <button class="btn-forest btn-small">Send invitation</button>
                            </form>
                            <p class="hint text-[13px] mt-3">They accept from their Horsefax dashboard after signing in with this email address. They see this horse's identity and their own entries only, never your private records.</p>
                        </div>
                    @endif
                </aside>
            </div>
        @break
        @endswitch
    </div>
</x-layouts.site>
