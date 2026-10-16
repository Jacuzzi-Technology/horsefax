<?php

namespace App\Support;

use App\Models\Horse;
use Illuminate\Database\Eloquent\Builder;

/**
 * Narrowing search (Richard, Oct 16 2026): start with a name; while more than MAX_LIST horses match, ask for more
 * details; once few enough remain, list them so the person can pick one or add a new record.
 *
 * Search covers listed horses (basic identity only) and horses with a public summary. Identifier filters match
 * exactly and never reveal the number itself.
 */
final class HorseSearch
{
    public const MAX_LIST = 10;

    public const FIELDS = ['name', 'registry', 'registration_number', 'microchip', 'foal_year', 'sex', 'color', 'breed', 'sire', 'dam'];

    public static function criteria(array $input): array
    {
        $c = [];
        foreach (self::FIELDS as $f) {
            $v = trim((string) ($input[$f] ?? ''));
            if ($v !== '') {
                $c[$f] = mb_substr($v, 0, 120);
            }
        }
        if (isset($c['registry']) && ! array_key_exists($c['registry'], Record::REGISTRIES)) {
            unset($c['registry']);
        }
        if (isset($c['sex']) && ! array_key_exists($c['sex'], Record::SEXES)) {
            unset($c['sex']);
        }
        if (isset($c['foal_year']) && ! ctype_digit($c['foal_year'])) {
            unset($c['foal_year']);
        }

        return $c;
    }

    /** A Horsefax ID typed into the name box finds that horse directly. */
    public static function byHorsefaxId(string $text): ?Horse
    {
        $n = Record::normalizeIdentifier($text);
        if (! (str_starts_with($n, 'HF') && strlen($n) === 10)) {
            return null;
        }

        return Horse::where('horsefax_id', 'HF-'.substr($n, 2, 4).'-'.substr($n, 6))
            ->where(fn ($q) => $q->where('listed', true)->orWhere('public_summary', true))->first();
    }

    public static function query(array $c): Builder
    {
        $q = Horse::query()->where(fn ($w) => $w->where('listed', true)->orWhere('public_summary', true));

        if (isset($c['name'])) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $c['name']).'%';
            $q->where(fn ($w) => $w->where('registered_name', 'ilike', $like)->orWhere('barn_name', 'ilike', $like)
                ->orWhereHas('names', fn ($n) => $n->where('visibility', '!=', 'private')->where('name', 'ilike', $like)));
        }
        if (isset($c['registration_number'])) {
            $q->whereHas('identifiers', fn ($i) => $i->where('kind', 'registration')->whereNull('superseded_by')
                ->where('value_normalized', Record::normalizeIdentifier($c['registration_number']))
                ->when(isset($c['registry']), fn ($r) => $r->where('registry', $c['registry'])));
        } elseif (isset($c['registry'])) {
            $q->whereHas('identifiers', fn ($i) => $i->where('kind', 'registration')->whereNull('superseded_by')->where('registry', $c['registry']));
        }
        if (isset($c['microchip'])) {
            $q->whereHas('identifiers', fn ($i) => $i->where('kind', 'microchip')->whereNull('superseded_by')
                ->where('value_normalized', Record::normalizeIdentifier($c['microchip'])));
        }
        if (isset($c['foal_year'])) {
            $q->where('birth_year', (int) $c['foal_year']);
        }
        if (isset($c['sex'])) {
            $q->where('sex', $c['sex']);
        }
        foreach (['color', 'breed'] as $f) {
            if (isset($c[$f])) {
                $q->where($f, 'ilike', '%'.$c[$f].'%');
            }
        }
        foreach (['sire' => 's', 'dam' => 'd'] as $f => $pos) {
            if (isset($c[$f])) {
                $q->whereHas('pedigree', fn ($p) => $p->where('position', $pos)->where('visibility', '!=', 'private')->where('name', 'ilike', '%'.$c[$f].'%'));
            }
        }

        return $q;
    }

    /** Has the person given anything to search on? */
    public static function hasStart(array $c): bool
    {
        return isset($c['name']) || isset($c['registration_number']) || isset($c['microchip']);
    }
}
