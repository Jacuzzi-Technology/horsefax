<?php

namespace App\Http\Controllers;

use App\Models\Contributor;
use App\Models\ReviewItem;
use App\Models\Transfer;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function dashboard(Request $request)
    {
        $user = $request->user();

        return view('account.dashboard', [
            'horses' => $user->horses()->with('profilePhoto')->orderBy('barn_name')->orderBy('registered_name')->get(),
            'incoming' => Transfer::where('status', 'pending')->whereRaw('lower(to_email) = lower(?)', [$user->email])->with('horse')->get(),
            'invitations' => Contributor::where('status', 'pending')->whereRaw('lower(email) = lower(?)', [$user->email])->with('horse', 'inviter')->get(),
            'contributing' => $user->contributions()->with('horse')->get(),
            'claims' => ReviewItem::where('kind', 'ownership_claim')->where('submitted_by', $user->id)->with('horse')->orderByDesc('created_at')->limit(10)->get(),
        ]);
    }

    public function account(Request $request)
    {
        return view('account.settings', ['user' => $request->user()]);
    }
}
