{{--
    Evidence badges for one claim (Kate's model): where it came from, whether Horsefax has verified it, and whether a
    document backs it. "Horsefax Verified" opens the verification detail: how, when, by whom (the team, never a
    person), and exactly what it covers.
--}}
@props(['item', 'conflict' => false, 'compact' => false])
@php
    use App\Support\Record;
    $source = $item->source_type ?? 'owner_reported';
    $status = $conflict ? 'disputed' : ($item->status ?? 'unverified');
    $sourceClass = match ($source) {
        'professional_attested' => 'chip border-[#3b5b7a]/40 text-[#2c4a66] bg-[#3b5b7a]/[0.06]',
        'official_source' => 'chip border-ink/40 text-ink bg-ink/[0.05]',
        'historical' => 'chip border-charcoal/20 text-muted bg-white italic',
        default => 'chip-self',
    };
    $role = $item->contributor_role ?? null;
    $roleLabel = $source === 'professional_attested' && $role ? (Record::CONTRIBUTOR_ROLES[$role] ?? ucfirst($role)) : null;
    $credentialed = $source === 'professional_attested' && ($item->contributor ?? null)?->credential_verified_at;
    $v = $status === 'verified' ? $item->verification : null;
    $hasDoc = ! empty($item->document_id);
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex flex-wrap items-center gap-1.5 align-middle']) }}>
    <span class="{{ $sourceClass }}" title="{{ Record::SOURCE_DESCRIPTIONS[$source] ?? '' }}">{{ Record::SOURCES[$source] ?? $source }}{{ $roleLabel ? ' · '.$roleLabel : '' }}{{ $credentialed ? ' ✓' : '' }}</span>
    @if ($status === 'verified')
        <details class="relative inline-block">
            <summary class="list-none cursor-pointer chip-verified" title="See how this was verified"><svg width="10" height="10" viewBox="0 0 10 10" aria-hidden="true"><path d="M1.5 5.2 4 7.6 8.6 2.4" fill="none" stroke="currentColor" stroke-width="1.6"/></svg> Horsefax Verified</summary>
            <div class="absolute right-0 z-30 mt-2 w-[300px] max-w-[80vw] card shadow-xl p-4 text-left normal-case tracking-normal font-serif text-[14px] leading-snug text-charcoal">
                @if ($v)
                    <p class="label text-forest text-[10px]">Verification detail</p>
                    <p class="mt-2">{{ $v->scope }}</p>
                    <dl class="mt-3 font-sans text-[12px] space-y-1">
                        <div><dt class="inline text-muted">How:</dt> <dd class="inline">{{ $v->methodLabel() }}</dd></div>
                        <div><dt class="inline text-muted">Verified by:</dt> <dd class="inline">{{ $v->verifier_label }}</dd></div>
                        <div><dt class="inline text-muted">Date:</dt> <dd class="inline">{{ $v->verified_at->format('M j, Y') }}</dd></div>
                        @if ($v->recheck_on)<div><dt class="inline text-muted">Recheck due:</dt> <dd class="inline">{{ $v->recheck_on->format('M j, Y') }}</dd></div>@endif
                    </dl>
                @else
                    <p>Verified by the Horsefax Verification Team{{ $item->verified_at ? ' on '.$item->verified_at->format('M j, Y') : '' }}.</p>
                @endif
                <p class="mt-3 font-sans text-[11px] text-muted">A verification covers this item only. It is not a statement about the horse's soundness, suitability or anything else on the record.</p>
            </div>
        </details>
    @elseif ($status === 'disputed')
        <span class="chip-disputed">Disputed</span>
    @elseif ($status === 'superseded')
        <span class="chip-superseded">Superseded</span>
    @elseif (! $compact)
        <span class="chip border-charcoal/10 text-muted/80 bg-transparent">Not verified</span>
    @endif
    @if ($hasDoc)<span class="chip-doc" title="A supporting document is on file with Horsefax">Document on file</span>@endif
</span>
