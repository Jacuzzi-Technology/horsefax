{{-- Rewritten Oct 19 2026 under the factual record policy: professionals contribute documented history, not opinions. --}}
<x-layouts.site title="For professionals and industry partners" description="How registries, sale companies, breeders, trainers and veterinarians contribute documented, attributed records to a horse's Horsefax history.">
    <x-page-header eyebrow="For professionals" title="Documented history, credited to its source." lede="Horsefax brings together what can be documented about a horse's life, each entry attributed to the person or organization it came from." />
    <section class="bg-white py-16 sm:py-20 border-b border-line">
        <div class="wrap grid sm:grid-cols-2 lg:grid-cols-3 gap-x-10 gap-y-9">
            @foreach ([
                ['Registries & associations', 'Official records and published results.'],
                ['Sale companies & sellers', 'Historical listings and documented sale results, with the price the sale reported.'],
                ['Breeders', 'Registration, breeding and pedigree documentation.'],
                ['Trainers', 'Dated, documentable training periods and competition participation: who, where and when.'],
                ['Veterinarians & farriers', 'Authenticated professional records, shared only with the owner\'s authorization and the record\'s privacy safeguards.'],
            ] as [$h, $p])
                <div><h2 class="text-[25px]">{{ $h }}</h2><p class="mt-2 text-[17px] leading-relaxed text-charcoal/85">{{ $p }}</p></div>
            @endforeach
        </div>
    </section>
    <div class="wrap py-16 prose-hf max-w-[46em]">
        <h2 class="!mt-0">How contributions work</h2>
        <ul>
            <li><strong>Attributed:</strong> each entry shows who contributed it and in what role.</li>
            <li><strong>Authorized:</strong> professionals contribute to a horse's record by the owner's invitation, and the owner decides what of it is shared.</li>
            <li><strong>Protected:</strong> no one can silently rewrite what a professional recorded. Disagreements go through the correction process, and the history is kept.</li>
            <li><strong>Factual only:</strong> Horsefax accepts documented facts, not opinions about temperament, suitability, ability or soundness. See our <a href="{{ route('factual-record-policy') }}">factual record policy</a>.</li>
            <li><strong>Source, not endorsement:</strong> a verified professional is a verified source; Horsefax labels each fact by how it is known.</li>
        </ul>
        <h2>Working with Horsefax</h2>
        <p>Owners can invite their veterinarian, farrier, trainer or breeder to contribute in their own name today. For registries, associations, sale companies and veterinary software providers, we're building data partnerships so records arrive directly from their source, with the owner's permission where it's required.</p>
        <p>Registries, associations, sale companies and breeders can read about <a href="{{ route('partners') }}">partnering with Horsefax</a> and send a partnership inquiry. Individual professionals can <a href="{{ route('contact') }}">get in touch</a>.</p>
    </div>
</x-layouts.site>
