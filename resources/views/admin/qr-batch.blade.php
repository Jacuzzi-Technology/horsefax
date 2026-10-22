<x-layouts.site title="Staff: QR codes" robots="noindex, nofollow">
    <div class="wrap py-10 max-w-[860px]">
        @include('admin.nav')
        <h1 class="text-[30px]">QR codes for a sale catalog</h1>
        <p class="hint mt-2">One line per horse: the hip number, then the Horsefax ID (or just the ID). You get a ZIP with a PNG and a print-ready SVG for each horse, named by hip, plus an index. Each code opens the horse's Horsefax record and carries the HF mark.</p>
        <form method="POST" action="{{ route('admin.qr.batch') }}" class="card p-6 mt-6 space-y-4">
            @csrf
            <div><label class="field-label" for="sale">Sale name (for the file name)</label><input id="sale" name="sale" class="field" placeholder="Billings October 2026"></div>
            <div><label class="field-label" for="lines">Horses</label><textarea id="lines" name="lines" rows="14" class="field font-sans text-[14px]" placeholder="14, HF-N7X8-6R4T&#10;15, HF-XXXX-XXXX" required></textarea>@error('lines')<p class="error">{{ $message }}</p>@enderror</div>
            <button class="btn-forest btn-small">Download QR codes</button>
        </form>
    </div>
</x-layouts.site>
