<x-layouts.site title="Verify your identity" robots="noindex, nofollow">
    <div class="wrap py-12 max-w-[680px]">
        <p class="label text-gold">Your account</p>
        <h1 class="text-[36px] mt-2">Verify your identity</h1>
        @if ($user->identity_verified_at)
            <div class="card p-6 mt-6"><p class="text-[18px]">Your identity was verified on {{ $user->identity_verified_at->format('M j, Y') }} as {{ $user->identity_verified_name }}.</p></div>
        @elseif ($pending)
            <div class="card p-6 mt-6"><p class="text-[18px]">Your ID is with Horsefax staff. You'll see the result on your account page.</p></div>
        @else
            <p class="mt-4 text-[18px]">A verified identity strengthens your ownership claims: staff can match the name on your registration papers or bill of sale to you. It is never shown publicly.</p>
            <form method="POST" action="{{ route('identity.submit') }}" enctype="multipart/form-data" class="card p-6 mt-6 space-y-5">
                @csrf
                <div><label class="field-label" for="legal_name">Your full legal name, as on your ID</label><input id="legal_name" name="legal_name" value="{{ old('legal_name', $user->name) }}" required class="field">@error('legal_name')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label class="field-label" for="document">A photo of a government-issued ID (driver's license or passport)</label><input id="document" type="file" name="document" required accept=".jpg,.jpeg,.png,.webp,.heic,.pdf" class="field">@error('document')<p class="error">{{ $message }}</p>@enderror</div>
                <label class="flex items-start gap-2 font-sans text-[14px]"><input type="checkbox" name="consent" value="1" required class="accent-gold mt-0.5"> I agree that Horsefax staff may view this ID to confirm who I am. Horsefax deletes the image as soon as the check is decided and keeps only the result.</label>
                @error('consent')<p class="error">{{ $message }}</p>@enderror
                <button class="btn-gold">Send for checking</button>
            </form>
        @endif
    </div>
</x-layouts.site>
