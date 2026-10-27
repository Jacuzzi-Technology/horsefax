{{-- Published profile (Oct 27 2026): reported lifetime earnings, at-stud details and article mentions from published
     stallion pages. Business details only: no phone numbers or email addresses are stored. --}}
@php
    $earn = $horse->events()->where('type', 'earnings')->whereIn('visibility', ['public', 'shared'])->orderByDesc('occurred_on')->first();
    $services = $horse->stallionServices()->with('source')->get();
    $mentions = $horse->mentions()->limit(12)->get();
@endphp
@if ($earn || $services->isNotEmpty() || $mentions->isNotEmpty())
    <section aria-labelledby="h-published" class="{{ $class ?? '' }}">
        <h2 id="h-published" class="text-[{{ $size ?? '26px' }}] border-b border-line pb-2">Published profile</h2>
        <table class="table-hf mt-2"><tbody>
            @if ($earn)
                <tr><td class="w-[28%] label text-muted text-[10px] align-top pt-3.5">Lifetime earnings</td>
                    <td class="text-[18px]">{{ \App\Models\HorseEvent::money($earn->earnings_cents) }}<p class="hint text-[13px]">As reported{{ str_contains((string) $earn->details, 'StallionCompare') ? ' by StallionCompare' : '' }}, {{ $earn->occurred_on?->format('M Y') }}. Not verified by Horsefax.</p></td></tr>
            @endif
            @foreach ($services as $s)
                <tr><td class="w-[28%] label text-muted text-[10px] align-top pt-3.5">At stud</td>
                    <td class="text-[18px]">
                        @if ($s->standing_at){{ $s->standing_at }}@if ($s->placeLabel())<span class="text-muted">, {{ $s->placeLabel() }}</span>@endif<br>@endif
                        <span class="font-sans text-[15px]">{{ collect([$s->feeLabel() ? 'Fee '.$s->feeLabel() : null, $s->chute_fee_cents ? 'chute fee $'.number_format($s->chute_fee_cents / 100) : null])->filter()->join(' · ') }}</span>
                        @if ($s->breeding)<p class="font-sans text-[14px] text-muted mt-1">{{ $s->breeding }}</p>@endif
                        <p class="hint text-[13px]">@if ($s->website)<a href="{{ $s->website }}" rel="nofollow noopener" target="_blank" class="text-gold-dark">Farm website</a> · @endif As published{{ $s->source ? ' on '.preg_replace('/\s*\(.*$/', '', $s->source->name) : '' }}, {{ $s->observed_on?->format('M Y') }}.@if ($s->source_url) <a href="{{ $s->source_url }}" rel="nofollow noopener" target="_blank" class="text-gold-dark">Source</a>@endif</p>
                    </td></tr>
            @endforeach
            @if ($mentions->isNotEmpty())
                <tr><td class="w-[28%] label text-muted text-[10px] align-top pt-3.5">In the news</td>
                    <td><ul class="space-y-1.5 font-sans text-[15px]">@foreach ($mentions as $m)<li><a href="{{ $m->url }}" rel="nofollow noopener" target="_blank" class="text-gold-dark">{{ $m->title }}</a>@if ($m->publisher) <span class="text-muted">· {{ $m->publisher }}</span>@endif</li>@endforeach</ul></td></tr>
            @endif
        </tbody></table>
    </section>
@endif
