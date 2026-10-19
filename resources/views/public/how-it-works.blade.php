<x-layouts.site title="How it works" description="Create a Horsefax record, add evidence-backed history, share only what you choose, and transfer the record when the horse changes hands.">
    <x-page-header eyebrow="How Horsefax works" title="Create, verify, contribute, share and transfer." lede="A record that belongs to the horse, kept honest by evidence and attribution." />
    <div class="wrap py-16 grid lg:grid-cols-[220px_1fr] gap-12">
        <nav class="hidden lg:block sticky top-6 self-start font-sans text-[12px] font-semibold tracking-[0.14em] uppercase space-y-3" aria-label="On this page">
            <a href="#create" class="block no-underline text-ink hover:text-gold-dark">Create</a>
            <a href="#verification" class="block no-underline text-ink hover:text-gold-dark">Verification</a>
            <a href="#contribute" class="block no-underline text-ink hover:text-gold-dark">Contribute</a>
            <a href="#share" class="block no-underline text-ink hover:text-gold-dark">Share</a>
            <a href="#transfer" class="block no-underline text-ink hover:text-gold-dark">Transfer</a>
            <a href="#correct" class="block no-underline text-ink hover:text-gold-dark">Correct</a>
        </nav>
        <div class="prose-hf max-w-[44em]">
            <h2 id="create" class="!mt-0">1. Create a record</h2>
            <p>Sign up, then enter what identifies the horse: registered and barn names, breed, sex, foaling date, color and markings, and any registration or microchip numbers. Before a new record is made, Horsefax checks whether the horse is already here. If a registration or microchip number is already on another record, both are flagged for staff review. Nothing is merged or overwritten automatically.</p>
            <p>Every record gets a permanent <strong>Horsefax ID</strong> (for example HF-7K3M-Q9P2). It stays with the horse for life and is never reused.</p>

            <h2 id="verification">2. Add evidence, and see how each fact is known</h2>
            <p>Upload registration papers, Coggins tests, health certificates, vet and farrier records, show results and bills of sale. Originals are stored as evidence and never edited. Every fact on a record shows two separate things.</p>
            <h3>Where it came from</h3>
            <ul>
                <li><strong>Owner reported</strong>: entered by the owner or their authorized representative.</li>
                <li><strong>Professional attested</strong>: submitted by a veterinarian, farrier, trainer or breeder in their own name. A ✓ means Horsefax has checked their credentials. It authenticates who said it, not the horse's overall soundness.</li>
                <li><strong>Official source</strong>: supplied or confirmed by a breed registry, competition association or other authoritative organization.</li>
                <li><strong>Historical / third-party</strong>: from legacy records, other people's pedigrees or submissions whose reliability hasn't been established.</li>
            </ul>
            <h3>Whether it has been checked</h3>
            <ul>
                <li><strong>Horsefax Verified</strong>: checked by the Horsefax Verification Team. Click the badge to see how it was checked, when, any recheck date, and exactly what the verification covers and doesn't.</li>
                <li><strong>Not verified</strong>: not yet checked. That says nothing bad about it; most information starts here.</li>
                <li><strong>Disputed</strong>: challenged with evidence and under review.</li>
                <li><strong>Superseded</strong>: replaced by a later correction. The original stays in the record history.</li>
            </ul>
            <p>A source can be verified: an owner might upload a registration certificate (owner reported), and once Horsefax confirms it with the registry it becomes Horsefax Verified. Each entry also shows when it happened, when it was reported, and when it was verified.</p>
            <p>Horsefax never marks a whole horse as "verified" and never gives a horse a score. Instead a record says plainly what has been established, for example "Identity verified · Pedigree confirmed · 3 professional records · 4 owner-reported entries." A horse's identity can be verified without implying that everything else on its record has been.</p>

            <h2 id="contribute">3. Build the history</h2>
            <p>Add dated entries for health and care, training, competition, ownership and sales, and transport. Entries stay attributed to the person who added them. To change one of your own entries you add a correction, which supersedes it; the original remains in the history. Invitations for veterinarians, farriers and trainers to add their own attributed entries are coming next.</p>

            <h2 id="share">4. Share only what you choose</h2>
            <p>Everything you add starts private. (History Horsefax collects from public sources, such as published show and sale results, appears on the record labeled with its source.) You can:</p>
            <ul>
                <li>publish a <strong>public summary</strong>, which shows only the items you mark Public, and lets people find the horse in Horsefax search;</li>
                <li>create a <strong>report link</strong> for a buyer, vet or trainer, picking exactly which sections it includes, with an optional expiry. You can revoke it at any moment, and it stops working at once.</li>
            </ul>
            <p>Report readers never see your name or contact details, previous owners' identities, or your original files, and microchip numbers are always masked.</p>

            <h2 id="transfer">5. Transfer when the horse changes hands</h2>
            <p>The owner starts a transfer to the new owner's email; the new owner accepts it from their Horsefax account. History stays attributed to whoever added it, and the previous owner can no longer edit. If someone believes they own a horse already on Horsefax, they can submit an ownership claim with private evidence, which staff review. A claim never displaces the current owner automatically.</p>

            <h2 id="correct">6. Correct and dispute</h2>
            <p>Anyone can request a correction from a record or report page. Staff review the evidence; an upheld challenge marks the entry Disputed rather than deleting it. See <a href="{{ route('record-corrections') }}">record corrections</a>.</p>

            <div class="not-prose mt-10 flex flex-wrap gap-3"><a href="{{ route('horses.create') }}" class="btn-gold">Create a Horse Record</a><a href="{{ route('sample') }}" class="btn-ghost">See a sample report</a></div>
        </div>
    </div>
</x-layouts.site>
