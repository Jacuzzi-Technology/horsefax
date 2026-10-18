@php use App\Support\Record; @endphp
<x-layouts.site title="Sharing" robots="noindex, nofollow">
    <div class="wrap py-12 max-w-[920px] space-y-8">
        <div><a href="{{ route('horses.show', $horse) }}" class="font-sans text-[13px] text-gold-dark">← {{ $horse->displayName() }}</a><h1 class="text-[38px] mt-2">Sharing</h1></div>

        @if ($newLink)
            <div class="card p-6 border-gold">
                <p class="label text-gold">New report link</p>
                <p class="mt-2 font-sans text-[14px] break-all bg-white border border-line p-3">{{ $newLink->url() }}</p>
                <div class="mt-3 flex gap-2"><button type="button" class="btn-gold btn-small" data-copy="{{ $newLink->url() }}">Copy link</button><a href="{{ $newLink->url() }}" target="_blank" class="btn-ghost btn-small">Preview</a></div>
                <p class="hint">Anyone with this link can see the sections you chose until it expires or you revoke it.</p>
            </div>
        @endif

        <section class="card p-6">
            <h2 class="text-[24px]">Search listing and public summary</h2>
            <form method="POST" action="{{ route('sharing.public', $horse) }}" class="mt-4 space-y-3">
                @csrf
                <label class="flex items-start gap-3 text-[17px]"><input type="checkbox" name="listed" value="1" class="accent-gold mt-1.5" @checked($horse->listed)> <span><strong>List in Horsefax search</strong><br><span class="hint">Shows only names, breed, sex, foaling year, color and the Horsefax ID, so buyers and owners can find the record and ask you for a report.</span></span></label>
                <label class="flex items-start gap-3 text-[17px]"><input type="checkbox" name="public_summary" value="1" class="accent-gold mt-1.5" @checked($horse->public_summary)> <span><strong>Publish a public summary</strong><br><span class="hint">A public page at {{ route('horse.public', $horse) }} showing only the items you've marked Public.</span></span></label>
                <button class="btn-forest btn-small">Save</button>
            </form>
        </section>

        <section class="card p-6">
            <h2 class="text-[24px]">Create a report link</h2>
            <p class="mt-1 text-[16px]">For a buyer, vet or trainer. Identity basics are always included; tick the sections to add. Only items marked Shared or Public appear.</p>
            <form method="POST" action="{{ route('sharing.store', $horse) }}" class="mt-4 space-y-4">
                @csrf
                <x-input name="label" label="Who is it for? (only you see this)" placeholder="e.g. Buyer in Texas" />
                <fieldset><legend class="field-label">Include</legend>
                    <div class="grid sm:grid-cols-2 gap-2 mt-1">
                        @foreach (Record::SHARE_SECTIONS as $k => $l)
                            <label class="flex items-start gap-2 text-[16px]"><input type="checkbox" name="sections[]" value="{{ $k }}" class="accent-gold mt-1.5" @checked(in_array($k, ['identifiers', 'pedigree', 'training', 'competition', 'media'], true))> {{ $l }}</label>
                        @endforeach
                    </div>
                </fieldset>
                <div class="max-w-[260px]"><label class="field-label" for="days">Expires</label><select id="days" name="days" class="field"><option value="7">In 7 days</option><option value="30" selected>In 30 days</option><option value="90">In 90 days</option><option value="">Never (until revoked)</option></select></div>
                <button class="btn-gold">Create link</button>
            </form>
        </section>

        <section>
            <h2 class="text-[24px]">Your links</h2>
            <table class="table-hf mt-3">
                <thead><tr><th>For</th><th>Sections</th><th>Status</th><th>Views</th><th></th></tr></thead>
                <tbody>
                @forelse ($links as $l)
                    <tr>
                        <td>{{ $l->label ?: 'Untitled' }}<br><span class="font-sans text-[12px] text-muted">created {{ $l->created_at->format('M j, Y') }}</span></td>
                        <td class="font-sans text-[12px]">{{ collect($l->sections)->map(fn ($s) => Record::SHARE_SECTIONS[$s] ?? $s)->join(', ') ?: 'Identity only' }}</td>
                        <td class="font-sans text-[13px]">@if ($l->revoked_at) Revoked {{ $l->revoked_at->format('M j') }} @elseif (! $l->isActive()) Expired @else Active{{ $l->expires_at ? ' until '.$l->expires_at->format('M j, Y') : '' }} @endif</td>
                        <td class="font-sans text-[13px]">{{ $l->view_count }}{{ $l->last_viewed_at ? ' · last '.$l->last_viewed_at->format('M j') : '' }}</td>
                        <td>@if ($l->isActive())
                            <div class="flex gap-2"><button type="button" class="btn-ghost btn-small" data-copy="{{ $l->url() }}">Copy</button>
                            <form method="POST" action="{{ route('sharing.revoke', [$horse, $l]) }}" data-confirm="Revoke this link? It will stop working immediately.">@csrf<button class="btn-ghost btn-small">Revoke</button></form></div>
                        @endif</td>
                    </tr>
                @empty<tr><td colspan="5" class="hint">No links yet.</td></tr>@endforelse
                </tbody>
            </table>
        </section>
    </div>
</x-layouts.site>
