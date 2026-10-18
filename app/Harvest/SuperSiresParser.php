<?php

namespace App\Harvest;

use App\Support\Record;

/**
 * Super Sires (supersires.org) stallion and nominated-foal pages. Keeps the horse's identity, pedigree and Super Sires
 * results; owner, standing, nominator and ownership-history names on the pages are never read.
 */
class SuperSiresParser
{
    /** Stallion page: "1992 aqha Bay Stallion", "[ Sire x Dam ]" and a 3-generation chart (14 ancestors). */
    public static function stallion(string $html): ?array
    {
        $x = self::xpath($html);
        $name = self::text($x, '//h1[contains(@class,"et_pb_module_heading")]');
        if ($name === '') {
            return null;
        }
        $out = ['name' => $name, 'foal_year' => null, 'registry' => null, 'color' => null, 'sex' => 'stallion', 'birth_date' => null, 'registration_number' => null, 'pedigree' => [], 'results' => []];
        foreach ($x->query('//div[contains(@class,"info-stallion")]/p') as $p) {
            $t = self::clean($p->textContent);
            if (preg_match('/^(\d{4})\s+([A-Za-z]+)\s+(.+?)\s+(Stallion|Gelding|Mare)$/i', $t, $m)) {
                $out['foal_year'] = (int) $m[1];
                $out['registry'] = self::registry($m[2]);
                $out['color'] = self::color($m[3]);
            } elseif (preg_match('/^\[\s*(.+?)\s+x\s+(.+?)\s*\]$/i', $t, $m)) {
                $out['pedigree']['s'] = $m[1];
                $out['pedigree']['d'] = $m[2];
            }
        }
        // The chart: one block for the sire's side, one for the dam's, each a horse, its two parents and four grandparents.
        foreach ($x->query('//div[contains(@class,"rst-pedigree-matters-item")]') as $i => $side) {
            $p = $i === 0 ? 's' : 'd';
            if ($i > 1) {
                break;
            }
            $self = self::text($x, './/div[contains(@class,"pedigree-column1")]', $side);
            if ($self !== '') {
                $out['pedigree'][$p] = $self;
            }
            foreach ($x->query('.//div[contains(@class,"pedigree-column2-item")]', $side) as $j => $item) {
                $q = $p.($j === 0 ? 's' : 'd');
                if ($j > 1) {
                    break;
                }
                foreach ([[$q, './/div[contains(@class,"pedigree-column2-name")]'], [$q.'s', './/div[contains(@class,"sire-f0")]'], [$q.'d', './/div[contains(@class,"dam-f0")]']] as [$pos, $path]) {
                    $n = self::text($x, $path, $item);
                    if ($n !== '') {
                        $out['pedigree'][$pos] = $n;
                    }
                }
            }
        }

        return $out;
    }

    /** Nominated-foal page: "Sire x Dam (Dam's sire)", DOB, gender, registry number and Super Sires earnings. */
    public static function foal(string $html): ?array
    {
        $x = self::xpath($html);
        $name = self::text($x, '//h1[contains(@class,"et_pb_module_heading")]');
        if ($name === '') {
            return null;
        }
        $out = ['name' => $name, 'foal_year' => null, 'registry' => null, 'color' => null, 'sex' => null, 'birth_date' => null, 'registration_number' => null, 'pedigree' => [], 'results' => []];
        $p = $x->query('//div[contains(@class,"rst-info-foals")]/p')->item(0);
        if ($p) {
            // Only the first line (before <br>): the second is the owner's name.
            $line = '';
            foreach ($p->childNodes as $n) {
                if ($n->nodeName === 'br') {
                    break;
                }
                $line .= $n->textContent;
            }
            if (preg_match('/^\s*(.*?)\s*x\s+(.+?)\s*(?:\((.+)\))?\s*$/i', self::clean($line), $m)) {
                $out['pedigree'] = array_filter(['s' => trim($m[1]), 'd' => trim($m[2]), 'ds' => trim($m[3] ?? '')]);
            }
        }
        foreach ($x->query('//div[contains(@class,"rst-info-foals-column-inner")]/div') as $cell) {
            $label = strtolower(self::text($x, './span', $cell));
            $value = self::text($x, './p', $cell);
            if ($value === '') {
                continue;
            }
            if ($label === 'dob' && preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $d)) {
                $out['birth_date'] = sprintf('%04d-%02d-%02d', $d[3], $d[1], $d[2]);
                $out['foal_year'] = (int) $d[3];
            } elseif ($label === 'gender') {
                $out['sex'] = array_key_exists(strtolower($value), Record::SEXES) ? strtolower($value) : null;
            } elseif (self::registry($label) && preg_match('/^[A-Z0-9-]{3,}$/i', $value)) {
                $out['registry'] = self::registry($label);
                $out['registration_number'] = $value;
            }
        }
        foreach ($x->query('//div[contains(@class,"rst-foal-earnings")]//tr[td]') as $tr) {
            $td = [];
            foreach ($x->query('./td', $tr) as $c) {
                $td[] = self::clean($c->textContent);
            }
            if (count($td) < 6 || $td[1] === '') {
                continue;
            }
            $year = preg_match('/^(\d{4})\b/', $td[1], $y) ? (int) $y[1] : (preg_match('#(\d{4})$#', $td[4], $y) ? (int) $y[1] : null);
            $out['results'][] = [
                'placing' => preg_match('/^\d+$/', $td[0]) ? $td[0] : null, 'event' => $td[1], 'class' => $td[2], 'purse' => $td[3] ?: null,
                'year' => $year, 'earnings_cents' => is_numeric($e = str_replace([',', '$'], '', $td[5])) ? (int) round((float) $e * 100) : null,
            ];
        }

        return $out;
    }

    public static function registry(string $s): ?string
    {
        foreach (array_keys(Record::REGISTRIES) as $k) {
            if (strcasecmp($k, trim($s)) === 0) {
                return $k;
            }
        }

        return null;
    }

    public static function color(string $s): ?string
    {
        foreach (Record::COLORS as $c) {
            if (strcasecmp($c, trim($s)) === 0) {
                return $c;
            }
        }

        return trim($s) !== '' ? ucwords(strtolower(trim($s))) : null;
    }

    private static function xpath(string $html): \DOMXPath
    {
        $doc = new \DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        libxml_clear_errors();

        return new \DOMXPath($doc);
    }

    private static function text(\DOMXPath $x, string $path, ?\DOMNode $ctx = null): string
    {
        $n = $x->query($path, $ctx)->item(0);

        return $n ? self::clean($n->textContent) : '';
    }

    private static function clean(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
