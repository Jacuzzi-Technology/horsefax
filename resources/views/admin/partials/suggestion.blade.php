{{-- A search-backed suggestion: $s (ReviewSuggestion or null), $name (horse name), $sides (labels for left/right). --}}
@php $q = '"'.trim($name).'" horse'; @endphp
<div class="mt-3 border border-line rounded-[3px] px-3 py-2.5 font-sans text-[13px] {{ $s && $s->actionable() ? 'bg-forest/5 border-forest/40' : '' }}">
    @if ($s)
        <p><span class="font-semibold">Search suggestion: {{ \App\Models\ReviewSuggestion::LABELS[$s->suggestion] ?? $s->suggestion }}{{ $s->suggestion === 'lot_belongs' || ($s->suggestion === 'same_horse' && $s->horse) ? ' '.$s->horse?->horsefax_id : '' }}{{ $s->suggestion === 'same_horse' && $s->horse ? ' (keep this record)' : '' }}</span>
            @if ($s->status !== 'pending') <span class="text-muted">· {{ $s->status }} {{ $s->decided_at?->format('M j') }}</span>@endif</p>
        <p class="mt-1">{{ $s->summary }}</p>
        @php $pages = collect($s->evidence ?? [])->filter(fn ($e) => $e['supports'] ?? null)->unique('url'); @endphp
        @if ($pages->isNotEmpty())
            <ul class="mt-1 list-disc pl-5">
                @foreach ($pages as $e)
                    <li><a href="{{ $e['url'] }}" target="_blank" rel="noopener nofollow" class="text-gold-dark break-all">{{ \Illuminate\Support\Str::limit($e['url'], 90) }}</a>: {{ \App\Actions\Record\SuggestFromSearch::describe($e['facts']) }}{{ isset($e['record']) ? ' (vs '.$e['record'].')' : '' }}</li>
                @endforeach
            </ul>
        @endif
        @if ($s->actionable())
            <form method="POST" action="{{ route('admin.suggestions.decide', $s) }}" class="flex gap-2 mt-2" data-confirm="Apply this suggestion?">
                @csrf
                <button name="decision" value="accept" class="btn-forest btn-small">Accept</button>
                <button name="decision" value="reject" class="btn-ghost btn-small">Reject</button>
            </form>
        @endif
    @endif
    <p class="mt-1 text-muted">Look yourself (links only, nothing is read from these):
        <a href="https://search.brave.com/search?q={{ urlencode($q) }}" target="_blank" rel="noopener nofollow" class="text-gold-dark">web</a> ·
        <a href="https://search.brave.com/search?q={{ urlencode($q.' site:aqha.com') }}" target="_blank" rel="noopener nofollow" class="text-gold-dark">AQHA</a> ·
        <a href="https://www.facebook.com/search/top/?q={{ urlencode(trim($name)) }}" target="_blank" rel="noopener nofollow" class="text-gold-dark">Facebook</a></p>
</div>
