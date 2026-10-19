<?php

namespace App\Actions\Claims;

use App\Models\ClaimCode;
use App\Models\ReviewItem;
use App\Models\Stewardship;
use App\Models\User;
use App\Support\Audit;
use App\Support\Notify;
use Illuminate\Support\Facades\DB;

/**
 * A buyer redeems a claim code from the seller. With no other owner on the record, the buyer becomes the verified owner
 * at once (the seller's code is the evidence). If someone else already owns the record, it goes to staff instead: a
 * code never displaces an owner by itself.
 *
 * @return array{0:string, 1:?\App\Models\Horse} outcome (verified_owner, sent_to_review, already_owner, invalid) and the horse
 */
class RedeemClaimCode
{
    public function __invoke(string $input, User $user): array
    {
        return DB::transaction(function () use ($input, $user) {
            $code = ClaimCode::where('code_hash', ClaimCode::hash($input))->lockForUpdate()->first();
            if (! $code || ! $code->usable()) {
                return ['invalid', null];
            }
            $horse = $code->horse;
            if ($horse->activeStewards()->where('user_id', $user->id)->where('role', 'owner')->where('status', 'verified')->exists()) {
                return ['already_owner', $horse];
            }
            $others = $horse->activeStewards()->where('role', 'owner')->where('user_id', '!=', $user->id)->exists();
            if ($others) {
                $item = ReviewItem::create([
                    'kind' => 'ownership_claim', 'horse_id' => $horse->id, 'submitted_by' => $user->id,
                    'statement' => 'Redeemed a claim code issued by '.$code->issuer_name.'. The record already has an owner, so staff decide.',
                    'details' => ['claim_code_id' => $code->id, 'claim_code_issuer' => $code->issuer_name],
                ]);
                $code->forceFill(['redeemed_by' => $user->id, 'redeemed_at' => now(), 'redemption' => 'sent_to_review'])->save();
                Audit::record('claim_code.redeemed_to_review', $code, null, ['review_item' => $item->id], $horse->id, $user->id);
                Notify::staff('Horsefax claim code redeemed for an owned record: '.$horse->horsefax_id, $item->statement);

                return ['sent_to_review', $horse];
            }
            $s = $horse->activeStewards()->where('user_id', $user->id)->first();
            if ($s) {
                $s->forceFill(['role' => 'owner', 'status' => 'verified'])->save();
            } else {
                $s = Stewardship::create(['horse_id' => $horse->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'verified', 'started_on' => now()->toDateString()]);
            }
            $code->forceFill(['redeemed_by' => $user->id, 'redeemed_at' => now(), 'redemption' => 'verified_owner'])->save();
            Audit::record('stewardship.verified', $s, null, ['user_id' => $user->id, 'via' => 'claim_code', 'issuer' => $code->issuer_name], $horse->id, $user->id);

            return ['verified_owner', $horse];
        });
    }
}
