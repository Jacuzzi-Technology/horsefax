<?php

namespace App\Actions\Record;

use App\Models\Horse;
use App\Models\ShareLink;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Str;

class ShareLinks
{
    public function create(Horse $horse, User $user, array $sections, ?string $label, ?int $days): ShareLink
    {
        $link = ShareLink::create([
            'horse_id' => $horse->id,
            'token' => Str::random(40),
            'label' => $label,
            'sections' => array_values($sections),
            'created_by' => $user->id,
            'expires_at' => $days ? now()->addDays($days) : null,
        ]);
        Audit::record('share_link.created', $link, null, ['label' => $label, 'sections' => $link->sections, 'expires_at' => $link->expires_at?->toIso8601String()]);

        return $link;
    }

    public function revoke(ShareLink $link): void
    {
        if ($link->revoked_at) {
            return;
        }
        $link->forceFill(['revoked_at' => now()])->save();
        Audit::record('share_link.revoked', $link, null, ['revoked_at' => $link->revoked_at->toIso8601String()]);
    }
}
