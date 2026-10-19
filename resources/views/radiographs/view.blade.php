<x-layouts.site title="{{ $set->title() }} · {{ $horse->displayName() }}" robots="noindex, nofollow">
    <div class="wrap py-12">
        <a href="{{ route('horse.public', $horse) }}" class="font-sans text-[13px] text-gold-dark">← {{ $horse->displayName() }}</a>
        <h1 class="text-[34px] mt-2">{{ $set->title() }}</h1>
        <p class="font-sans text-[14px] text-muted mt-1">{{ $set->attribution() }}@if ($set->views) · {{ $set->views }}@endif · {{ $set->source_type === 'professional_attested' ? 'Added by the veterinarian' : 'Supplied by the owner' }}</p>
        <p class="hint mt-3 max-w-[48em]">These are the clinic's images as supplied. Horsefax doesn't interpret radiographs: have your own veterinarian review them. The owner can see who has viewed them.</p>
        @php $images = $set->files->where('kind', 'image'); $others = $set->files->where('kind', '!=', 'image'); @endphp
        @if ($images->isNotEmpty())
            <div class="mt-8 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                @foreach ($images as $f)
                    <button type="button" class="card overflow-hidden bg-ink aspect-square" data-full="{{ route('radiographs.file', [$horse, $set, $f] + $q) }}" aria-label="Open image {{ $loop->iteration }}">
                        <img src="{{ route('radiographs.file', [$horse, $set, $f] + $q) }}" alt="Radiograph {{ $loop->iteration }}" loading="lazy" class="w-full h-full object-contain">
                    </button>
                @endforeach
            </div>
        @endif
        @if ($others->isNotEmpty())
            <div class="card p-5 mt-8">
                <p class="label text-muted text-[10px]">Files</p>
                <ul class="mt-2 space-y-1 font-sans text-[14px]">
                    @foreach ($others as $f)
                        <li><a href="{{ route('radiographs.file', [$horse, $set, $f] + $q) }}" class="text-gold-dark" @if ($f->kind === 'pdf') target="_blank" @endif>{{ $f->kind === 'dicom' ? 'DICOM image' : 'PDF report' }} {{ $loop->iteration }}</a> <span class="text-muted">· {{ number_format($f->size / 1048576, 1) }} MB</span></li>
                    @endforeach
                </ul>
                @if ($others->where('kind', 'dicom')->isNotEmpty())<p class="hint text-[13px] mt-2">DICOM files download for your veterinarian's viewing software. An in-browser DICOM viewer is coming.</p>@endif
            </div>
        @endif
    </div>
    {{-- Lightbox: wheel or +/- to zoom, drag to pan, Esc to close. --}}
    <div class="fixed inset-0 bg-black/90 z-50 hidden items-center justify-center overflow-hidden" data-lightbox>
        <img alt="" class="max-w-none select-none cursor-grab" draggable="false" data-lb-img>
        <div class="absolute top-3 right-3 flex gap-2 font-sans text-[13px]">
            <button type="button" class="btn-ghost-light btn-small" data-lb="in">+</button><button type="button" class="btn-ghost-light btn-small" data-lb="out">−</button><button type="button" class="btn-ghost-light btn-small" data-lb="close">Close</button>
        </div>
    </div>
    <script>
        (() => {
            const box = document.querySelector('[data-lightbox]'), img = box.querySelector('[data-lb-img]');
            let s = 1, x = 0, y = 0, drag = null;
            const apply = () => img.style.transform = `translate(${x}px, ${y}px) scale(${s})`;
            const open = src => { img.src = src; s = 1; x = 0; y = 0; img.style.maxHeight = '90vh'; img.style.maxWidth = '92vw'; apply(); box.classList.replace('hidden', 'flex'); };
            const close = () => box.classList.replace('flex', 'hidden');
            document.querySelectorAll('[data-full]').forEach(b => b.addEventListener('click', () => open(b.dataset.full)));
            box.addEventListener('wheel', e => { e.preventDefault(); s = Math.min(8, Math.max(1, s * (e.deltaY < 0 ? 1.15 : 0.87))); apply(); }, {passive: false});
            img.addEventListener('pointerdown', e => { drag = [e.clientX - x, e.clientY - y]; img.setPointerCapture(e.pointerId); });
            img.addEventListener('pointermove', e => { if (drag) { x = e.clientX - drag[0]; y = e.clientY - drag[1]; apply(); } });
            img.addEventListener('pointerup', () => drag = null);
            box.querySelector('[data-lb="in"]').onclick = () => { s = Math.min(8, s * 1.3); apply(); };
            box.querySelector('[data-lb="out"]').onclick = () => { s = Math.max(1, s / 1.3); apply(); };
            box.querySelector('[data-lb="close"]').onclick = close;
            document.addEventListener('keydown', e => e.key === 'Escape' && close());
        })();
    </script>
</x-layouts.site>
