<?php

namespace App\Http\Controllers;

use App\Actions\Claims\IssueClaimCode;
use App\Actions\Claims\RedeemClaimCode;
use App\Models\ClaimCode;
use App\Models\Horse;
use App\Support\Audit;
use Illuminate\Http\Request;

/** Claim codes: buyers redeem them; staff issue, print and revoke them. */
class ClaimCodeController extends Controller
{
    public function form(Request $request)
    {
        return view('claims.code', ['code' => $request->query('code')]);
    }

    public function redeem(Request $request, RedeemClaimCode $redeem)
    {
        $data = $request->validate(['code' => 'required|string|max:40']);
        [$outcome, $horse] = $redeem($data['code'], $request->user());

        return match ($outcome) {
            'verified_owner' => redirect()->route('horses.show', $horse)->with('status', 'You are now the verified owner of '.$horse->displayName().' on Horsefax.'),
            'already_owner' => redirect()->route('horses.show', $horse)->with('status', 'You are already the verified owner of this horse.'),
            'sent_to_review' => redirect()->route('dashboard')->with('status', 'This record already has an owner, so your code went to Horsefax staff with your claim. You will see the decision on your dashboard.'),
            default => back()->withInput()->withErrors(['code' => 'That code isn\'t valid, has been used, or has expired. Check it against your claim certificate, or contact the seller.']),
        };
    }

    public function issue(Request $request, Horse $horse, IssueClaimCode $issue)
    {
        $data = $request->validate(['issuer' => 'required|string|max:120', 'expires_on' => 'nullable|date|after:today']);
        $code = $issue($horse, $request->user(), $data['issuer'], $data['expires_on'] ?? null);

        return redirect()->route('admin.claim-codes.certificate', $code);
    }

    public function certificate(ClaimCode $code)
    {
        return view('claims.certificate', ['code' => $code->load('horse')]);
    }

    public function qr(ClaimCode $code)
    {
        return response(\App\Support\HorseQr::svg($code->claimUrl(), 400), 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'private, no-store']);
    }

    public function revoke(Request $request, ClaimCode $code)
    {
        abort_if($code->redeemed_at, 409, 'Already redeemed.');
        $code->forceFill(['revoked_at' => now()])->save();
        Audit::record('claim_code.revoked', $code, null, [], $code->horse_id);

        return back()->with('status', 'Claim code revoked.');
    }
}
