{{-- Partners & affiliates (Richard, Oct 19 2026). Three ways to collaborate, described rather than sold as membership
     programs: data partners contribute documented records, affiliate partners offer Horsefax reports to their customers,
     integrated partners do both. Promise only what exists (source labels, links back, QR codes, report links); no
     traffic, lead or sales claims until there is data behind them. Independence is stated plainly: partnership never
     buys favorable treatment. --}}
@php $interests = \App\Http\Controllers\PublicController::PARTNER_INTERESTS; @endphp
<x-layouts.site title="Partner with Horsefax" description="Registries, show associations, sale companies, breeders and equine businesses: contribute documented records, offer Horsefax reports to your customers, or both.">
    <x-page-header eyebrow="Horsefax partnerships" title="Help build a more complete history of every horse." lede="The equine industry's history is spread across registries, competition organizations, sale companies and thousands of individual businesses. Horsefax is bringing those records together into a lasting, searchable resource.">
        <div class="mt-8 flex flex-wrap items-center gap-4">
            <a href="#inquiry" class="btn-gold">Start a partnership inquiry</a>
            <a href="{{ route('sample') }}" class="btn-ghost-light">See a sample report</a>
        </div>
    </x-page-header>

    {{-- Why --}}
    <section class="bg-white py-20 sm:py-24 border-b border-line">
        <div class="wrap grid lg:grid-cols-[1.1fr_1fr] gap-12 items-start">
            <div>
                <p class="label text-gold">Every horse has a history</p>
                <h2 class="text-[36px] sm:text-[44px] leading-[1.1] mt-4">Together, we can preserve more of it.</h2>
            </div>
            <div class="space-y-4 text-[18px] leading-relaxed text-charcoal/85 lg:pt-10">
                <p>Across the equine industry, valuable information is created every day. Competition results, registration records, pedigrees and sale histories tell the story of a horse's life, and too often that information stays scattered across different organizations, websites and archives.</p>
                <p>Horsefax brings those records together, preserves their sources and makes them easier to discover. We're looking to work with equine businesses and organizations that share our commitment to accurate, transparent historical information.</p>
            </div>
        </div>
    </section>

    {{-- Ways to partner --}}
    <section class="bg-paper py-20 sm:py-24 border-b border-line">
        <div class="wrap">
            <p class="label text-gold">Ways to partner</p>
            <h2 class="text-[36px] sm:text-[44px] leading-[1.1] mt-4 max-w-[18em]">Contribute records, offer reports, or both.</h2>
            <p class="mt-4 text-[18px] leading-relaxed max-w-[38em] text-charcoal/85">These aren't separate membership programs. They're the ways organizations can work with Horsefax, and many will do more than one.</p>
            <div class="mt-12 grid md:grid-cols-3 gap-px bg-line border border-line">
                @foreach ([
                    ['Data partners', 'Contribute historical records', 'Registries, competition associations, sale companies and breeders can contribute documented information: registration identities, pedigrees, official competition results, historical sale listings and documented transactions. Each source is evaluated for reliability, identity matching and appropriate use before its records are included.'],
                    ['Affiliate partners', 'Offer Horsefax reports to your customers', 'Sale companies, marketplaces, dealers and breeders can add Horsefax report links and QR codes to their catalogs, listings and customer materials, giving buyers an independent historical resource on the horses they\'re considering.'],
                    ['Integrated partners', 'Both, connected to the horse\'s history', 'Organizations that contribute their records and offer Horsefax reports through their own websites, catalogs and sales. Their records appear on each horse labeled with the source and, where appropriate, a link back to the original.'],
                ] as $i => [$kind, $h, $p])
                    <div class="bg-white p-8 sm:p-10">
                        <p class="font-sans text-[12px] font-semibold tracking-[0.2em] text-gold uppercase">{{ $kind }}</p>
                        <h3 class="text-[25px] mt-3 leading-tight">{{ $h }}</h3>
                        <p class="mt-3 text-[17px] leading-relaxed text-charcoal/85">{{ $p }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- What partners receive --}}
    <section class="bg-white py-20 sm:py-24 border-b border-line">
        <div class="wrap">
            <p class="label text-gold">What partners receive</p>
            <h2 class="text-[36px] sm:text-[44px] leading-[1.1] mt-4 max-w-[18em]">Your records, credited and kept.</h2>
            <div class="mt-12 grid sm:grid-cols-2 lg:grid-cols-3 gap-x-10 gap-y-8">
                @foreach ([
                    ['Permanent source attribution', 'When your information appears on a Horsefax record, it is labeled with your organization as its source.'],
                    ['Links back to the original', 'Where appropriate, records link to the original result, catalog or listing on your website.'],
                    ['Catalog QR codes', 'A QR code for each horse, with the Horsefax mark, that buyers scan from a printed catalog or stall card to open its history.'],
                    ['Report links for listings', 'Horse-specific history links you can add to online listings and sale pages.'],
                    ['Historical preservation', 'Your results and sales stay discoverable after the original listing or catalog has come down.'],
                    ['Value for your customers', 'Buyers and sellers get access to more of a horse\'s documented history, from a source independent of the sale.'],
                    ['Partner recognition', 'Participating organizations may be recognized as Horsefax partners.'],
                ] as [$h, $p])
                    <div class="border-l-2 border-gold pl-5"><h3 class="text-[21px]">{{ $h }}</h3><p class="mt-1.5 text-[17px] leading-relaxed text-charcoal/80">{{ $p }}</p></div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Independence --}}
    <section class="bg-ink text-cream py-20 sm:py-24">
        <div class="wrap grid lg:grid-cols-[1fr_1fr] gap-12 items-start">
            <div>
                <p class="label text-gold-soft">Built on facts, not opinions</p>
                <h2 class="text-cream text-[34px] sm:text-[44px] leading-[1.1] mt-4">Independent, with the same standards for everyone.</h2>
                <p class="mt-5 text-[18px] text-cream/80 leading-relaxed">Horsefax records documented history, not reviews, personal evaluations or opinions about a horse's temperament, suitability or quality. Every entry is labeled by its source and how it is known, and information that hasn't been independently confirmed is never shown as verified. See our <a href="{{ route('factual-record-policy') }}" class="text-gold-soft">factual record policy</a>.</p>
            </div>
            <ul class="space-y-5 lg:pt-12">
                @foreach ([
                    'No partner can buy favorable treatment of the history on its horses.',
                    'No partner can have accurate information removed, including past sale results.',
                    'Partnership never raises a record\'s verification status. Verification comes only from checking the facts.',
                    'Disagreements about a record go through the same correction process for everyone, and the history is kept.',
                ] as $line)
                    <li class="flex gap-4 text-[18px] text-cream/90 leading-relaxed"><span class="w-2 h-2 mt-2.5 bg-gold rotate-45 shrink-0"></span>{{ $line }}</li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- Inquiry --}}
    <section id="inquiry" class="bg-paper py-20 sm:py-24 scroll-mt-4">
        <div class="wrap grid lg:grid-cols-[1fr_1.2fr] gap-12 items-start">
            <div>
                <p class="label text-gold">Let's explore a partnership</p>
                <h2 class="text-[36px] sm:text-[44px] leading-[1.1] mt-4">Tell us about your organization.</h2>
                <p class="mt-5 text-[18px] leading-relaxed text-charcoal/85">Whether you maintain thousands of historical records or run a business serving individual horse owners, we'd welcome the chance to learn about your organization: the records you keep and how you'd like to work with Horsefax.</p>
                <p class="mt-6 text-[20px] italic text-ink">Help us preserve the history of the horses that have shaped our industry.</p>
            </div>
            <form method="POST" action="{{ route('partners.send') }}" class="card p-6 sm:p-8 space-y-5">
                @csrf
                <input type="text" name="website_url" class="hidden" tabindex="-1" autocomplete="off">
                <x-input name="organization" label="Organization name" required />
                <div class="grid sm:grid-cols-2 gap-5">
                    <x-input name="name" label="Contact name" required />
                    <x-input name="email" type="email" label="Email" required />
                </div>
                <x-input name="website" label="Website" placeholder="example.com" />
                <fieldset>
                    <legend class="field-label">Partnership interest <span class="text-gold-dark">*</span></legend>
                    <div class="mt-2 space-y-2 font-sans text-[15px]">
                        @foreach ($interests as $k => $v)
                            <label class="flex items-center gap-3"><input type="radio" name="interest" value="{{ $k }}" @checked(old('interest', 'records') === $k) required> {{ $v }}</label>
                        @endforeach
                    </div>
                    @error('interest')<p class="error">{{ $message }}</p>@enderror
                </fieldset>
                <x-input name="record_count" label="Approximate number of horse records you maintain" hint="Optional. A rough figure is fine, for example “about 5,000 a year since 2010”." />
                <div>
                    <label class="field-label" for="f-message">Tell us about your organization <span class="text-gold-dark">*</span></label>
                    <textarea id="f-message" name="message" rows="6" class="field" required>{{ old('message') }}</textarea>
                    @error('message')<p class="error">{{ $message }}</p>@enderror
                </div>
                <button class="btn-gold">Send inquiry</button>
            </form>
        </div>
    </section>
</x-layouts.site>
