<?php

namespace App\Http\Controllers;

use App\Models\IdentityCheck;
use App\Models\ReviewItem;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * People verification, staff-reviewed for now (a third-party ID service can replace the upload later). The person
 * uploads a photo of a government ID; staff compare it with their account and ownership papers; the image is deleted
 * as soon as the check is decided, and only the result, method and date are kept. Never shown publicly.
 */
class IdentityController extends Controller
{
    public function form(Request $request)
    {
        return view('account.identity', ['user' => $request->user(), 'pending' => IdentityCheck::where('user_id', $request->user()->id)->where('status', 'pending')->exists()]);
    }

    public function submit(Request $request)
    {
        $user = $request->user();
        abort_if(IdentityCheck::where('user_id', $user->id)->where('status', 'pending')->exists(), 409);
        $data = $request->validate([
            'legal_name' => 'required|string|max:160',
            'document' => 'required|file|mimes:jpg,jpeg,png,webp,heic,pdf|max:15360',
            'consent' => 'accepted',
        ]);
        $path = $request->file('document')->store('identity/'.$user->id, 'local');
        $check = IdentityCheck::create(['user_id' => $user->id, 'legal_name' => $data['legal_name'], 'document_path' => $path, 'method' => 'id_document']);
        ReviewItem::create(['kind' => 'identity_check', 'submitted_by' => $user->id, 'subject_type' => 'IdentityCheck', 'subject_id' => $check->id,
            'statement' => 'Identity check for '.$data['legal_name'].' (account: '.$user->name.').']);
        Audit::record('identity.submitted', $check, null, ['legal_name' => $data['legal_name']]);
        \App\Support\Notify::staff('Horsefax identity check submitted', $data['legal_name']);

        return redirect()->route('account')->with('status', 'Thanks. Horsefax staff will check your ID, then delete the image. You\'ll see the result on your account page.');
    }

    /** Staff only: the ID image, while the check is open. */
    public function document(IdentityCheck $check)
    {
        abort_unless($check->document_path && Storage::disk('local')->exists($check->document_path), 404);

        return response()->file(Storage::disk('local')->path($check->document_path), ['Cache-Control' => 'private, no-store']);
    }
}
