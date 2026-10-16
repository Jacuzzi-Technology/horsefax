<x-layouts.site title="For owners and breeders" description="Keep your horse's papers, health records, show results and history in one lasting record, and share only what you choose.">
    <x-page-header eyebrow="For owners & breeders" title="Your horse's story, kept in one place." lede="Papers, Coggins, vet and farrier visits, pedigree, show results, photos and sales: organized, dated and ready to share on your terms." />
    <div class="wrap py-16 grid md:grid-cols-2 gap-x-14 gap-y-10">
        @foreach ([
            ['Stop digging for paperwork', 'Keep registration papers, Coggins tests and health certificates with the horse\'s record, with expiry dates in view.'],
            ['Show what you can prove', 'Every entry is labeled self-reported, document supplied or independently verified, so buyers can see exactly what stands behind it.'],
            ['Share without oversharing', 'Health records start private. Send a buyer a report link with only the sections you choose, and revoke it whenever you like.'],
            ['Keep your privacy', 'Report readers never see your name, contact details or address. Ownership history shows dates of changes, not owners\' names.'],
            ['Pedigree, offspring and show record', 'Record the pedigree and show results. When a sire or dam is also on Horsefax, offspring are linked automatically.'],
            ['Pass it on', 'When the horse sells, transfer the record. The new owner accepts it, and the history stays with the horse.'],
        ] as [$h, $p])
            <div><h2 class="text-[26px]">{{ $h }}</h2><p class="mt-2 text-[18px] leading-relaxed">{{ $p }}</p></div>
        @endforeach
    </div>
    <div class="wrap pb-20"><a href="{{ route('horses.create') }}" class="btn-gold">Create a Horse Record</a> <span class="font-sans text-[13px] text-muted ml-3">Creating a record is free.</span></div>
</x-layouts.site>
