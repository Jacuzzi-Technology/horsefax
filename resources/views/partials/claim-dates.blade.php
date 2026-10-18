{{-- Kate's three dates: when it happened, when it was reported to Horsefax, when Horsefax verified it. --}}
<p class="font-sans text-[11.5px] text-muted mt-1.5">
    @if (method_exists($c, 'dateLabel'))Happened {{ $c->dateLabel() }} · @endif
    Reported {{ $c->created_at?->format('M j, Y') ?? '—' }}
    @if ($c->verified_at) · Verified {{ $c->verified_at->format('M j, Y') }}@endif
</p>
