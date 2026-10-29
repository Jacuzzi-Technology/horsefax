<?php

namespace App\Harvest;

/**
 * American Classic Pedigrees (americanclassicpedigrees.com) Thoroughbred profiles. Facts only: the profiles are Avalyn
 * Hunter's writing ("All rights reserved"), so no prose is kept. Read: the header (name, country, life dates, sire x
 * dam by damsire, family), the race record (summary line and each listed result by year), Honors, color and sex
 * ("A chestnut mare"), and the breeder named in Connections ("bred by …").
 */
class AcpParser
{
    public const HEADINGS = ['Race record', 'Honors', 'Assessments', 'As an individual', 'As a stallion', 'As a producer', 'As a broodmare', 'Sire rankings',
        'Broodmare sire rankings', 'Notable progeny', 'Connections', 'Pedigree notes', 'Books and media', 'Fun facts', 'Photo credit'];

    private const MONTH = '(?:January|February|March|April|May|June|July|August|September|October|November|December)';

    /** @return array|null name, country, foal date/year, death, pedigree, family, sex, color, record, results, honors, breeder */
    public static function page(string $html): ?array
    {
        $lines = self::lines($html);
        if (count($lines) < 4) {
            return null;
        }
        // Header: name, life dates, parentage, family (within the first few lines).
        $name = $lines[0];
        $life = $ped = $family = null;
        foreach (array_slice($lines, 1, 5) as $l) {
            if (! $life && preg_match('/^(?:('.self::MONTH.')\s+(\d{1,2}),\s+)?(\d{4})\s*[–-]\s*(Living|(?:('.self::MONTH.')\s+(\d{1,2}),\s+)?(\d{4}))?\s*$/u', $l, $m)) {
                $life = $m;
            } elseif (! $ped && preg_match('/^(.+?)\s+x\s+(.+?),\s+by\s+(.+)$/u', $l, $m)) {
                $ped = $m;
            } elseif (! $family && preg_match('/^Family\s+(\S+)/i', $l, $m)) {
                $family = $m[1];
            }
        }
        if (! $life || ! $ped || ! preg_match('/^(.+?)(?:\s*\(([A-Z]{2,3})\))?$/u', $name, $nm)) {
            return null; // not a horse profile (blog posts, articles)
        }
        $date = fn ($mon, $day, $y) => $mon && $day ? date('Y-m-d', strtotime("$mon $day $y")) : null;
        $out = [
            'name' => self::name($name), 'country' => $nm[2] ?? null, 'foal_year' => (int) $life[3], 'birth_date' => $date($life[1] ?? null, $life[2] ?? null, $life[3]),
            'living' => ($life[4] ?? '') === 'Living', 'died_year' => ! empty($life[7]) ? (int) $life[7] : null,
            'died_on' => ! empty($life[7]) ? $date($life[5] ?? null, $life[6] ?? null, $life[7]) : null,
            'pedigree' => ['s' => ['name' => self::name($ped[1])], 'd' => ['name' => self::name($ped[2])], 'ds' => ['name' => self::name($ped[3])]],
            'family' => $family, 'sex' => null, 'color' => null, 'record' => null, 'earnings_cents' => null, 'results' => [], 'honors' => [], 'breeder' => null,
        ];

        // Sections by heading.
        $sections = [];
        $cur = null;
        foreach ($lines as $l) {
            if (in_array($l, self::HEADINGS, true)) {
                $cur = $l;
                $sections[$cur] = [];
            } elseif (str_starts_with($l, 'Last updated')) {
                $cur = null;
            } elseif ($cur) {
                $sections[$cur][] = $l;
            }
        }

        $rr = $sections['Race record'] ?? [];
        if ($rr) {
            $out['record'] = mb_substr($rr[0], 0, 255);
            if (preg_match('/(?:^|,\s*)(?:US)?\$([\d,]+)\s*$/', $rr[0], $m)) {
                $out['earnings_cents'] = (int) str_replace(',', '', $m[1]) * 100;
            }
            $year = null;
            foreach (array_slice($rr, 1) as $l) {
                if (preg_match('/^(\d{4}):$/', $l, $m)) {
                    $year = (int) $m[1];
                } elseif ($year && preg_match('/^(Won|2nd|3rd|4th|Dead-heated for (?:1st|win|2nd|3rd))\s+(.+?)(?:\s*\(([^)]*)\))?$/i', $l, $m)) {
                    $parts = array_map('trim', explode(',', $m[3] ?? ''));
                    $track = count($parts) > 1 ? array_pop($parts) : null;
                    $out['results'][] = ['year' => $year, 'placing' => match (true) { stripos($m[1], 'won') === 0, stripos($m[1], '1st') !== false || stripos($m[1], 'win') !== false => '1st',
                        stripos($m[1], '2nd') !== false => '2nd', stripos($m[1], '3rd') !== false => '3rd', default => $m[1] },
                        'race' => trim($m[2]), 'class' => implode(', ', array_filter($parts)) ?: null, 'track' => $track, 'text' => $l];
                }
            }
        }
        $out['honors'] = array_values(array_filter($sections['Honors'] ?? [], fn ($l) => mb_strlen($l) <= 255));

        // "A chestnut mare; no further information available." / "A bay horse with …"
        $ind = $sections['As an individual'][0] ?? '';
        if (preg_match('/^An?\s+((?:dark |light |liver |red |blood |flea-bitten |dapple )?(?:bay|brown|chestnut|gr[ae]y|black|roan|white|sorrel|palomino|dun|grey\/roan|bay\/brown|dark bay\/brown))\s+(horse|colt|stallion|mare|filly|gelding)\b/i', $ind, $m)) {
            $out['color'] = ucwords(strtolower($m[1]));
            $out['sex'] = match (strtolower($m[2])) { 'mare', 'filly' => 'mare', 'gelding' => 'gelding', default => 'stallion' };
        }
        if (! $out['sex']) {
            $out['sex'] = isset($sections['As a stallion']) ? 'stallion' : (isset($sections['As a producer']) || isset($sections['As a broodmare']) ? 'mare' : null);
        }
        if ($out['sex'] === 'stallion' && preg_match('/\bgelding\b/i', $ind)) {
            $out['sex'] = 'gelding';
        }

        // "… was bred by J. O. "Jack" Keene and William Cleveland. She was …" / "bred and owned by Haras Vacación."
        $conn = implode(' ', $sections['Connections'] ?? []);
        if (preg_match('/\bbred\s+(?:and\s+(?:raced|owned)\s+)?by\s+(.+?)(?=;|,\s+who|\s+at\s|\s+in\s+(?:partnership|Kentucky|Ireland|England|France)|\s+and\s+(?:was|sold|raced)|(?<!\b[A-Z])(?<!\bMr)(?<!\bMrs)(?<!\bDr)(?<!\bSt)(?<!\bJr)(?<!\bSr)(?<!\bCo)\.\s+[A-Z][a-z]{2,}|\.\s*$|$)/u', $conn, $m)) {
            $b = trim($m[1], " ,.");
            if ($b !== '' && mb_strlen($b) <= 120 && ! preg_match('/\b(unknown|not known)\b/i', $b)) {
                $out['breeder'] = $b;
            }
        }

        return $out;
    }

    /** The profile body as lines (breaks, list items and blocks end a line). */
    public static function lines(string $html): array
    {
        $i = strpos($html, 'id="wsite-content"');
        if ($i === false) {
            return [];
        }
        $i = strpos($html, '>', $i) + 1;
        $j = strpos($html, 'id="footer', $i);
        $b = substr($html, $i, $j !== false ? $j - $i : null);
        $b = preg_replace(['#<script.*?</script>|<style.*?</style>#is', '#<br\s*/?>|</li>|<li[^>]*>|</div>|</p>|</h\d>#i'], ['', "\n"], $b);
        $t = str_replace(["\u{200b}", "\u{a0}"], ['', ' '], html_entity_decode(strip_tags($b), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return array_values(array_filter(array_map(fn ($l) => trim(preg_replace('/\s+/u', ' ', $l)), explode("\n", $t)), fn ($l) => $l !== ''));
    }

    /** "Forestry (USA)" → "Forestry"; other countries keep their suffix, as in pedigrees ("Abercorn (AUS)"). */
    public static function name(string $n): string
    {
        return trim(preg_replace('/\s*\(USA\)\s*$/', '', trim($n)));
    }
}
