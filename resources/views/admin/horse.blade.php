@php use App\Support\Record; @endphp
<x-layouts.site title="Staff: {{ $horse->displayName() }}" robots="noindex, nofollow">
    <div class="wrap py-10">
        @include('admin.nav')
        <h1 class="text-[32px]">{{ $horse->displayName() }} <span class="font-sans text-[15px] text-muted">{{ $horse->horsefax_id }}</span></h1>
        <p class="mt-1 font-sans text-[13px]"><a href="{{ route('horses.show', $horse) }}" class="text-gold-dark">Open as the owner sees it</a></p>

        <form method="POST" action="{{ route('admin.horse.identity', $horse) }}" class="card p-5 mt-6 grid sm:grid-cols-[220px_1fr_auto] gap-3 items-end">
            @csrf
            <div><label class="field-label" for="idst">Identity</label><select id="idst" name="identity_status" class="field">@foreach (\App\Models\Horse::IDENTITY as $k => $l)<option value="{{ $k }}" @selected($horse->identity_status === $k)>{{ $l }}</option>@endforeach</select></div>
            <x-input name="note" label="What was cross-checked (required)" required />
            <button class="btn-forest btn-small">Save</button>
        </form>

        @foreach (['identifier' => $identifiers, 'event' => $events, 'document' => $documents] as $type => $rows)
            <h2 class="text-[24px] mt-10">{{ ucfirst($type) }}s</h2>
            <table class="table-hf mt-2">
                <tbody>
                @forelse ($rows as $r)
                    <tr>
                        <td class="text-[15px]">
                            @if ($type === 'identifier'){{ $r->label() }}: {{ $r->value }}{{ $r->in_conflict ? ' (in conflict)' : '' }}
                            @elseif ($type === 'event'){{ $r->dateLabel() }} · {{ $r->categoryLabel() }} · {{ $r->title }}{{ $r->superseded_by ? ' (superseded)' : '' }}
                            @else<a href="{{ route('files.show', $r) }}" target="_blank" class="text-gold-dark">{{ $r->title }}</a> · {{ $r->kindLabel() }} · {{ $r->original_name }}@endif
                        </td>
                        <td><x-status :status="$r->status" /> <x-visibility :value="$r->visibility" /></td>
                        <td>
                            <form method="POST" action="{{ route('admin.facts.status', [$type, $r->id]) }}" class="flex gap-2 items-center">
                                @csrf
                                <select name="status" class="font-sans text-[12px] border border-line rounded px-1 py-1">@foreach (Record::STATUS as $k => $l)<option value="{{ $k }}" @selected($r->status === $k)>{{ $l }}</option>@endforeach</select>
                                <input name="note" required placeholder="Source checked" class="font-sans text-[12px] border border-line rounded px-2 py-1 w-40">
                                <button class="btn-ghost btn-small">Set</button>
                            </form>
                        </td>
                    </tr>
                @empty<tr><td class="hint">None.</td></tr>@endforelse
                </tbody>
            </table>
        @endforeach

        <h2 class="text-[24px] mt-10">Stewards</h2>
        <ul class="mt-2 text-[16px]">@foreach ($stewardships as $s)<li>{{ $s->user->name }} &lt;{{ $s->user->email }}&gt; · {{ $s->role }} · {{ $s->status }} · {{ $s->started_on?->format('M j, Y') }}–{{ $s->ended_at?->format('M j, Y') ?? 'present' }}</li>@endforeach</ul>

        <h2 class="text-[24px] mt-10">Audit log</h2>
        <table class="table-hf mt-2 text-[13px]">
            <tbody>@foreach ($audit as $a)<tr><td class="whitespace-nowrap font-sans text-[12px]">{{ \Illuminate\Support\Carbon::parse($a->created_at)->setTimezone('America/Chicago')->format('M j g:ia') }}</td><td class="font-sans text-[12px]">{{ $a->actor_name ?? 'System' }}</td><td class="font-sans text-[12px]">{{ $a->action }}</td><td class="font-mono text-[11px] break-all">{{ $a->before }} → {{ $a->after }} <span class="text-muted">{{ $a->ip }}</span></td></tr>@endforeach</tbody>
        </table>
    </div>
</x-layouts.site>
