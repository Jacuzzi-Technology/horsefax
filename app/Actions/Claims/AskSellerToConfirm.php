<?php

namespace App\Actions\Claims;

use App\Models\ClaimConfirmation;
use App\Models\ReviewItem;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/** Emails the seller named in a claim a one-time link to confirm or deny the sale. Bills of sale are easy to fake; this isn't. */
class AskSellerToConfirm
{
    public function __invoke(ReviewItem $item, string $sellerName, string $sellerEmail): ClaimConfirmation
    {
        $token = Str::random(48);
        $c = ClaimConfirmation::create(['review_item_id' => $item->id, 'seller_name' => $sellerName, 'seller_email' => $sellerEmail, 'token_hash' => hash('sha256', $token)]);
        $horse = $item->horse;
        $buyer = $item->submitter?->name ?? 'A Horsefax member';
        $url = route('claims.confirm', $token);
        try {
            Mail::raw("Hello {$sellerName},\n\n{$buyer} has asked Horsefax to record them as the owner of {$horse->displayName()} ({$horse->horsefax_id}), and named you as the seller.\n\nDid you sell this horse to them? Please confirm or deny here (no account needed):\n{$url}\n\nIf you don't know anything about this, choose Deny. Thank you,\nHorsefax", function ($m) use ($sellerEmail, $horse) {
                $m->to($sellerEmail)->subject('Did you sell '.$horse->displayName().'? A quick confirmation for Horsefax');
            });
            $c->forceFill(['sent_at' => now()])->save();
        } catch (\Throwable $e) {
            report($e);
        }

        return $c;
    }
}
