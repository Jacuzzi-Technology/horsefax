{{-- For owners & breeders, rebuilt from Kate's review (Oct 19 2026): the value of a lasting history first, then how
     to start, then breeders. Only features that exist today are described. Positioning (Richard): Horsefax is the
     history of a horse's life, not horse-management software (that's horsefax.ai's space): no care schedules, reminders
     or barn-management language here. --}}
<x-layouts.site title="For owners and breeders" description="The history of your horse's life in one lasting record: registration, pedigree, competition, sales and ownership, every fact labeled by its source. Claim it, add what you can document, and share it when you choose.">
    <x-page-header eyebrow="For owners & breeders" title="Their history deserves to be remembered." lede="From registration and pedigree to competition, sales and ownership, Horsefax brings the history of your horse's life together in one lasting record, every fact labeled by where it came from. Claim it, add what you can document, and share it when you choose.">
        <div class="mt-8 flex flex-wrap items-center gap-4">
            <a href="{{ route('horses.create') }}" class="btn-gold">Create a free horse record</a>
            <a href="{{ route('search') }}" class="btn-ghost-light">Search for your horse first</a>
        </div>
    </x-page-header>

    {{-- Three core benefits --}}
    <section class="bg-white py-20 sm:py-24 border-b border-line">
        <div class="wrap">
            <div class="grid md:grid-cols-3 gap-10 lg:gap-14">
                @foreach ([
                    ['Preserve their history', 'Registration, pedigree, competition results, sales, photographs and the documents that prove them, in one permanent record that grows throughout the horse\'s life and outlasts any one owner.'],
                    ['Control what you share', 'Anything you add starts private. Share it on the public record, or send chosen sections to a buyer through a report link you can revoke. History from published sources, such as show and sale results, stays part of the record.'],
                    ['Build a lasting legacy', 'Follow your horse\'s life on its timeline. When the horse changes hands, its documented history stays with the horse, so its accomplishments and milestones are there for every future owner.'],
                ] as $i => [$h, $p])
                    <div>
                        <p class="font-sans text-[12px] font-semibold tracking-[0.2em] text-gold">0{{ $i + 1 }}</p>
                        <h2 class="text-[30px] leading-tight mt-3">{{ $h }}</h2>
                        <p class="mt-4 text-[18px] leading-relaxed text-charcoal/85">{{ $p }}</p>
                    </div>
                @endforeach
            </div>

            <div class="mt-16 pt-10 border-t border-line grid sm:grid-cols-2 lg:grid-cols-3 gap-x-10 gap-y-7">
                @foreach ([
                    ['Health and documents over time', 'Coggins tests, exams, registration papers and bills of sale accumulate year after year into the horse\'s documented history, kept unedited as evidence.'],
                    ['Every fact labeled', 'Owner reported, professional attested, official source or Horsefax Verified: readers see exactly what stands behind each entry.'],
                    ['Your privacy kept', 'Report readers never see your name, contact details or address. Ownership history shows when it changed, not who owned the horse.'],
                    ['Pedigree and offspring', 'When a sire or dam is also on Horsefax, records link across generations and offspring appear automatically.'],
                    ['Professionals vouch for it', 'Trainers, veterinarians and others can add what they know in their own name, which carries more weight than an owner\'s word.'],
                    ['Print or download', 'Any record or report downloads as a PDF with a QR code that opens the current online version.'],
                ] as [$h, $p])
                    <div><h3 class="text-[21px]">{{ $h }}</h3><p class="mt-1.5 text-[17px] leading-relaxed text-charcoal/80">{{ $p }}</p></div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Getting started: the horse may already be on Horsefax --}}
    <section class="bg-paper py-20 sm:py-24 border-b border-line">
        <div class="wrap">
            <p class="label text-gold">Getting started</p>
            <h2 class="text-[36px] sm:text-[44px] leading-[1.1] mt-4 max-w-[18em]">Your horse may already be on Horsefax.</h2>
            <p class="mt-4 text-[18px] leading-relaxed max-w-[38em] text-charcoal/85">Horsefax has records for millions of horses built from registries, published show results, pedigrees and sale listings. Start by searching, so the history already gathered stays in one record.</p>
            <ol class="mt-12 grid md:grid-cols-3 gap-px bg-line border border-line">
                @foreach ([
                    ['Search for your horse', 'Look it up by registered name, registration number or microchip number. Horsefax may already have its registration, pedigree, show results or sale history.'],
                    ['Claim it, or create it', 'Found it? Claim the record with proof of ownership, or a claim code from the seller, and Horsefax staff confirm it. Not there yet? Create a new record with a permanent Horsefax ID.'],
                    ['Add to their story', 'Add what you can document: papers, photographs, accomplishments and the milestones that belong in its history. You choose what of it to share.'],
                ] as $i => [$h, $p])
                    <li class="bg-white p-8 sm:p-10">
                        <p class="font-sans text-[12px] font-semibold tracking-[0.2em] text-gold">STEP {{ $i + 1 }}</p>
                        <h3 class="text-[25px] mt-3 leading-tight">{{ $h }}</h3>
                        <p class="mt-3 text-[17px] leading-relaxed text-charcoal/85">{{ $p }}</p>
                    </li>
                @endforeach
            </ol>
            <form action="{{ route('search') }}" method="GET" class="mt-10 max-w-[640px] flex flex-col sm:flex-row gap-2" role="search">
                <label for="owners-search" class="sr-only">Name, registration number or microchip number</label>
                <input id="owners-search" name="name" type="search" required minlength="2" placeholder="Name, registration # or microchip #" data-suggest="{{ route('search.suggest') }}" class="field flex-1">
                <button class="btn-forest">Search</button>
            </form>
        </div>
    </section>

    {{-- Breeders --}}
    <section class="bg-white py-20 sm:py-24 border-b border-line">
        <div class="wrap grid lg:grid-cols-[1.1fr_1fr] gap-12 items-start">
            <div>
                <p class="label text-gold">For breeders</p>
                <h2 class="text-[36px] sm:text-[44px] leading-[1.1] mt-4">Your breeding program has a history worth preserving.</h2>
                <p class="mt-5 text-[18px] leading-relaxed text-charcoal/85">Follow the accomplishments of the horses you've bred, connect pedigrees across generations, and keep a lasting record of your program. As more horses and results come into Horsefax, the history of your program becomes more complete.</p>
            </div>
            <ul class="space-y-5 lg:pt-12">
                @foreach ([
                    ['Pedigrees that connect', 'Enter a pedigree once; every sire and dam in it becomes a linked record, so families can be followed across generations.'],
                    ['Offspring on the record', 'A stallion or mare\'s record lists the offspring Horsefax knows about, each linked to its own show and sale history.'],
                    ['Results that find their way back', 'Published show results and sale history attach to the horses you produced as Horsefax matches them, so their accomplishments reflect on your program.'],
                ] as [$h, $p])
                    <li class="border-l-2 border-gold pl-5"><h3 class="text-[22px]">{{ $h }}</h3><p class="mt-1 text-[17px] leading-relaxed text-charcoal/85">{{ $p }}</p></li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- Final call to action --}}
    <section class="bg-forest text-cream py-20 sm:py-24 text-center">
        <div class="wrap">
            <h2 class="text-cream text-[36px] sm:text-[48px] leading-[1.1]">Start preserving their history.</h2>
            <p class="mt-4 text-[19px] text-cream/80 max-w-[34em] mx-auto">Search for your horse, or create a free record. Everything you add starts private until you choose to share it.</p>
            <div class="mt-8 flex flex-wrap justify-center gap-4">
                <a href="{{ route('search') }}" class="btn-ghost-light">Search for your horse</a>
                <a href="{{ route('horses.create') }}" class="btn-gold">Create a free horse record</a>
            </div>
        </div>
    </section>
</x-layouts.site>
