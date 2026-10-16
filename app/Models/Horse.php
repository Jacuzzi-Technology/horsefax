<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Horse extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'horsefax_id', 'created_by', 'identity_status'];

    protected function casts(): array
    {
        return ['birth_date' => 'date', 'height_measured_on' => 'date', 'public_summary' => 'boolean', 'listed' => 'boolean', 'height_hands' => 'decimal:1'];
    }

    public function getRouteKeyName(): string
    {
        return 'horsefax_id';
    }

    public function names(): HasMany { return $this->hasMany(HorseName::class); }
    public function identifiers(): HasMany { return $this->hasMany(HorseIdentifier::class); }
    public function stewardships(): HasMany { return $this->hasMany(Stewardship::class); }
    public function events(): HasMany { return $this->hasMany(HorseEvent::class); }
    public function documents(): HasMany { return $this->hasMany(Document::class); }
    public function shareLinks(): HasMany { return $this->hasMany(ShareLink::class); }
    public function pedigree(): HasMany { return $this->hasMany(PedigreeEntry::class)->whereNull('superseded_by'); }
    public function media(): HasMany { return $this->hasMany(Media::class); }
    public function contributors(): HasMany { return $this->hasMany(Contributor::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function profilePhoto(): BelongsTo { return $this->belongsTo(Document::class, 'profile_photo_id'); }

    public function displayName(): string
    {
        if ($this->registered_name && $this->barn_name && strcasecmp($this->registered_name, $this->barn_name) !== 0) {
            return $this->registered_name.' ("'.$this->barn_name.'")';
        }

        return $this->registered_name ?: ($this->barn_name ?: 'Unnamed horse');
    }

    public const IDENTITY = ['unverified' => 'Identity unverified', 'under_review' => 'Identity under review', 'verified' => 'Identity verified'];

    public function identityLabel(): string
    {
        return self::IDENTITY[$this->identity_status] ?? $this->identity_status;
    }

    public function shortName(): string
    {
        return $this->barn_name ?: ($this->registered_name ?: 'Unnamed horse');
    }

    public function birthLabel(): ?string
    {
        return match ($this->birth_precision) {
            'day' => $this->birth_date?->format('M j, Y'),
            'year' => $this->birth_year ? 'Foaled '.$this->birth_year : null,
            default => null,
        };
    }

    public function heightLabel(): ?string
    {
        return $this->height_hands ? rtrim(rtrim((string) $this->height_hands, '0'), '.').' hh' : null;
    }

    /** Active stewards (owner/custodian) who can edit the record. */
    public function activeStewards()
    {
        return $this->stewardships()->where('status', '!=', 'ended');
    }

    public function owner(): ?User
    {
        return $this->activeStewards()->where('role', 'owner')->with('user')->first()?->user;
    }

    public function isStewardedBy(?User $user): bool
    {
        return $user !== null && $this->activeStewards()->where('user_id', $user->id)->exists();
    }

    public function canBeEditedBy(?User $user): bool
    {
        return $user !== null && $user->standing === 'good' && $this->isStewardedBy($user);
    }

    /** The professional's active invitation on this record, if any. Revoked or pending ones give no access. */
    public function activeContributor(?User $user): ?Contributor
    {
        return $user === null ? null : $this->contributors()->where('status', 'active')->where('user_id', $user->id)->first();
    }

    /** Professionals add entries in their own name; they never edit the owner's record or change visibility. */
    public function canBeContributedToBy(?User $user): bool
    {
        return $user !== null && $user->standing === 'good' && $this->activeContributor($user) !== null;
    }

    public function canBeViewedPrivatelyBy(?User $user): bool
    {
        return $user !== null && ($user->isStaff() || $this->isStewardedBy($user));
    }

    /** Current (not superseded) identifiers. */
    public function currentIdentifiers()
    {
        return $this->identifiers()->whereNull('superseded_by');
    }

    /** Horses on Horsefax whose current sire or dam line links to this horse. */
    public function offspring()
    {
        return Horse::whereHas('pedigree', fn ($q) => $q->whereIn('position', ['s', 'd'])->where('linked_horse_id', $this->id));
    }

    public function currentEvents()
    {
        return $this->events()->whereNull('superseded_by');
    }
}
