<?php

namespace App\Actions\Record;

use App\Models\Contributor;
use App\Models\Horse;
use App\Models\User;
use App\Support\Audit;
use App\Support\Record;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * The owner invites a professional by email; the professional (signed in with that email) accepts. While active they
 * add entries and documents in their own name. The owner decides who sees those entries but cannot edit or supersede
 * them, and can remove access at any time; removing access never removes what was already added.
 */
class Contributors
{
    public function invite(Horse $horse, User $owner, string $email, string $role): Contributor
    {
        $email = strtolower(trim($email));
        if ($email === strtolower($owner->email)) {
            throw ValidationException::withMessages(['email' => 'That is your own email address.']);
        }
        if ($horse->activeStewards()->whereHas('user', fn ($q) => $q->whereRaw('lower(email) = ?', [$email]))->exists()) {
            throw ValidationException::withMessages(['email' => 'That person already looks after this horse on Horsefax.']);
        }
        if ($horse->contributors()->whereIn('status', ['pending', 'active'])->whereRaw('lower(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['email' => 'That person is already invited to this record.']);
        }

        $contributor = Contributor::create([
            'horse_id' => $horse->id, 'role' => $role, 'email' => $email, 'invited_by' => $owner->id,
        ]);
        Audit::record('contributor.invited', $contributor, null, ['email' => $email, 'role' => $role]);
        $this->notify($contributor, $owner);

        return $contributor;
    }

    public function accept(Contributor $contributor, User $user): void
    {
        $contributor->forceFill(['status' => 'active', 'user_id' => $user->id, 'accepted_at' => now()])->save();
        Audit::record('contributor.accepted', $contributor, ['status' => 'pending'], ['status' => 'active', 'user_id' => $user->id, 'role' => $contributor->role]);
    }

    public function decline(Contributor $contributor, User $user): void
    {
        $contributor->forceFill(['status' => 'declined', 'user_id' => $user->id, 'ended_at' => now()])->save();
        Audit::record('contributor.declined', $contributor, ['status' => 'pending'], ['status' => 'declined']);
    }

    /** Also cancels an invitation that has not been accepted yet. Entries already added stay on the record. */
    public function revoke(Contributor $contributor, User $by): void
    {
        $before = $contributor->status;
        $contributor->forceFill(['status' => 'revoked', 'ended_at' => now(), 'ended_by' => $by->id])->save();
        Audit::record('contributor.revoked', $contributor, ['status' => $before], ['status' => 'revoked']);
    }

    /** Best-effort email. The invitation also waits on the invitee's dashboard, so a failed email loses nothing. */
    private function notify(Contributor $contributor, User $owner): void
    {
        $horse = $contributor->horse;
        $body = $owner->name.' has invited you to add to the Horsefax record for '.$horse->displayName().' ('.$horse->horsefax_id.') as its '.strtolower(Record::CONTRIBUTOR_ROLES[$contributor->role] ?? $contributor->role).".\n\n"
            ."Sign in or create a free Horsefax account with this email address ({$contributor->email}) and accept the invitation from your dashboard:\n".route('dashboard');
        try {
            Mail::raw($body, fn ($m) => $m->to($contributor->email)->subject('Horsefax: invitation to contribute to '.$horse->shortName()."'s record"));
        } catch (\Throwable $e) {
            Log::warning('Horsefax contributor invitation email failed: '.$e->getMessage());
        }
    }
}
