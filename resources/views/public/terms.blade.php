<x-layouts.site title="Terms of Use" description="The terms for using Horsefax.">
    <x-page-header eyebrow="Legal" title="Terms of Use" lede="Last updated October 16, 2026" />
    <div class="wrap py-14 prose-hf max-w-[46em]">
        <p>These terms are an agreement between you and {{ config('horsefax.operator') }} ("Horsefax," "we," "us") for your use of horsefax.com (the "Service"). By creating an account or using the Service, you agree to them and to our <a href="{{ route('privacy') }}">Privacy Policy</a>.</p>

        <h2>1. Accounts</h2>
        <p>You must be at least 18 and give accurate information. Keep your password secure; you are responsible for activity on your account. We may suspend accounts that break these terms or put other users or records at risk.</p>

        <h2>2. What you add</h2>
        <ul>
            <li>You may only add information, documents, photos and videos that you have the right to share. For photos and videos, you confirm that you hold the necessary rights and releases from the photographer or videographer and from any person shown.</li>
            <li>Information must be true to the best of your knowledge. Do not add false, misleading or fraudulent records, impersonate anyone, or claim a horse you do not own.</li>
            <li>You keep ownership of what you add. You give Horsefax a non-exclusive, worldwide, royalty-free license to store, process, and display it to the people you choose, as needed to provide the Service, and to keep it as part of the record's history and audit trail.</li>
        </ul>

        <h2>3. Records, verification and corrections</h2>
        <ul>
            <li>Each fact on Horsefax is labeled to show how it is known (self-reported, document supplied, independently verified, disputed or superseded). A label applies only to that fact.</li>
            <li><strong>Horsefax does not certify any horse as sound, healthy, safe or suitable</strong>, and a missing record is not evidence that something did not happen. Records are not a substitute for a pre-purchase examination, a veterinarian's advice, or checking registration with the registry.</li>
            <li>To keep records trustworthy, entries are corrected by adding to the history rather than deleting it. Staff may label entries as disputed, review ownership claims and identifier conflicts, and make decisions that are logged with reasons.</li>
        </ul>

        <h2>4. Sharing and transfers</h2>
        <p>You control what is shared through public summaries and report links. Anyone you give a report link to can see what it includes until it expires or you revoke it, and may have copied what they saw. When a horse changes hands, the record transfers to the new owner, while history stays attributed to whoever contributed it.</p>

        <h2>5. Acceptable use</h2>
        <p>Do not misuse the Service. In particular, do not: access records or accounts you are not authorized to see; probe, scan or test the Service's security; use bots, scrapers or other automated means to collect content or data without our written permission; get around rate limits or access controls; use Horsefax data to build or train a competing database, directory or AI model; or harvest anyone's personal information.</p>

        <h2>6. Fees</h2>
        <p>Creating a record is currently free. If we introduce paid features, we will describe them and their prices before you are charged.</p>

        <h2>7. Our rights</h2>
        <p>The Service, its design and software, and the Horsefax™ name, logos and the tagline "Because Every Horse Has A History."™ belong to {{ config('horsefax.operator') }}. These terms do not give you any right to use them except as part of using the Service.</p>

        <h2>8. Disclaimers</h2>
        <p>THE SERVICE IS PROVIDED "AS IS" AND "AS AVAILABLE." TO THE FULLEST EXTENT ALLOWED BY LAW, HORSEFAX DISCLAIMS ALL WARRANTIES, EXPRESS OR IMPLIED, INCLUDING WARRANTIES OF ACCURACY, MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NON-INFRINGEMENT. MUCH OF THE CONTENT IS PROVIDED BY USERS, AND WE DO NOT GUARANTEE IT IS COMPLETE OR CORRECT.</p>

        <h2>9. Limitation of liability</h2>
        <p>TO THE FULLEST EXTENT ALLOWED BY LAW, HORSEFAX WILL NOT BE LIABLE FOR INDIRECT, INCIDENTAL, SPECIAL, CONSEQUENTIAL OR PUNITIVE DAMAGES, OR FOR LOST PROFITS, DATA OR GOODWILL, OR FOR ANY DECISION TO BUY, SELL, TREAT OR USE A HORSE. OUR TOTAL LIABILITY FOR ANY CLAIM RELATING TO THE SERVICE WILL NOT EXCEED THE GREATER OF THE AMOUNT YOU PAID US IN THE 12 MONTHS BEFORE THE CLAIM OR $100.</p>

        <h2>10. Indemnity</h2>
        <p>You agree to defend and hold Horsefax harmless from claims arising from content you add or your breach of these terms.</p>

        <h2>11. Ending</h2>
        <p>You may stop using the Service at any time. We may suspend or end access for breach of these terms. Records and history may remain as described in the Privacy Policy.</p>

        <h2>12. Changes</h2>
        <p>We may update these terms. We will post the new version here with a new date, and tell signed-in users about significant changes. Continuing to use the Service means you accept the updated terms.</p>

        <h2>13. Contact</h2>
        <p>Use our <a href="{{ route('contact') }}">contact form</a>.</p>
    </div>
</x-layouts.site>
