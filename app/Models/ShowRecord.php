<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A registered name seen in published show results. Not a horse record until staff match it to one. */
class ShowRecord extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['matched_at' => 'datetime'];
    }

    public function appearances(): HasMany { return $this->hasMany(ShowAppearance::class); }
    public function horse(): BelongsTo { return $this->belongsTo(Horse::class); }

    /** Names are compared without case, spacing or punctuation differences. */
    public static function key(string $name): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($name));
    }

    /** The sire, dam and dam's sire most often published with this name (NRCHA lists them), or nulls. */
    public function parentage(): array
    {
        $row = $this->appearances()->whereNotNull('sire_name')
            ->selectRaw('sire_name, dam_name, dam_sire_name, count(*) n')->groupBy('sire_name', 'dam_name', 'dam_sire_name')
            ->orderByDesc('n')->first();

        return ['sire' => $row?->sire_name, 'dam' => $row?->dam_name, 'dam_sire' => $row?->dam_sire_name];
    }

    /** Registration numbers published with this name (WSQHA prints them). More than one means more than one horse. */
    public function registrationNumbers(): array
    {
        return $this->appearances()->whereNotNull('registration_number')->distinct()->pluck('registration_number')->all();
    }

    /** The registration number and foal year published with this name, when they're consistent; nulls otherwise. */
    public function publishedIdentity(): array
    {
        $regs = $this->registrationNumbers();
        $years = $this->appearances()->whereNotNull('foal_year')->distinct()->pluck('foal_year')->all();
        $registry = $this->appearances()->whereNotNull('registration_number')->value('organization');

        return [
            'registration_number' => count($regs) === 1 ? $regs[0] : null,
            'registry' => count($regs) === 1 && in_array($registry, ['AQHA', 'APHA', 'ApHC', 'PtHA'], true) ? $registry : null,
            'foal_year' => count($years) === 1 ? (int) $years[0] : null,
        ];
    }

    public function yearsLabel(): string
    {
        return $this->first_year === $this->last_year ? (string) $this->first_year : $this->first_year.'–'.$this->last_year;
    }
}
