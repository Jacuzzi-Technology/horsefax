@php use App\Models\HorseEvent; @endphp
<x-layouts.site :title="$record->display_name.' — published show results'" :canonical="route('show-records.show', $record)"
    :description="$record->display_name.': '.$record->appearances.' published show results, '.$record->yearsLabel().', collected by Horsefax from public results.'">
    <section class="bg-forest text-cream">
        <div class="wrap py-12">
            <p class="label text-gold-soft">Published show results</p>
            <h1 class="text-cream text-[38px] sm:text-[50px] mt-3">{{ $record->display_name }}</h1>
            <p class="font-sans text-[14px] text-cream/75 mt-3">{{ $record->appearances }} {{ Str::plural('result', $record->appearances) }} · {{ $record->yearsLabel() }}@if ($record->earnings_cents) · {{ HorseEvent::money($record->earnings_cents) }} reported earnings @endif</p>
            <p class="mt-4 flex flex-wrap gap-2">
                <span class="chip border-cream/25 text-cream/80 bg-transparent italic">Historical / third-party</span>
                @if ($record->registry_inferred)<span class="chip border-cream/25 text-cream/80 bg-transparent">{{ $record->registry_inferred }} registration inferred from {{ $record->registry_inferred }} classes</span>@endif
                <span class="chip border-cream/25 text-cream/80 bg-transparent">Not matched to a horse record yet</span>
            </p>
        </div>
    </section>
    <div class="wrap py-10 grid lg:grid-cols-[1fr_300px] gap-10">
        <div class="min-w-0">
            <p class="text-[17px] max-w-[42em]">These results were published under this registered name. More than one horse can share a name, so Horsefax hasn't attached them to a horse yet. That happens once a registration number confirms which horse they belong to.</p>
            @foreach ($byYear as $year => $rows)
                <h2 class="text-[24px] mt-8">{{ $year }} · {{ $rows->first()->event_name }}</h2>
                <div class="overflow-x-auto">
                    <table class="table-hf mt-2 min-w-[600px] text-[15px]">
                        <thead><tr><th>Class</th><th>Round</th><th>Placing</th><th class="text-right">Earnings</th><th>Sanction</th><th>Source</th></tr></thead>
                        <tbody>
                        @foreach ($rows as $a)
                            <tr>
                                <td>{{ $a->class_name }}</td>
                                <td>{{ $a->round }}</td>
                                <td>{{ $a->placing }}</td>
                                <td class="text-right whitespace-nowrap">{{ HorseEvent::money($a->earnings_cents) }}</td>
                                <td class="font-sans text-[12px]">{{ $a->organization }}</td>
                                <td><a href="{{ $a->source_url }}" class="font-sans text-[12px] text-gold-dark" rel="nofollow noopener" target="_blank">Published result</a></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
            <p class="hint mt-6">Collected from the publicly posted results of the {{ $byYear->first()?->first()?->event_name }}. Owner and rider names are not shown. A registration is "inferred" when the horse placed in AQHA classes; it is not confirmed until checked against the registry.</p>
        </div>
        <aside class="space-y-5">
            <div class="card p-5">
                <h2 class="text-[20px]">Is this your horse?</h2>
                <p class="mt-2 text-[16px]">Create its Horsefax record, add its registration number, and ask us to attach these results.</p>
                <a href="{{ route('horses.create', ['registered_name' => $record->display_name]) }}" class="btn-gold btn-small mt-3">Create the record</a>
            </div>
            <div class="card p-5">
                <p class="text-[15px]">Something wrong here?</p>
                <a href="{{ route('contact') }}" class="font-sans text-[12px] text-gold-dark">Tell us</a>
            </div>
        </aside>
    </div>
</x-layouts.site>
