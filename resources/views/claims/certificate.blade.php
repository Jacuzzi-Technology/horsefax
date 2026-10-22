{{-- Printable claim certificate: handed to the buyer by the seller or sale company. --}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
    <title>Horsefax claim certificate · {{ $code->horse->displayName() }}</title>
    @vite(['resources/css/app.css'])
    <style>@media print { .no-print { display: none !important } body { background: #fff } @page { size: letter; margin: 0.6in } }</style>
</head>
<body class="bg-paper">
    <div class="no-print wrap py-4 flex flex-wrap gap-3 items-center font-sans text-[13px]">
        <button onclick="window.print()" class="btn-forest btn-small">Print</button>
        <a href="{{ route('admin.horse', $code->horse) }}" class="text-gold-dark">Back to the record</a>
        @if ($code->redeemed_at)<span class="text-muted">Redeemed {{ $code->redeemed_at->format('M j, Y') }} by {{ $code->redeemer?->name }} ({{ str_replace('_', ' ', $code->redemption) }})</span>
        @elseif ($code->revoked_at)<span class="error">Revoked {{ $code->revoked_at->format('M j, Y') }}</span>
        @else
            <form method="POST" action="{{ route('admin.claim-codes.revoke', $code) }}" data-confirm="Revoke this code? It can't be used afterwards.">@csrf<button class="btn-ghost btn-small">Revoke</button></form>
        @endif
    </div>
    <div class="max-w-[7.3in] mx-auto bg-white border border-line p-10 my-6 print:my-0 print:border-0">
        <div class="flex items-start justify-between gap-6">
            <img src="/img/wordmark.svg" alt="Horsefax" class="h-[26px] w-auto">
            <p class="font-sans text-[11px] tracking-[0.2em] text-gold-dark text-right">OWNERSHIP CLAIM CERTIFICATE</p>
        </div>
        <h1 class="text-[40px] leading-tight mt-8">{{ $code->horse->displayName() }}</h1>
        <p class="font-sans text-[14px] text-muted mt-1">{{ $code->horse->horsefax_id }} · {{ collect([$code->horse->breed, $code->horse->sex ? ucfirst($code->horse->sex) : null, $code->horse->birth_year ? 'Foaled '.$code->horse->birth_year : null, $code->horse->color])->filter()->join(' · ') }}</p>
        <p class="mt-6 text-[17px]">Issued by <strong>{{ $code->issuer_name }}</strong> on {{ $code->created_at->format('F j, Y') }}{{ $code->expires_at ? '. Use by '.$code->expires_at->format('F j, Y') : '' }}.</p>
        <div class="mt-8 grid grid-cols-[1fr_200px] gap-8 items-center">
            <div>
                <p class="text-[17px]">To become this horse's verified owner on Horsefax, scan the code or go to <strong>horsefax.com/claim</strong>, sign in or create a free account, and enter:</p>
                <p class="mt-5 font-sans text-[30px] font-semibold tracking-[0.18em] text-ink">{{ $code->plain() }}</p>
                <p class="hint mt-5 text-[13px]">This code can be used once. Keep it private until you claim the horse: anyone with the code can claim it. If the record already has an owner, Horsefax staff review the claim.</p>
            </div>
            <img src="{{ route('admin.claim-codes.qr', $code) }}" alt="QR code to claim this horse" class="w-[200px] h-[200px]">
        </div>
        <p class="font-sans text-[11px] text-muted mt-10 border-t border-line pt-4">Because Every Horse Has A History.™ · horsefax.com · Horsefax, LLC</p>
    </div>
</body>
</html>
