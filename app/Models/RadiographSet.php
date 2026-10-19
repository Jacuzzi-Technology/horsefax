<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A set of radiographs taken on one date, attributed to the clinic and veterinarian. */
class RadiographSet extends Model
{
    use HasUuids;

    public const PURPOSES = ['pre_purchase' => 'Pre-purchase exam', 'sale' => 'Taken for sale', 'routine' => 'Routine', 'treatment' => 'Treatment or follow-up', 'other' => 'Other'];
    public const ACCESS = [
        'private' => 'Private: only you and people you share a report link with',
        'on_request' => 'On request: signed-in buyers ask, you approve each one',
        'sale' => 'Sale access: any signed-in buyer can view until a date you set',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['taken_on' => 'date', 'sale_access_until' => 'date', 'in_reports' => 'boolean'];
    }

    public function horse(): BelongsTo { return $this->belongsTo(Horse::class); }
    public function uploader(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by'); }
    public function files(): HasMany { return $this->hasMany(RadiographFile::class)->orderBy('position')->orderBy('created_at'); }
    public function access(): HasMany { return $this->hasMany(RadiographAccess::class); }
    public function viewsLog(): HasMany { return $this->hasMany(RadiographView::class); }

    public function title(): string
    {
        return collect(['Radiographs', $this->taken_on?->format('M j, Y'), self::PURPOSES[$this->purpose] ?? null])->filter()->join(' · ');
    }

    public function attribution(): string
    {
        return collect([$this->veterinarian, $this->clinic])->filter()->join(', ') ?: 'Veterinarian not stated';
    }

    public function saleOpen(): bool
    {
        return $this->access === 'sale' && (! $this->sale_access_until || $this->sale_access_until->endOfDay()->isFuture());
    }

    /** May this reader see the images? Stewards and staff always; others by sale access, an approved request, or a report link. */
    public function viewableBy(?User $user, ?ShareLink $link = null): bool
    {
        if ($link) {
            return $link->horse_id === $this->horse_id && $link->isActive() && $this->in_reports && $link->includes('health');
        }
        if (! $user) {
            return false;
        }
        if ($user->isStaff() || $this->horse->canBeViewedPrivatelyBy($user)) {
            return true;
        }
        if ($this->saleOpen()) {
            return true;
        }

        return $this->access === 'on_request' && $this->access()->where('user_id', $user->id)->where('status', 'approved')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists();
    }

    public function log(string $action, ?User $user, ?ShareLink $link = null, ?string $fileId = null): void
    {
        if ($user && ($user->isStaff() || $this->horse->canBeViewedPrivatelyBy($user))) {
            return; // the owner's own views and staff checks aren't buyer interest
        }
        RadiographView::create(['radiograph_set_id' => $this->id, 'user_id' => $user?->id, 'share_link_id' => $link?->id, 'radiograph_file_id' => $fileId, 'action' => $action]);
    }
}
