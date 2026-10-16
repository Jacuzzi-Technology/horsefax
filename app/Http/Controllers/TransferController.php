<?php

namespace App\Http\Controllers;

use App\Actions\Record\Transfers;
use App\Models\Horse;
use App\Models\Transfer;
use Illuminate\Http\Request;

class TransferController extends Controller
{
    public function start(Request $request, Horse $horse, Transfers $transfers)
    {
        abort_unless($horse->canBeEditedBy($request->user()) && $horse->owner()?->id === $request->user()->id, 403);
        $data = $request->validate(['email' => 'required|email|max:200', 'confirm' => 'accepted']);
        $transfers->start($horse, $request->user(), $data['email']);

        return back()->with('status', 'Transfer started. When the new owner signs in to Horsefax with '.$data['email'].', they can accept it from their dashboard.');
    }

    private function forMe(Request $request, Transfer $transfer): void
    {
        abort_unless($transfer->status === 'pending' && strcasecmp($transfer->to_email, $request->user()->email) === 0, 404);
    }

    public function accept(Request $request, Transfer $transfer, Transfers $transfers)
    {
        $this->forMe($request, $transfer);
        $transfers->accept($transfer, $request->user());

        return redirect()->route('horses.show', $transfer->horse)->with('status', 'You are now the owner on this Horsefax record.');
    }

    public function decline(Request $request, Transfer $transfer, Transfers $transfers)
    {
        $this->forMe($request, $transfer);
        $transfers->decline($transfer, $request->user());

        return back()->with('status', 'Transfer declined.');
    }

    public function cancel(Request $request, Transfer $transfer, Transfers $transfers)
    {
        abort_unless($transfer->status === 'pending' && $transfer->from_user_id === $request->user()->id, 404);
        $transfers->cancel($transfer);

        return back()->with('status', 'Transfer cancelled.');
    }
}
