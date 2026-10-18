<x-layouts.site title="Record history" robots="noindex, nofollow">
    <div class="wrap py-12">
        <a href="{{ route('horses.show', $horse) }}" class="font-sans text-[13px] text-gold-dark">← {{ $horse->displayName() }}</a>
        <h1 class="text-[36px] mt-2">Record history</h1>
        <p class="hint">Every change to this record, newest first. This log can't be edited or deleted, by anyone.</p>
        <table class="table-hf mt-6">
            <thead><tr><th>When</th><th>Who</th><th>What</th><th>Change</th></tr></thead>
            <tbody>
            @foreach ($entries as $a)
                <tr>
                    <td class="whitespace-nowrap font-sans text-[13px]">{{ \Illuminate\Support\Carbon::parse($a->created_at)->setTimezone('America/Chicago')->format('M j, Y g:ia') }} CT</td>
                    <td>{{ $a->actor_name ?? 'System' }}</td>
                    <td class="font-sans text-[13px]">{{ str_replace(['.', '_'], [' · ', ' '], $a->action) }}</td>
                    <td class="font-mono text-[12px] break-all max-w-[420px]">@if ($a->before)<span class="text-muted">was</span> {{ $a->before }}<br>@endif @if ($a->after){{ $a->after }}@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div class="mt-6">{{ $entries->links() }}</div>
    </div>
</x-layouts.site>
