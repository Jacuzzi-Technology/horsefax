<?php

namespace App\Actions\Record;

use App\Models\Horse;
use App\Models\Stewardship;
use App\Models\Transfer;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The owner starts a transfer to an email address; the receiver (signed in with that email) accepts. History stays
 * attributed to whoever added it; the previous owner loses editing from then on.
 */
class Transfers
{
    public function start(Horse $horse, User $from, string $email): Transfer
    {
        $email = strtolower(trim($email));
        if ($email === strtolower($from->email)) {
            throw ValidationException::withMessages(['email' => 'That is your own email address.']);
        }
        Transfer::where('horse_id', $horse->id)->where('status', 'pending')->update(['status' => 'cancelled', 'decided_at' => now()]);
        $transfer = Transfer::create(['horse_id' => $horse->id, 'from_user_id' => $from->id, 'to_email' => $email]);
        Audit::record('transfer.started', $transfer, null, ['to_email' => $email]);

        return $transfer;
    }

    public function cancel(Transfer $transfer): void
    {
        $transfer->forceFill(['status' => 'cancelled', 'decided_at' => now()])->save();
        Audit::record('transfer.cancelled', $transfer, null, []);
    }

    public function accept(Transfer $transfer, User $to): void
    {
        DB::transaction(function () use ($transfer, $to) {
            $horse = $transfer->horse;
            foreach ($horse->activeStewards()->where('role', 'owner')->get() as $old) {
                $old->forceFill(['status' => 'ended', 'ended_at' => now(), 'ended_reason' => 'Transferred on Horsefax'])->save();
            }
            Stewardship::create(['horse_id' => $horse->id, 'user_id' => $to->id, 'role' => 'owner', 'status' => 'asserted', 'started_on' => now()->toDateString()]);
            $transfer->forceFill(['status' => 'accepted', 'to_user_id' => $to->id, 'decided_at' => now()])->save();
            Audit::record('transfer.accepted', $transfer, null, ['to_user_id' => $to->id]);
        });
    }

    public function decline(Transfer $transfer, User $to): void
    {
        $transfer->forceFill(['status' => 'declined', 'to_user_id' => $to->id, 'decided_at' => now()])->save();
        Audit::record('transfer.declined', $transfer, null, []);
    }
}
