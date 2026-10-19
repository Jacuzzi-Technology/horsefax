<x-layouts.site title="Claim a record" robots="noindex, nofollow">
    <div class="wrap py-12 max-w-[760px]">
        <p class="label text-gold">{{ $horse->horsefax_id }}</p>
        <h1 class="text-[36px] mt-2">Claim {{ $horse->displayName() }}</h1>
        <p class="font-sans text-[14px] text-muted mt-1">{{ collect([$horse->breed, $horse->sex ? ucfirst($horse->sex) : null, $horse->birth_year ? 'Foaled '.$horse->birth_year : null, $horse->color])->filter()->join(' · ') }}</p>
        @if ($pending)
            <div class="card p-6 mt-6"><p class="text-[18px]">Your claim for this horse is with Horsefax staff. You'll see the decision on your dashboard.</p></div>
        @else
            <p class="mt-4 text-[16px] card p-4">Have a claim certificate from the seller or sale company? <a href="{{ route('claim-code.form') }}" class="text-gold-dark font-semibold">Enter its code</a> instead: it's instant.</p>
            <p class="mt-4 text-[18px]">If you own this horse, send us proof. Staff review every claim; the current record doesn't change until a claim is approved, and a claim never removes another owner automatically. Your evidence stays private.</p>
            <form method="POST" action="{{ route('horses.claim.store', $horse) }}" enctype="multipart/form-data" class="card p-6 mt-6 space-y-5">
                @csrf
                <div><label class="field-label" for="statement">How you came to own this horse</label><textarea id="statement" name="statement" rows="5" class="field" required minlength="20">{{ old('statement') }}</textarea>@error('statement')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label class="field-label" for="evidence">Evidence (bill of sale, transfer report, papers in your name), up to 5 files</label><input id="evidence" type="file" name="evidence[]" multiple required accept=".pdf,.jpg,.jpeg,.png,.webp,.heic" class="field">@error('evidence')<p class="error">{{ $message }}</p>@enderror @error('evidence.*')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label class="field-label" for="registration_number">Registration number on your papers (optional)</label><input id="registration_number" name="registration_number" value="{{ old('registration_number') }}" class="field" autocomplete="off"><p class="hint text-[13px] mt-1">Staff check it against the number on this record.</p></div>
                <div class="grid sm:grid-cols-2 gap-4">
                    <div><label class="field-label" for="seller_name">Who sold you the horse? (optional)</label><input id="seller_name" name="seller_name" value="{{ old('seller_name') }}" class="field">@error('seller_name')<p class="error">{{ $message }}</p>@enderror</div>
                    <div><label class="field-label" for="seller_email">Seller's email (optional)</label><input id="seller_email" type="email" name="seller_email" value="{{ old('seller_email') }}" class="field">@error('seller_email')<p class="error">{{ $message }}</p>@enderror</div>
                </div>
                <p class="hint text-[13px] -mt-2">If you give the seller's email, Horsefax asks them to confirm the sale. That is the quickest way to get a claim approved.</p>
                @unless (auth()->user()->identity_verified_at)<p class="hint text-[13px]">Tip: <a href="{{ route('identity') }}" class="text-gold-dark">verify your identity</a> so staff can match the name on your papers to you.</p>@endunless
                <button class="btn-gold">Submit claim</button>
            </form>
        @endif
    </div>
</x-layouts.site>
