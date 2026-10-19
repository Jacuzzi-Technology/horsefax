<x-layouts.site :canonical="url('/')">
    {{-- Hero --}}
    <section class="relative z-10 bg-forest text-cream">
        {{-- The mark is clipped on its own layer so search suggestions can drop below the hero. --}}
        <div class="absolute inset-0 overflow-hidden pointer-events-none" aria-hidden="true"><div class="absolute -right-[8vw] -bottom-[10vh] w-[min(62vw,720px)] aspect-[782/899] bg-[url('/img/x-mark.svg')] bg-contain bg-no-repeat opacity-[0.08]"></div></div>
        <div class="wrap relative pt-16 pb-20 sm:pt-24 sm:pb-28">
            <p class="label text-gold-soft mb-6">The lasting record of a horse's life</p>
            <h1 class="text-cream text-[46px] sm:text-[72px] lg:text-[84px] leading-[1.02] tracking-[-0.01em] max-w-[11em]">Every horse has a history&hellip;</h1>
            <p class="mt-6 text-[20px] sm:text-[24px] text-cream/80 max-w-[30em] leading-snug">Look up a horse's history: registration, pedigree, show results and sale records, brought together in one place, with every fact labeled by where it came from.</p>

            <form action="{{ route('search') }}" method="GET" class="mt-10 max-w-[720px]" role="search">
                <label for="hero-name" class="block font-sans text-[11px] font-semibold tracking-[0.2em] uppercase text-cream/70 mb-2">Find a horse by name, registration number or microchip</label>
                <div class="flex flex-col sm:flex-row gap-2 bg-cream/95 p-2 rounded-[3px] shadow-2xl shadow-black/20">
                    <div class="relative flex-1"><input id="hero-name" name="name" type="search" required minlength="2" placeholder="Name, registration # or microchip #" data-suggest="{{ route('search.suggest') }}" class="w-full bg-white rounded-[2px] px-4 py-3 text-[19px] text-charcoal placeholder:text-muted/80 focus:outline-none"></div>
                    <button class="btn-gold">Search Horses</button>
                </div>
                <p class="font-sans text-[12px] text-cream/60 mt-2">Registration and microchip numbers must match exactly. Microchip numbers are never shown on records.</p>
                <p class="font-sans text-[13px] text-gold-soft mt-3">Full records are free with an account during our introductory launch.</p>
            </form>
            <div class="mt-6 flex flex-wrap items-center gap-x-6 gap-y-3">
                <a href="{{ route('horses.create') }}" class="btn-ghost-light">Create a Horse Record</a>
                <a href="{{ route('sample') }}" class="font-sans text-[12px] font-semibold tracking-[0.16em] uppercase text-gold-soft no-underline hover:text-cream">See a sample report →</a>
            </div>
        </div>
    </section>

    {{-- The growing database (Kate's launch review, Oct 18 2026: lead with discovering a horse's history) --}}
    <section class="py-16 sm:py-20 border-b border-line">
        <div class="wrap grid lg:grid-cols-[1.1fr_1fr] gap-12 items-start">
            <div>
                <p class="label text-gold">A growing database</p>
                <h2 class="text-[34px] sm:text-[44px] leading-[1.1] mt-4">Horse history that has been scattered, brought together.</h2>
                <p class="mt-5 text-[19px] leading-relaxed max-w-[32em]">A horse's story is spread across registries, show associations, sale companies and owners' files. Horsefax gathers what has been published into one record per horse, and adds more every day as new sources and new results come in.</p>
                <p class="mt-4 text-[17px] leading-relaxed text-charcoal/85 max-w-[34em]">Some horses already have years of results and sales on file; others have only a few entries so far. Records grow as we add sources and as owners and professionals contribute. A missing record never means nothing happened.</p>
            </div>
            <dl class="grid grid-cols-1 sm:grid-cols-3 lg:grid-cols-1 gap-px bg-line border border-line">
                @foreach ([
                    [$stats['horses'], 'horses on record'],
                    [$stats['results'], 'published show results'],
                    [$stats['sales'], 'auction and sale records'],
                ] as [$n, $label])
                    <div class="bg-paper px-6 py-5"><dt class="sr-only">{{ ucfirst($label) }}</dt><dd><span class="text-[34px] text-ink leading-none">{{ number_format($n) }}</span> <span class="font-sans text-[13px] text-muted">{{ $label }}</span></dd></div>
                @endforeach
            </dl>
        </div>
        <div class="wrap mt-12 grid md:grid-cols-2 gap-6">
            <div class="card p-6">
                <p class="label text-gold-dark text-[11px]">Public-source history</p>
                <h3 class="text-[23px] mt-2">What has already been published</h3>
                <p class="mt-2 text-[17px] leading-relaxed">Show results, pedigrees and auction results that associations, registries and sale companies have made public. Horsefax shows them on the horse's record, each labeled with its source and never marked verified unless we have checked it.</p>
            </div>
            <div class="card p-6">
                <p class="label text-gold-dark text-[11px]">Owner and professional records</p>
                <h3 class="text-[23px] mt-2">What you add stays yours to share</h3>
                <p class="mt-2 text-[17px] leading-relaxed">Papers, health and veterinary records, training notes and anything else an owner or professional adds start private. The owner decides what is published or shared in a report link.</p>
            </div>
        </div>
    </section>

    {{-- Three steps --}}
    <section class="bg-paper border-b border-line">
        <div class="wrap grid md:grid-cols-3 gap-px bg-line">
            @foreach ([
                ['1', 'Search the history', 'Look a horse up by registered name or registration number and see what is already on record. Create a free account to open the full record.'],
                ['2', 'Claim and add to it', 'Own the horse? Claim its record, or start one with a permanent Horsefax ID, and add papers, health, training and results. Every entry shows who added it and how it is known.'],
                ['3', 'Share only what you choose', 'What you add starts private. Publish it on the public record, or send a report link with exactly the sections you pick, and revoke it any time.'],
            ] as [$n, $h, $p])
                <div class="bg-paper p-8 sm:p-10">
                    <p class="font-sans text-[12px] font-semibold tracking-[0.2em] text-gold">STEP {{ $n }}</p>
                    <h2 class="text-[26px] mt-3 leading-tight">{{ $h }}</h2>
                    <p class="mt-3 text-[17px] leading-relaxed text-charcoal/85">{{ $p }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Sample report preview --}}
    <section class="py-20 sm:py-28">
        <div class="wrap grid lg:grid-cols-[1fr_1.15fr] gap-12 items-center">
            <div>
                <p class="label text-gold">The Horsefax report</p>
                <h2 class="text-[38px] sm:text-[48px] leading-[1.08] mt-4">One horse. One record. Every fact labeled.</h2>
                <p class="mt-5 text-[19px] leading-relaxed max-w-[30em]">A Horsefax report shows a horse's identity and history the way a careful buyer, vet or new owner needs to see it: dated, attributed, and clear about what has been checked and what has only been reported.</p>
                <ul class="mt-6 grid grid-cols-2 gap-x-6 gap-y-2 font-sans text-[14px]">
                    @foreach (['Registered name & aliases', 'Registry & numbers', 'Foal date, sex & markings', 'Pedigree & offspring', 'Show record', 'Coggins & health certificates', 'Vaccinations & treatments', 'Sale & ownership history', 'Photos & videos', 'Documents on file'] as $item)
                        <li class="flex items-center gap-2"><svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true"><path d="M2 7.4 5.6 11 12 3" fill="none" stroke="#9F7E4F" stroke-width="1.8"/></svg>{{ $item }}</li>
                    @endforeach
                </ul>
                <a href="{{ route('sample') }}" class="btn-forest mt-8">View the sample report</a>
            </div>
            <div class="card shadow-xl shadow-ink/5 overflow-hidden" aria-label="Preview of a sample Horsefax report with fictional data">
                <div class="bg-forest text-cream px-6 py-4 flex items-center justify-between">
                    <img src="/img/wordmark-reverse.svg" alt="Horsefax" class="h-[18px] w-auto">
                    <span class="font-sans text-[10px] tracking-[0.2em] text-gold-soft">SAMPLE · FICTIONAL DATA</span>
                </div>
                <div class="p-6 sm:p-7">
                    <p class="font-sans text-[11px] tracking-[0.14em] text-gold-dark">HF-SAMP-L3XX</p>
                    <p class="text-[28px] text-ink leading-tight mt-1">Juniper Creek Lark <span class="text-muted text-[20px]">("Lark")</span></p>
                    <p class="font-sans text-[13px] text-muted mt-1">Quarter Horse · Mare · Foaled 2016 · Bay · 15.1 hh</p>
                    <div class="mt-5 space-y-3">
                        @foreach ([
                            ['Registration · Sample Breed Registry #0000000', 'chip-verified', 'Horsefax Verified'],
                            ['Coggins test negative · Mar 2026', 'chip border-[#3b5b7a]/40 text-[#2c4a66] bg-[#3b5b7a]/[0.06]', 'Professional attested'],
                            ['Ranch riding, 2nd place · Sample Fall Classic', 'chip-self', 'Owner reported'],
                            ['Hoof trim and reset · Feb 2026', 'chip-self', 'Owner reported'],
                        ] as [$t, $c, $l])
                            <div class="flex items-start justify-between gap-4 border-b border-line pb-3">
                                <p class="text-[16px]">{{ $t }}</p>
                                <span class="{{ $c }}">{{ $l }}</span>
                            </div>
                        @endforeach
                    </div>
                    <p class="hint mt-4">Health details appear only if the owner chooses to include them.</p>
                </div>
            </div>
        </div>
    </section>

    {{-- Trust explainer --}}
    <section class="bg-ink text-cream py-20 sm:py-28">
        <div class="wrap">
            <div class="max-w-[46em]">
                <p class="label text-gold-soft">What "verified" means here</p>
                <h2 class="text-cream text-[36px] sm:text-[48px] leading-[1.1] mt-4">Verification applies to a fact, never to a whole horse.</h2>
                <p class="mt-5 text-[19px] text-cream/80 leading-relaxed">Horsefax never stamps a horse "verified," "sound" or "safe." Every fact shows where it came from and whether Horsefax has checked it, so you can see exactly what stands behind it. A missing record is not proof that nothing happened.</p>
            </div>
            <p class="label text-gold-soft mt-12">Where it came from</p>
            <div class="mt-4 grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
                @foreach ([
                    ['chip-self', 'Owner reported', 'Entered by the owner or their representative, without independent confirmation.'],
                    ['chip border-[#9db3c9]/50 text-[#c9d8e6] bg-transparent', 'Professional attested', 'Submitted by a veterinarian, farrier or trainer in their own name. It authenticates who said it, not the horse\'s overall soundness.'],
                    ['chip border-cream/50 text-cream bg-transparent', 'Official source', 'Supplied or confirmed by a breed registry, association or other authoritative organization.'],
                    ['chip border-cream/25 text-cream/70 bg-transparent italic', 'Historical / third-party', 'From legacy records, other people\'s pedigrees or submissions whose reliability hasn\'t been established.'],
                ] as [$c, $h, $p])
                    <div class="border border-cream/15 rounded-[3px] p-5 bg-cream/[0.03]">
                        <span class="{{ $c }}">{{ $h }}</span>
                        <p class="mt-3 text-[16px] text-cream/75 leading-relaxed">{{ $p }}</p>
                    </div>
                @endforeach
            </div>
            <p class="label text-gold-soft mt-10">Whether it has been checked</p>
            <div class="mt-4 grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach ([
                    ['chip-verified', 'Horsefax Verified', 'Checked by the Horsefax Verification Team. Click the badge on any fact to see how, when, and exactly what the check covers.'],
                    ['chip-doc', 'Document on file', 'A supporting document is held by Horsefax as evidence.'],
                    ['chip-disputed', 'Disputed', 'Challenged with evidence. It stays visible, labeled, while it is reviewed.'],
                ] as [$c, $h, $p])
                    <div class="border border-cream/15 rounded-[3px] p-5 bg-cream/[0.03]">
                        <span class="{{ $c }}">{{ $h }}</span>
                        <p class="mt-3 text-[16px] text-cream/75 leading-relaxed">{{ $p }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Lifecycle modules --}}
    <section class="py-20 sm:py-28">
        <div class="wrap">
            <p class="label text-gold">A whole life, not a single sale</p>
            <h2 class="text-[36px] sm:text-[46px] leading-[1.1] mt-4 max-w-[18em]">The record follows the horse from foaling to retirement.</h2>
            <div class="mt-12 grid sm:grid-cols-2 lg:grid-cols-3 gap-px bg-line border border-line">
                @foreach ([
                    ['Identity', 'Registered and barn names, registration numbers, microchips (masked), markings, pedigree and photos.'],
                    ['Health', 'Vet visits, vaccinations, Coggins and health certificates. Private by default, shared only when you say so.'],
                    ['Training', 'Trainers, programs and skills, with temperament notes kept as dated, attributed observations.'],
                    ['Performance', 'Shows, classes, placings and awards, with participant claims kept apart from official results.'],
                    ['Ownership', 'Dated stewardship from breeder onward. Transfers need the new owner to accept, and history stays attributed.'],
                    ['Documents', 'Original papers kept as evidence, with checksums, and never edited. Readers see what exists, not your files.'],
                ] as [$h, $p])
                    <div class="bg-paper p-7">
                        <h3 class="text-[23px]">{{ $h }}</h3>
                        <p class="mt-2 text-[17px] leading-relaxed text-charcoal/85">{{ $p }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Contributors --}}
    <section class="bg-forest text-cream py-20 sm:py-24">
        <div class="wrap grid lg:grid-cols-[1fr_1fr] gap-12 items-center">
            <div>
                <p class="label text-gold-soft">Built with the people who know the horse</p>
                <h2 class="text-cream text-[34px] sm:text-[44px] leading-[1.1] mt-4">Owners, breeders, vets, farriers and trainers, each credited for what they add.</h2>
                <p class="mt-5 text-[19px] text-cream/80 leading-relaxed">Owners decide who can see the record. Contributions stay attributed to whoever made them, so a record can't be quietly rewritten to look cleaner.</p>
                <div class="mt-8 flex flex-wrap gap-3">
                    <a href="{{ route('for-owners') }}" class="btn-gold">For owners</a>
                    <a href="{{ route('for-professionals') }}" class="btn-ghost-light">For professionals</a>
                </div>
            </div>
            <ul class="grid grid-cols-2 gap-3 font-sans text-[13px] font-semibold tracking-[0.12em] uppercase">
                @foreach (['Owners', 'Breeders', 'Veterinarians', 'Farriers', 'Trainers', 'Registries (future partners)'] as $who)
                    <li class="border border-cream/15 rounded-[3px] px-4 py-4 flex items-center gap-3"><span class="w-1.5 h-1.5 bg-gold rotate-45 shrink-0"></span>{{ $who }}</li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- CTA --}}
    <section class="py-20 sm:py-24 text-center">
        <div class="wrap">
            <img src="/img/hf-mark.svg" alt="" class="w-[120px] h-auto mx-auto mb-8">
            <h2 class="text-[36px] sm:text-[48px] leading-[1.1]">Start your horse's Horsefax record.</h2>
            <p class="mt-4 text-[19px] text-muted max-w-[32em] mx-auto">Creating a record is free. Everything you add is private until you choose to share it.</p>
            <a href="{{ route('horses.create') }}" class="btn-gold mt-8">Create a Horse Record</a>
        </div>
    </section>
</x-layouts.site>
