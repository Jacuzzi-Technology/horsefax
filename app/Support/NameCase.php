<?php

namespace App\Support;

/**
 * Initials in registered names (Richard, Oct 23 2026): title-casing an all-caps catalog name turned "E.L. TREMOR" into
 * "E.l. Tremor" and "BP'S MEGAN" into "Bp's Megan". Dotted initials and vowelless initials before 's stay in capitals.
 * Dutch and Flemish particles ("v.d." = van de, "v.h.") are not initials and are left as written.
 */
class NameCase
{
    /** Lower-case particles written with dots in European names (IFCE): never initials. */
    public const PARTICLES = ['v.d.', 'v.h.', 'd.v.', 'e.v.', 's.v.', 'v.d.s.', 'v.t.'];

    private const NOT_INITIALS = ['mr', 'mrs', 'dr', 'st', 'jr', 'sr', 'ms'];

    /** One word of an all-caps name, title-cased (used by NrchaHarvester::display). */
    public static function word(string $w): string
    {
        $s = '';
        if (preg_match("/^(.+?)(['’]S)$/u", $w, $m)) {
            [$w, $s] = [$m[1], mb_strtolower($m[2])];
        }
        if (preg_match('/^(?:[A-Z]\.){2,}$/', $w) || (preg_match('/^[BCDFGHJKLMNPQRSTVWXZ]{2,3}$/', $w) && ! in_array(strtolower($w), self::NOT_INITIALS, true))) {
            return $w.$s; // "E.L.", "J.D.'s", "BP's", "JM"
        }

        return ucfirst(strtolower($w)).$s;
    }

    /**
     * Repairs a stored name. Dotted initials are fixed at the start of the name or before 's ($anywhere also mid-name, for
     * American catalog sources); vowelless initials before 's only when $vowelless (not for European registers).
     */
    public static function repair(string $name, bool $anywhere = true, bool $vowelless = true): string
    {
        $fixed = preg_replace_callback("/(?<![\\p{L}.])((?:[A-Za-z]\\.){2,})(?=['’]s\\b|\\s|$)/u", function ($m) use ($name, $anywhere) {
            $token = $m[1][0];
            if (in_array(strtolower($token), self::PARTICLES, true)) {
                return $token;
            }
            $atStart = $m[1][1] === 0;
            $beforeS = (bool) preg_match("/^['’]s\\b/u", substr($name, $m[1][1] + strlen($token)));

            return ($anywhere || $atStart || $beforeS) ? strtoupper($token) : $token;
        }, $name, -1, $count, PREG_OFFSET_CAPTURE);

        if ($vowelless) {
            $fixed = preg_replace_callback("/\\b([BCDFGHJKLMNPQRSTVWXZ][bcdfghjklmnpqrstvwxz]{1,2})(['’]s)\\b/u",
                fn ($m) => in_array(strtolower($m[1]), self::NOT_INITIALS, true) ? $m[0] : strtoupper($m[1]).$m[2], $fixed);
        }

        return $fixed;
    }
}
