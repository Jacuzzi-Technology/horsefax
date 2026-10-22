@php use App\Models\RadiographSet; @endphp
<x-layouts.site title="{{ $set->title() }} · {{ $horse->displayName() }}" robots="noindex, nofollow">
    <div class="wrap py-12 max-w-[980px]">
        <a href="{{ route('radiographs.index', $horse) }}" class="font-sans text-[13px] text-gold-dark">← Radiographs for {{ $horse->displayName() }}</a>
        <h1 class="text-[34px] mt-2">{{ $set->title() }}</h1>
        <p class="font-sans text-[14px] text-muted mt-1">{{ $set->attribution() }}@if ($set->views) · {{ $set->views }}@endif · {{ $set->source_type === 'professional_attested' ? 'Added by the veterinarian' : 'Added by the owner' }}</p>
        @if (session('status'))<p class="mt-4 font-sans text-[14px] text-forest">{{ session('status') }}</p>@endif
        @if ($errors->any())<p class="mt-4 error">{{ $errors->first() }}</p>@endif

        <section class="card p-6 mt-6">
            <h2 class="text-[22px]">Files</h2>
            <ul class="mt-3 space-y-1 font-sans text-[14px]" data-file-list>
                @forelse ($set->files as $f)<li>{{ $f->original_name }} <span class="text-muted">· {{ strtoupper($f->kind) }} · {{ number_format($f->size / 1048576, 1) }} MB</span></li>@empty<li class="hint" data-empty>No files yet.</li>@endforelse
            </ul>
            <div class="mt-4">
                <label class="field-label" for="rad-files">Add files (you can select many at once)</label>
                <input id="rad-files" type="file" multiple accept=".dcm,.dicom,.jpg,.jpeg,.png,.webp,.pdf,application/dicom" class="field" data-upload="{{ route('radiographs.upload', [$horse, $set]) }}">
                <p class="hint text-[13px] mt-1">Up to 150 MB a file. Files upload one at a time, so a whole set can go up together.</p>
                <p class="font-sans text-[13px] mt-2" data-progress></p>
            </div>
        </section>

        @if ($canManage)
            <section class="card p-6 mt-6">
                <h2 class="text-[22px]">Who can see them</h2>
                <form method="POST" action="{{ route('radiographs.access', [$horse, $set]) }}" class="mt-3 space-y-3">
                    @csrf
                    @foreach (RadiographSet::ACCESS as $k => $l)
                        <label class="flex items-start gap-2 text-[16px]"><input type="radio" name="access" value="{{ $k }}" @checked($set->access === $k) class="accent-gold mt-1"> {{ $l }}</label>
                    @endforeach
                    <div class="pl-6"><x-input name="sale_access_until" type="date" label="Sale access until" :value="$set->sale_access_until?->format('Y-m-d')" /></div>
                    <label class="flex items-start gap-2 text-[16px]"><input type="checkbox" name="in_reports" value="1" @checked($set->in_reports) class="accent-gold mt-1"> Also include in report links that include health records</label>
                    <button class="btn-forest btn-small">Save</button>
                </form>
                <p class="hint text-[13px] mt-3">Buyers reach them from the horse's Horsefax record, or the QR code in a sale catalog. You'll see every buyer who looks.</p>
            </section>

            <section class="card p-6 mt-6">
                <h2 class="text-[22px]">Requests</h2>
                @forelse ($requests as $r)
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line py-3">
                        <div><p class="text-[16px]">{{ $r->user->name }} <span class="font-sans text-[13px] text-muted">&lt;{{ $r->user->email }}&gt; · {{ $r->created_at->format('M j, Y') }}</span></p>@if ($r->message)<p class="font-sans text-[13px] text-muted">“{{ $r->message }}”</p>@endif</div>
                        @if ($r->status === 'requested')
                            <div class="flex gap-2">
                                <form method="POST" action="{{ route('radiographs.decide', [$horse, $set, $r]) }}">@csrf<input type="hidden" name="decision" value="approved"><button class="btn-forest btn-small">Approve</button></form>
                                <form method="POST" action="{{ route('radiographs.decide', [$horse, $set, $r]) }}">@csrf<input type="hidden" name="decision" value="denied"><button class="btn-ghost btn-small">Decline</button></form>
                            </div>
                        @else
                            <p class="font-sans text-[13px]">{{ ucfirst($r->status) }} {{ $r->decided_at?->format('M j') }}@if ($r->expires_at) · until {{ $r->expires_at->format('M j, Y') }}@endif</p>
                        @endif
                    </div>
                @empty
                    <p class="hint mt-2">No requests.</p>
                @endforelse
            </section>

            <section class="card p-6 mt-6">
                <h2 class="text-[22px]">Who has looked</h2>
                <table class="table-hf mt-3"><tbody>
                    @forelse ($views as $v)
                        <tr><td>{{ $v->user?->name ?? 'Report link reader' }}</td><td class="font-sans text-[13px]">{{ ['viewed' => 'Viewed the set', 'opened' => 'Opened an image', 'downloaded' => 'Downloaded a file'][$v->action] ?? $v->action }}</td><td class="font-sans text-[13px] text-muted">{{ $v->created_at->setTimezone('America/Chicago')->format('M j, g:ia') }}</td></tr>
                    @empty
                        <tr><td class="hint">No buyer views yet.</td></tr>
                    @endforelse
                </tbody></table>
            </section>
        @endif
        <p class="mt-6"><a href="{{ route('radiographs.view', [$horse, $set]) }}" class="text-gold-dark font-sans text-[14px]">See them as a buyer would →</a></p>
    </div>
    <script>
        (() => {
            const input = document.querySelector('[data-upload]');
            if (!input) return;
            const out = document.querySelector('[data-progress]'), list = document.querySelector('[data-file-list]');
            const token = document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}';
            input.addEventListener('change', async () => {
                const files = [...input.files];
                let done = 0, failed = 0;
                for (const f of files) {
                    out.textContent = `Uploading ${done + 1} of ${files.length}: ${f.name}…`;
                    const fd = new FormData(); fd.append('file', f); fd.append('_token', token);
                    try {
                        const r = await fetch(input.dataset.upload, {method: 'POST', body: fd, headers: {'Accept': 'application/json'}});
                        const j = await r.json().catch(() => ({}));
                        if (!r.ok) throw new Error(j.error || j.message || r.status);
                        list.querySelector('[data-empty]')?.remove();
                        const li = document.createElement('li'); li.textContent = `${j.name} · ${j.kind.toUpperCase()}`; list.appendChild(li);
                    } catch (e) { failed++; const li = document.createElement('li'); li.className = 'error'; li.textContent = `${f.name}: ${e.message}`; list.appendChild(li); }
                    done++;
                }
                out.textContent = `${done - failed} of ${files.length} uploaded.` + (failed ? ` ${failed} could not be added.` : '');
                input.value = '';
            });
        })();
    </script>
</x-layouts.site>
