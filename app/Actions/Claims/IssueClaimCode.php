<?php

namespace App\Actions\Claims;

use App\Models\ClaimCode;
use App\Models\Horse;
use App\Models\User;
use App\Support\Audit;

/** Staff (later, partner sellers) issue a one-time claim code for a horse, printed on a claim certificate for the buyer. */
class IssueClaimCode
{
    public function __invoke(Horse $horse, User $by, string $issuer, ?string $expiresOn = null, ?string $saleLotId = null): ClaimCode
    {
        do {
            $raw = '';
            for ($i = 0; $i < 12; $i++) {
                $raw .= ClaimCode::ALPHABET[random_int(0, strlen(ClaimCode::ALPHABET) - 1)];
            }
            $plain = implode('-', str_split($raw, 4));
        } while (ClaimCode::where('code_hash', ClaimCode::hash($plain))->exists());

        $code = ClaimCode::create([
            'horse_id' => $horse->id, 'code_hash' => ClaimCode::hash($plain), 'code_encrypted' => $plain,
            'issuer_name' => $issuer, 'sale_lot_id' => $saleLotId, 'created_by' => $by->id,
            'expires_at' => $expiresOn ? \Illuminate\Support\Carbon::parse($expiresOn)->endOfDay() : null,
        ]);
        Audit::record('claim_code.issued', $code, null, ['issuer' => $issuer, 'expires_at' => $code->expires_at?->toDateString()], $horse->id, $by->id);

        return $code;
    }
}
