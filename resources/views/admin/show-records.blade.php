@php use App\Models\HorseEvent; @endphp
<x-layouts.site title="Staff: show results index" robots="noindex, nofollow">
    <div class="wrap py-10">
        @include('admin.nav')
        <h1 class="text-[30px]">Show results index</h1>
        <p class="font-sans text-[14px] mt-2">{{ number_format($stats['names']) }} registered names · {{ number_format($stats['results']) }} results · {{ number_format($stats['matched']) }} matched to horses · {{ number_format($stats['pages']) }} class pages harvested · {{ $stats['failed'] }} failed pages</p>
        @foreach ($sources as $s)
            <p class="hint text-[14px] mt-1">{{ $s->name }}: {{ str_replace('_', ' ', $s->permission) }}. {{ $s->terms_note }}</p>
        @endforeach
        <form method="GET" class="flex gap-2 max-w-[520px] mt-5"><input name="q" value="{{ $q }}" class="field" placeholder="Registered name"><button class="btn-forest btn-small">Search</button></form>
        <table class="table-hf mt-5">
            <thead><tr><th>Name</th><th>Years</th><th>Results</th><th class="text-right">Earnings</th><th>Inferred</th><th>Attach to horse</th></tr></thead>
            <tbody>
            @foreach ($records as $r)
                <tr>
                    <td><a href="{{ route('show-records.show', $r) }}" class="text-ink">{{ $r->display_name }}</a></td>
                    <td class="font-sans text-[13px]">{{ $r->yearsLabel() }}</td>
                    <td>{{ $r->appearances }}</td>
                    <td class="text-right font-sans text-[13px]">{{ HorseEvent::money($r->earnings_cents) }}</td>
                    <td class="font-sans text-[12px]">{{ $r->registry_inferred }}</td>
                    <td>
                        @if ($r->horse_id)
                            <a href="{{ route('admin.horse', $r->horse) }}" class="font-sans text-[12px] text-gold-dark">{{ $r->horse?->horsefax_id }}</a>
                        @else
                            <form method="POST" action="{{ route('admin.show-records.attach', $r) }}" class="flex gap-1" data-confirm="Attach all of this name's results to that horse?">
                                @csrf
                                <input name="horsefax_id" placeholder="HF-XXXX-XXXX" class="font-sans text-[12px] border border-line rounded px-2 py-1 w-32" required>
                                <input name="note" placeholder="How you confirmed it" class="font-sans text-[12px] border border-line rounded px-2 py-1 w-40" required>
                                <button class="btn-ghost btn-small">Attach</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div class="mt-6">{{ $records->links() }}</div>
    </div>
</x-layouts.site>
