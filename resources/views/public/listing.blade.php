<x-layouts.site :title="$horse->displayName()" robots="noindex, follow">
    <section class="bg-forest text-cream">
        <div class="wrap py-12">
            <p class="label text-gold-soft">Horsefax record · {{ $horse->horsefax_id }}</p>
            <h1 class="text-cream text-[38px] sm:text-[50px] mt-3">{{ $horse->displayName() }}</h1>
            <p class="font-sans text-[14px] text-cream/75 mt-3">{{ collect([$horse->breed, $horse->sex ? ucfirst($horse->sex) : null, $horse->birth_year ? 'Foaled '.$horse->birth_year : null, $horse->color])->filter()->join(' · ') }}</p>
            <p class="mt-4"><span class="{{ $horse->identity_status === 'verified' ? 'chip-verified' : ($horse->identity_status === 'under_review' ? 'chip-disputed' : 'chip-self') }}">{{ $horse->identityLabel() }}</span></p>
        </div>
    </section>
    <div class="wrap py-12 grid md:grid-cols-2 gap-6 max-w-[960px]">
        <div class="card p-6">
            <h2 class="text-[24px]">This record is private</h2>
            <p class="mt-2 text-[17px]">The owner keeps this horse's history private. If you are considering the horse, ask the owner or seller to send you a Horsefax report link.</p>
        </div>
        <div class="card p-6">
            <h2 class="text-[24px]">Is this your horse?</h2>
            <p class="mt-2 text-[17px]">If you own this horse, you can claim the record with proof of ownership. Horsefax staff review every claim.</p>
            <a href="{{ route('horses.claim', $horse) }}" class="btn-forest btn-small mt-4">Claim this record</a>
        </div>
    </div>
</x-layouts.site>
