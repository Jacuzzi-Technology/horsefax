<x-layouts.site title="Staff: review" robots="noindex, nofollow">
    <div class="wrap py-10 max-w-[980px]">
        @include('admin.nav')
        <p class="label text-gold">{{ $item->kindLabel() }} · {{ ucfirst($item->status) }}</p>
        <h1 class="text-[32px] mt-2">{{ $item->horse?->displayName() }} <span class="font-sans text-[15px] text-muted">{{ $item->horse?->horsefax_id }}</span></h1>
        @if ($item->horse)<p class="mt-1 font-sans text-[13px]"><a href="{{ route('admin.horse', $item->horse) }}" class="text-gold-dark">Open the full record and audit log</a></p>@endif

        <div class="card p-6 mt-6 space-y-3">
            <p class="font-sans text-[13px] text-muted">From {{ $item->submitter?->name ?? $item->submitter_name }} &lt;{{ $item->submitter?->email ?? $item->submitter_email }}&gt; · {{ $item->created_at->setTimezone('America/Chicago')->format('M j, Y g:ia') }} CT</p>
            <p class="text-[18px] whitespace-pre-line">{{ $item->statement }}</p>
            @if ($subject)
                <div class="border-t border-line pt-3"><p class="label text-muted text-[10px]">About</p>
                    @if ($subject instanceof \App\Models\ShowRecord)<p><a href="{{ route('show-records.show', $subject) }}" class="text-gold-dark" target="_blank">{{ $subject->display_name }}</a>: {{ $subject->appearances }} published results, {{ $subject->yearsLabel() }}. Approving attaches them all to this horse.</p>
                    @elseif ($subject instanceof \App\Models\HorseFact)<p>{{ $subject->label() }}: {{ $subject->display() }} <x-claim :item="$subject" /></p>
                    @elseif ($subject instanceof \App\Models\HorseEvent)<p>{{ $subject->dateLabel() }} · {{ $subject->categoryLabel() }} · {{ $subject->title }} <x-claim :item="$subject" /></p>
                    @else<p>{{ $subject->label() }}: {{ $subject->value }} <x-claim :item="$subject" /></p>@endif
                </div>
            @endif
            @if ($documents->isNotEmpty())
                <div class="border-t border-line pt-3"><p class="label text-muted text-[10px]">Evidence</p>
                    <ul class="mt-1">@foreach ($documents as $d)<li><a href="{{ route('files.show', $d) }}" target="_blank" class="text-gold-dark">{{ $d->original_name }}</a> <span class="font-sans text-[12px] text-muted">{{ number_format($d->size / 1024) }} KB · sha256 {{ substr($d->sha256, 0, 12) }}…</span></li>@endforeach</ul>
                </div>
            @endif
            @if ($others->isNotEmpty())
                <div class="border-t border-line pt-3"><p class="label text-muted text-[10px]">The same number is on</p>
                    <ul class="mt-1">@foreach ($others as $o)<li><a href="{{ route('admin.horse', $o->horse) }}" class="text-gold-dark">{{ $o->horse->displayName() }}</a> ({{ $o->horse->horsefax_id }}) · {{ $o->label() }} {{ $o->value }}</li>@endforeach</ul>
                </div>
            @endif
            @if ($claim)
                <div class="border-t border-line pt-3 space-y-1"><p class="label text-muted text-[10px]">Claim checks</p>
                    @if ($claim['code'])<p>Claim code issued by <strong>{{ $claim['code']->issuer_name }}</strong> on {{ $claim['code']->created_at->format('M j, Y') }}, redeemed {{ $claim['code']->redeemed_at?->format('M j, Y') }}.</p>@endif
                    @php $d = $item->details ?? []; @endphp
                    @if (! empty($d['registration_number']))<p>Registration number given: <strong>{{ $d['registration_number'] }}</strong> · {!! $d['registration_match'] ? '<span class="chip-verified">matches the record</span>' : ($d['record_has_registration'] ?? false ? '<span class="chip-disputed">does not match the record</span>' : '<span class="chip-self">record has no number to compare</span>') !!}</p>@endif
                    @foreach ($claim['confirmations'] as $c)
                        <p>Seller {{ $c->seller_name }} &lt;{{ $c->seller_email }}&gt;: {!! $c->response === 'confirmed' ? '<span class="chip-verified">confirmed the sale</span>' : ($c->response === 'denied' ? '<span class="chip-disputed">denied the sale</span>' : '<span class="chip-self">'.($c->sent_at ? 'asked '.$c->sent_at->format('M j').', no answer yet' : 'email not sent').'</span>') !!}@if ($c->response_note) <span class="text-muted">“{{ $c->response_note }}”</span>@endif</p>
                    @endforeach
                    <p>Claimant identity: {!! $item->submitter?->identity_verified_at ? '<span class="chip-verified">verified as '.e($item->submitter->identity_verified_name).'</span>' : '<span class="chip-self">not verified</span>' !!}</p>
                    @foreach ($claim['reused'] as $r)<p class="error">The same evidence file was also used on {{ $r->horse?->horsefax_id }} ({{ $r->horse?->displayName() }}).</p>@endforeach
                </div>
            @endif
            @if ($identity)
                <div class="border-t border-line pt-3"><p class="label text-muted text-[10px]">Identity check</p>
                    <p>Legal name given: <strong>{{ $identity->legal_name }}</strong> · account name {{ $item->submitter?->name }} &lt;{{ $item->submitter?->email }}&gt;</p>
                    @if ($identity->document_path)<p><a href="{{ route('admin.identity.document', $identity) }}" target="_blank" class="text-gold-dark">View the ID image</a> <span class="hint text-[12px]">(deleted automatically when you approve or reject)</span></p>@else<p class="hint">ID image deleted {{ $identity->document_deleted_at?->format('M j, Y') }}.</p>@endif
                </div>
            @endif
            @if ($item->kind === 'ownership_claim')
                <div class="border-t border-line pt-3"><p class="label text-muted text-[10px]">Current stewards</p>
                    <ul>@forelse ($owners as $s)<li>{{ $s->user->name }} &lt;{{ $s->user->email }}&gt; · {{ $s->role }} ({{ $s->status }}) since {{ $s->started_on?->format('M j, Y') }}</li>@empty<li class="hint">None.</li>@endforelse</ul>
                </div>
            @endif
        </div>

        @if ($item->status === 'open')
            <div class="grid md:grid-cols-2 gap-6 mt-6">
                <form method="POST" action="{{ route('admin.review.approve', $item) }}" class="card p-6 space-y-3" data-confirm="Approve this item?">
                    @csrf
                    <h2 class="text-[22px]">{{ ['ownership_claim' => 'Approve claim', 'identifier_conflict' => 'Resolve conflict', 'dispute' => 'Uphold: mark disputed', 'fact_conflict' => 'Use the other value', 'pedigree_conflict' => 'Use the other pedigree line', 'identity_check' => 'Verify identity'][$item->kind] ?? 'Approve' }}</h2>
                    @if ($item->kind === 'ownership_claim')
                        <p class="text-[15px]">The claimant becomes a verified owner.</p>
                        <label class="flex items-start gap-2 font-sans text-[14px]"><input type="checkbox" name="end_current_owner" value="1" class="accent-gold mt-0.5"> Also end the current owner's stewardship (only when the evidence shows the horse was sold or transferred)</label>
                    @elseif ($item->kind === 'identity_check')
                        <p class="text-[15px]">The name on the ID matches the person and their account. Their identity is marked verified (never shown publicly) and the ID image is deleted.</p>
                        <input type="hidden" name="method" value="id_document">
                    @elseif ($item->kind === 'identifier_conflict')
                        <label class="flex items-start gap-2 font-sans text-[14px]"><input type="radio" name="outcome" value="both_stand" checked class="accent-gold mt-0.5"> Both stand (e.g. the same number in different registries)</label>
                        <label class="flex items-start gap-2 font-sans text-[14px]"><input type="radio" name="outcome" value="new_is_wrong" class="accent-gold mt-0.5"> The number on this new record is wrong: mark it disputed</label>
                    @elseif (in_array($item->kind, ['fact_conflict', 'pedigree_conflict'], true))
                        <p class="text-[15px]">Use the other value: it becomes the current one, and the current value is kept in the record's history (never deleted). Check the registry first.</p>
                    @else
                        <p class="text-[15px]">The entry is labeled Disputed (never deleted). The owner can then correct it.</p>
                    @endif
                    <div><label class="field-label" for="a-note">Note (required, kept in the log)</label><textarea id="a-note" name="note" rows="3" class="field" required></textarea></div>
                    <button class="btn-forest btn-small">Approve</button>
                </form>
                <form method="POST" action="{{ route('admin.review.reject', $item) }}" class="card p-6 space-y-3" data-confirm="Reject this item?">
                    @csrf
                    <h2 class="text-[22px]">Reject</h2>
                    <p class="text-[15px]">{{ in_array($item->kind, ['fact_conflict', 'pedigree_conflict'], true) ? 'Keep the current value. The other one stays in the history.' : 'Nothing on the record changes.' }}</p>
                    <div><label class="field-label" for="r-note">Reason (required; shown to a claimant)</label><textarea id="r-note" name="note" rows="3" class="field" required></textarea></div>
                    <button class="btn-ghost btn-small">Reject</button>
                </form>
            </div>
        @else
            <div class="card p-5 mt-6"><p class="font-sans text-[14px]">{{ ucfirst($item->status) }} by {{ $item->resolver?->name }} on {{ $item->resolved_at?->format('M j, Y') }}: {{ $item->resolution_note }}</p></div>
        @endif
    </div>
</x-layouts.site>
