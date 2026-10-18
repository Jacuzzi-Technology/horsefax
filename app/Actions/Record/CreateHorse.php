<?php

namespace App\Actions\Record;

use App\Models\Horse;
use App\Models\Stewardship;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

class CreateHorse
{
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * @param  array<int, array{kind: string, registry?: ?string, value: string}>  $identifiers
     */
    public function __invoke(User $user, array $facts, array $identifiers = []): Horse
    {
        return DB::transaction(function () use ($user, $facts, $identifiers) {
            $horse = new Horse($facts);
            $horse->horsefax_id = self::newId();
            $horse->created_by = $user->id;
            $horse->save();

            Stewardship::create([
                'horse_id' => $horse->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'asserted',
                'started_on' => now()->toDateString(),
            ]);
            Audit::record('horse.created', $horse, null, $horse->only(array_keys($facts)) + ['horsefax_id' => $horse->horsefax_id]);
            app(RecordFacts::class)($horse, $user, RecordFacts::fromColumns($facts), 'owner_reported', 'owner');

            foreach ($identifiers as $identifier) {
                if (trim($identifier['value'] ?? '') !== '') {
                    app(AddIdentifier::class)($horse, $user, $identifier);
                }
            }

            return $horse->refresh();
        });
    }

    /** A permanent, public-friendly ID: HF-XXXX-XXXX, never reused (rows are never deleted). */
    public static function newId(): string
    {
        do {
            $id = self::randomId();
        } while (Horse::where('horsefax_id', $id)->exists());

        return $id;
    }

    /** A random HF-XXXX-XXXX (not checked against existing records). */
    public static function randomId(): string
    {
        $chars = '';
        for ($i = 0; $i < 8; $i++) {
            $chars .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return 'HF-'.substr($chars, 0, 4).'-'.substr($chars, 4);
    }
}
