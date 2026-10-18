<?php

namespace App\Support;

use App\Models\Horse;
use Illuminate\Support\Collection;

/**
 * What has been established about a horse, stated plainly (Kate: no universal score). Counts only what the reader
 * can see: e.g. "Identity verified · Pedigree confirmed · 3 Horsefax Verified facts · 2 professional records ·
 * 4 owner-reported entries · 5 documents".
 */
final class Summary
{
    /** @param iterable<Collection> $claims every visible claim collection (facts, identifiers, events, pedigree, documents) */
    public static function parts(Horse $horse, Collection $facts, Collection $identifiers, Collection $events, Collection $pedigree, Collection $documents): array
    {
        $all = $facts->concat($identifiers)->concat($events)->concat($pedigree->values())->concat($documents)
            ->filter(fn ($c) => ($c->status ?? null) !== 'superseded');
        $parts = [];
        $parts[] = $horse->identity_status === 'verified' ? 'Identity verified' : ($horse->identity_status === 'under_review' ? 'Identity under review' : 'Identity not yet verified');
        $parents = collect(['s', 'd'])->map(fn ($p) => $pedigree->get($p));
        if ($parents->every(fn ($e) => $e && $e->status === 'verified')) {
            $parts[] = 'Pedigree confirmed';
        }
        $count = fn ($n, $one, $many) => $n ? $n.' '.($n === 1 ? $one : $many) : null;
        $parts[] = $count($all->where('status', 'verified')->count(), 'Horsefax Verified fact', 'Horsefax Verified facts');
        $parts[] = $count($all->where('source_type', 'official_source')->count(), 'official-source record', 'official-source records');
        $parts[] = $count($all->where('source_type', 'professional_attested')->count(), 'professional record', 'professional records');
        $parts[] = $count($events->where('source_type', 'owner_reported')->count(), 'owner-reported entry', 'owner-reported entries');
        $parts[] = $count($documents->count(), 'document', 'documents');
        $parts[] = $count($all->where('status', 'disputed')->count(), 'disputed item', 'disputed items');

        return array_values(array_filter($parts));
    }
}
