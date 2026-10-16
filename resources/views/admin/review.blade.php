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
                    @if ($subject instanceof \App\Models\HorseEvent)<p>{{ $subject->dateLabel() }} · {{ $subject->categoryLabel() }} · {{ $subject->title }} <x-status :status="$subject->status" /></p>
                    @else<p>{{ $subject->label() }}: {{ $subject->value }} <x-status :status="$subject->status" /></p>@endif
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
                    <h2 class="text-[22px]">{{ ['ownership_claim' => 'Approve claim', 'identifier_conflict' => 'Resolve conflict', 'dispute' => 'Uphold: mark disputed'][$item->kind] ?? 'Approve' }}</h2>
                    @if ($item->kind === 'ownership_claim')
                        <p class="text-[15px]">The claimant becomes a verified owner.</p>
                        <label class="flex items-start gap-2 font-sans text-[14px]"><input type="checkbox" name="end_current_owner" value="1" class="accent-gold mt-0.5"> Also end the current owner's stewardship (only when the evidence shows the horse was sold or transferred)</label>
                    @elseif ($item->kind === 'identifier_conflict')
                        <label class="flex items-start gap-2 font-sans text-[14px]"><input type="radio" name="outcome" value="both_stand" checked class="accent-gold mt-0.5"> Both stand (e.g. the same number in different registries)</label>
                        <label class="flex items-start gap-2 font-sans text-[14px]"><input type="radio" name="outcome" value="new_is_wrong" class="accent-gold mt-0.5"> The number on this new record is wrong: mark it disputed</label>
                    @else
                        <p class="text-[15px]">The entry is labeled Disputed (never deleted). The owner can then correct it.</p>
                    @endif
                    <div><label class="field-label" for="a-note">Note (required, kept in the log)</label><textarea id="a-note" name="note" rows="3" class="field" required></textarea></div>
                    <button class="btn-forest btn-small">Approve</button>
                </form>
                <form method="POST" action="{{ route('admin.review.reject', $item) }}" class="card p-6 space-y-3" data-confirm="Reject this item?">
                    @csrf
                    <h2 class="text-[22px]">Reject</h2>
                    <p class="text-[15px]">Nothing on the record changes.</p>
                    <div><label class="field-label" for="r-note">Reason (required; shown to a claimant)</label><textarea id="r-note" name="note" rows="3" class="field" required></textarea></div>
                    <button class="btn-ghost btn-small">Reject</button>
                </form>
            </div>
        @else
            <div class="card p-5 mt-6"><p class="font-sans text-[14px]">{{ ucfirst($item->status) }} by {{ $item->resolver?->name }} on {{ $item->resolved_at?->format('M j, Y') }}: {{ $item->resolution_note }}</p></div>
        @endif
    </div>
</x-layouts.site>
