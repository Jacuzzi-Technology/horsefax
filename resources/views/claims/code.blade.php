<x-layouts.site title="Claim your horse" robots="noindex, nofollow">
    <div class="wrap py-12 max-w-[640px]">
        <p class="label text-gold">Claim code</p>
        <h1 class="text-[36px] mt-2">Claim your horse with a code</h1>
        <p class="mt-4 text-[18px]">If the seller or sale company gave you a Horsefax claim certificate, enter its code here. When the horse has no other owner on Horsefax, you become its verified owner straight away.</p>
        <form method="POST" action="{{ route('claim-code.redeem') }}" class="card p-6 mt-6 space-y-4">
            @csrf
            <div>
                <label class="field-label" for="code">Claim code</label>
                <input id="code" name="code" value="{{ old('code', $code) }}" required autocomplete="off" placeholder="XXXX-XXXX-XXXX" class="field font-sans tracking-[0.2em] uppercase">
                @error('code')<p class="error">{{ $message }}</p>@enderror
            </div>
            <button class="btn-gold">Claim this horse</button>
        </form>
        <p class="hint mt-4">No code? Open the horse's record and choose "Claim this record" to send proof of ownership instead.</p>
    </div>
</x-layouts.site>
