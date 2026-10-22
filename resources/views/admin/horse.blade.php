@php use App\Support\Record; @endphp
<x-layouts.site title="Staff: {{ $horse->displayName() }}" robots="noindex, nofollow">
    <div class="wrap py-10">
        @include('admin.nav')
        <h1 class="text-[32px]">{{ $horse->displayName() }} <span class="font-sans text-[15px] text-muted">{{ $horse->horsefax_id }}</span></h1>
        <p class="mt-1 font-sans text-[13px]"><a href="{{ route('horses.show', $horse) }}" class="text-gold-dark">Open as the owner sees it</a> · source: {{ $horse->fromPedigree() ? 'added from a pedigree' : ($horse->fromResults() ? 'built from published show results' : ($horse->source === 'published' ? 'built from published listings (HOMD, stallion or foal lists)' : ($horse->source === 'ifce' ? 'French national registry open data (IFCE)' : 'created by an owner'))) }}</p>

        <div class="card p-5 mt-6 flex flex-wrap items-start gap-6">
            <div>
                <p class="label text-muted text-[10px]">Identity</p>
                <p class="text-[20px] mt-1">{{ $horse->identityLabel() }}</p>
                @if ($identityVerification)<p class="font-sans text-[12px] text-muted mt-1">{{ $identityVerification->methodLabel() }} · {{ $identityVerification->verified_at->format('M j, Y') }} · by {{ $identityVerification->staff?->name }}</p>@endif
            </div>
            @include('admin.verify-controls', ['type' => 'horse', 'item' => $horse])
        </div>

        <div class="grid md:grid-cols-2 gap-6 mt-6">
            <div class="card p-5">
                <p class="label text-muted text-[10px]">Claim codes</p>
                <p class="hint text-[13px] mt-1">A one-time code for the buyer, printed on a claim certificate with a QR. Redeeming it makes them the verified owner when nobody else owns the record.</p>
                <form method="POST" action="{{ route('admin.claim-codes.issue', $horse) }}" class="mt-3 flex flex-wrap gap-2 items-end">
                    @csrf
                    <div><label class="field-label text-[11px]" for="issuer">Issued by</label><input id="issuer" name="issuer" required value="Horse of My Dreams" class="font-sans text-[13px] border border-line rounded px-2 py-1"></div>
                    <div><label class="field-label text-[11px]" for="expires_on">Use by (optional)</label><input id="expires_on" type="date" name="expires_on" class="font-sans text-[13px] border border-line rounded px-2 py-1"></div>
                    <button class="btn-forest btn-small">Issue code</button>
                </form>
                @php $codes = \App\Models\ClaimCode::where('horse_id', $horse->id)->latest()->get(); @endphp
                @foreach ($codes as $c)
                    <p class="font-sans text-[13px] mt-2"><a href="{{ route('admin.claim-codes.certificate', $c) }}" class="text-gold-dark">{{ $c->issuer_name }}, {{ $c->created_at->format('M j, Y') }}</a> · {{ $c->redeemed_at ? 'redeemed by '.$c->redeemer?->name.' ('.str_replace('_', ' ', $c->redemption).')' : ($c->revoked_at ? 'revoked' : ($c->usable() ? 'open' : 'expired')) }}</p>
                @endforeach
            </div>
            <div class="card p-5 flex gap-4 items-start">
                <img src="{{ route('horse.qr', [$horse, 'svg']) }}" alt="QR code for this horse's record" class="w-[110px] h-[110px]">
                <div><p class="label text-muted text-[10px]">QR code</p><p class="hint text-[13px] mt-1">Opens this horse's Horsefax record. For catalogs, stall cards and papers.</p>
                    <p class="font-sans text-[13px] mt-2"><a href="{{ route('horse.qr', [$horse, 'svg']) }}?download=1" class="text-gold-dark">SVG (print)</a> · <a href="{{ route('horse.qr', [$horse, 'png']) }}?download=1" class="text-gold-dark">PNG</a> · <a href="{{ route('admin.qr') }}" class="text-gold-dark">A whole catalog</a></p></div>
            </div>
        </div>

        @php
            $sections = [
                'fact' => ['Identity facts', $facts],
                'identifier' => ['Identifiers', $identifiers],
                'pedigree' => ['Pedigree lines', $pedigreeLines],
                'event' => ['History entries', $events],
                'document' => ['Documents', $documents],
            ];
        @endphp
        @foreach ($sections as $type => [$title, $rows])
            <h2 class="text-[24px] mt-10">{{ $title }}</h2>
            <table class="table-hf mt-2">
                <tbody>
                @forelse ($rows as $r)
                    <tr class="{{ ($type === 'fact' && ! $r->is_current) || ($r->superseded_by ?? null) ? 'opacity-60' : '' }}">
                        <td class="text-[15px]">
                            @switch($type)
                                @case('fact') {{ $r->label() }}: {{ $r->display() }}{{ $r->is_current ? '' : ($r->superseded_by ? ' (superseded)' : ' (held for review)') }} @break
                                @case('identifier') {{ $r->label() }}: {{ $r->value }}{{ $r->in_conflict ? ' (in conflict)' : '' }} @break
                                @case('pedigree') {{ Record::pedigreeLabel($r->position) }}: {{ $r->name }}{{ $r->registration_number ? ' · '.$r->registry.' '.$r->registration_number : '' }} @break
                                @case('event') {{ $r->dateLabel() }} · {{ $r->categoryLabel() }} · {{ $r->title }}{{ $r->superseded_by ? ' (superseded)' : '' }} @break
                                @default <a href="{{ route('files.show', $r) }}" target="_blank" class="text-gold-dark">{{ $r->title }}</a> · {{ $r->kindLabel() }} · {{ $r->original_name }}
                            @endswitch
                            <br><span class="font-sans text-[11px] text-muted">reported {{ $r->created_at->format('M j, Y') }}@if ($r->verified_at) · verified {{ $r->verified_at->format('M j, Y') }}@endif</span>
                        </td>
                        <td><x-claim :item="$r" :conflict="$type === 'identifier' && $r->in_conflict" />@isset($r->visibility) <x-visibility :value="$r->visibility" />@endisset</td>
                        <td>@include('admin.verify-controls', ['type' => $type, 'item' => $r])</td>
                    </tr>
                @empty<tr><td class="hint">None.</td></tr>@endforelse
                </tbody>
            </table>
        @endforeach

        <h2 class="text-[24px] mt-10">People</h2>
        <ul class="mt-2 text-[16px] space-y-1">
            <li>Record created by {{ $horse->creator?->name ?? '—' }} &lt;{{ $horse->creator?->email }}&gt;</li>
            @foreach ($stewardships as $s)<li>{{ $s->user->name }} &lt;{{ $s->user->email }}&gt; · {{ \App\Support\Roles::stewardLabel($s) }} · {{ $s->started_on?->format('M j, Y') }}–{{ $s->ended_at?->format('M j, Y') ?? 'present' }}</li>@endforeach
        </ul>
        @if ($contributors->isNotEmpty())
            <h3 class="text-[20px] mt-6">Professionals</h3>
            <table class="table-hf mt-2">
                <tbody>@foreach ($contributors as $c)
                    <tr>
                        <td>{{ $c->user?->name ?? $c->email }} · {{ $c->roleLabel() }} · {{ $c->statusLabel() }}<br><span class="font-sans text-[12px] text-muted">{{ $c->user?->credential_verified_at ? 'Credentials verified '.$c->user->credential_verified_at->format('M j, Y').' ('.$c->user->credential_detail.')' : 'Credentials not verified' }}</span></td>
                        <td>@if ($c->user)
                            <form method="POST" action="{{ route('admin.credentials', $c->user) }}" class="flex flex-wrap gap-2 items-center">
                                @csrf
                                <input type="hidden" name="credential_role" value="{{ $c->role }}">
                                <input name="credential_detail" value="{{ $c->user->credential_detail }}" placeholder="License / membership #" class="font-sans text-[12px] border border-line rounded px-2 py-1 w-48" required>
                                <select name="verified" class="font-sans text-[12px] border border-line rounded px-1 py-1"><option value="1">Verified</option><option value="0">Not verified</option></select>
                                <button class="btn-ghost btn-small">Save</button>
                            </form>
                        @endif</td>
                    </tr>
                @endforeach</tbody>
            </table>
        @endif

        <h2 class="text-[24px] mt-10">Audit log</h2>
        <table class="table-hf mt-2 text-[13px]">
            <tbody>@foreach ($audit as $a)<tr><td class="whitespace-nowrap font-sans text-[12px]">{{ \Illuminate\Support\Carbon::parse($a->created_at)->setTimezone('America/Chicago')->format('M j g:ia') }}</td><td class="font-sans text-[12px]">{{ $a->actor_name ?? 'System' }}</td><td class="font-sans text-[12px]">{{ $a->action }}</td><td class="font-mono text-[11px] break-all">{{ $a->before }} → {{ $a->after }} <span class="text-muted">{{ $a->ip }}</span></td></tr>@endforeach</tbody>
        </table>
    </div>
</x-layouts.site>
