<?php

namespace App\Http\Controllers;

use App\Models\ClaimConfirmation;
use App\Support\Audit;
use Illuminate\Http\Request;

/** The seller named in a claim confirms or denies the sale from the emailed link. No account needed. */
class ClaimConfirmationController extends Controller
{
    private function find(string $token): ClaimConfirmation
    {
        return ClaimConfirmation::where('token_hash', hash('sha256', $token))->with('reviewItem.horse', 'reviewItem.submitter')->firstOrFail();
    }

    public function show(string $token)
    {
        return view('claims.confirm', ['c' => $this->find($token), 'token' => $token]);
    }

    public function respond(Request $request, string $token)
    {
        $c = $this->find($token);
        abort_if($c->responded_at, 409, 'This sale has already been answered.');
        $data = $request->validate(['response' => 'required|in:confirmed,denied', 'note' => 'nullable|string|max:2000']);
        $c->forceFill(['response' => $data['response'], 'response_note' => $data['note'] ?? null, 'responded_at' => now()])->save();
        Audit::record('claim.seller_'.$data['response'], $c, null, ['seller_email' => $c->seller_email], $c->reviewItem->horse_id);
        \App\Support\Notify::staff('Seller '.$data['response'].' a Horsefax ownership claim ('.$c->reviewItem->horse?->horsefax_id.')', (string) ($data['note'] ?? ''));

        return view('claims.confirm', ['c' => $c->fresh('reviewItem.horse', 'reviewItem.submitter'), 'token' => $token, 'done' => true]);
    }
}
