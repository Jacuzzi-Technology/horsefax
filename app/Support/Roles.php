<?php

namespace App\Support;

use App\Models\Contributor;
use App\Models\Stewardship;

/**
 * Kate's role labels: creating a record is not the same as owning the horse.
 * Record creator · Claimed owner · Verified owner · Authorized manager or trainer · Verified professional · Horsefax administrator.
 */
final class Roles
{
    public static function stewardLabel(Stewardship $s): string
    {
        $label = match (true) {
            $s->role === 'custodian' => 'Authorized manager',
            $s->status === 'verified' => 'Verified owner',
            default => 'Claimed owner',
        };

        return $s->status === 'ended' ? ($s->role === 'custodian' ? 'Former manager' : 'Former owner') : $label;
    }

    public static function contributorLabel(Contributor $c): string
    {
        $role = $c->roleLabel();

        return $c->user?->credential_verified_at ? 'Verified professional · '.$role : $role.' · credentials not yet verified';
    }
}
