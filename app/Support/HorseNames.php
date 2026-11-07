<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Comparing horse names across sources (Nov 2 2026). Sources add registry tags ("Dun It Strait (P)"), drop apostrophes,
 * and misspell parents, so two printings of one name mustn't read as two horses. Numbers are part of a name: Blackburn
 * 1911 and Blackburn 1912 are two horses, however few letters apart.
 */
class HorseNames
{
    /** Registry tags at the end of a name. Country tags stay: "Bull Dog (FR)" and "Bull Dog (GB)" can be two horses. */
    private const TAG = '/\s*[\(\[]\s*(?:p|q|q\/p|p\/q|tb|ap|u|d|g|a|aq|qh|qp|apha|aqha)\s*[\)\]]\s*$/i';

    /** What a sale prints before a foal has a name: not an identity, so two of them say nothing about each other. */
    private const NOT_A_NAME = '/foal\s*in\s*utero|\bembryo\b|\bapplication\b|^\s*unnamed|^\s*pending|^\s*tbd\b|not named|\bfoal\s*$|^\s*none\s*$|^\s*n\/?a\s*$/i';

    /** What a source prints for a parent it doesn't know. */
    private const NO_PARENT = '/^(UNKNOWN.*|ISMERETLEN.*|GRADE|NONE|NA|UNK|NOTAVAILABLE|NOTLISTED|UNREGISTERED|PICTUREOFPEDIGREEABOVE)$/';

    public static function strip(?string $name): string
    {
        return trim((string) preg_replace(self::TAG, '', trim((string) $name)));
    }

    public static function key(?string $name): string
    {
        $name = self::strip($name);
        if (preg_match('/[^\x00-\x7F]/', $name)) {
            $name = Str::ascii($name);
        }

        return (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($name));
    }

    public static function notAName(?string $name): bool
    {
        return (bool) preg_match(self::NOT_A_NAME, (string) $name);
    }

    /** A parent's name as a key, or null when the source doesn't actually name one. */
    public static function parent(?string $name): ?string
    {
        $k = self::key($name);

        return $k === '' || preg_match(self::NO_PARENT, $k) ? null : $k;
    }

    /**
     * @return 'same'|'near'|'diff'|null null when either side names no parent; "near" is a couple of letters apart
     *                                   on a name of 5+ letters, with the same digits
     */
    public static function compareParents(?string $a, ?string $b): ?string
    {
        [$a, $b] = [self::parent($a), self::parent($b)];
        if ($a === null || $b === null) {
            return null;
        }
        if ($a === $b) {
            return 'same';
        }
        $sameDigits = preg_replace('/\D/', '', $a) === preg_replace('/\D/', '', $b);

        return $sameDigits && min(strlen($a), strlen($b)) >= 5 && levenshtein($a, $b) <= 2 ? 'near' : 'diff';
    }

    public static function parentsAgree(?string $compared): bool
    {
        return $compared === 'same' || $compared === 'near';
    }

    /** Both names carry a number, and only the number sets them apart ("Figure Four 344" / "Figure Four 424"). */
    public static function differOnlyByNumber(?string $a, ?string $b): bool
    {
        [$na, $la] = self::numbersAndLetters($a);
        [$nb, $lb] = self::numbersAndLetters($b);

        return $na && $nb && $na !== $nb && $la === $lb;
    }

    /** @return array{0: list<string>, 1: string} */
    private static function numbersAndLetters(?string $name): array
    {
        $name = self::strip($name);
        $name = strtoupper(str_replace('.', ' ', preg_match('/[^\x00-\x7F]/', $name) ? Str::ascii($name) : $name));
        preg_match_all('/\b(\d+|[IVXL]+(?:-\d+)?)\b/', $name, $m);

        return [$m[1], (string) preg_replace('/[^A-Z]/', '', (string) preg_replace('/\b(\d+|[IVXL]+(?:-\d+)?)\b/', '', $name))];
    }

    /** A colt becomes a stallion and a stallion a gelding, so only male against female says two horses. */
    public static function oppositeSex(?string $a, ?string $b): bool
    {
        $side = fn ($s) => ['stallion' => 'm', 'colt' => 'm', 'gelding' => 'm', 'geding' => 'm', 'ridgling' => 'm', 'mare' => 'f', 'filly' => 'f'][strtolower(trim((string) $s))] ?? null;
        [$a, $b] = [$side($a), $side($b)];

        return $a && $b && $a !== $b;
    }
}
