<?php

namespace App\Harvest;

/**
 * StallionCompare (stallioncompare.com) stallion and horse pages. Facts only: owner names, phone numbers and email
 * addresses are never read; the standing farm (a business), its town and website are, because Richard wants "where the
 * horse is standing" (Oct 27 2026). Article summaries are StallionCompare's own writing: only titles and links are kept.
 */
class StallionCompareParser
{
    public const BASE = 'https://stallioncompare.com';

    /** Breeds as the site writes them, longest first. */
    private const BREEDS = ['Quarter Horse', 'Paint Horse', 'Quarter Pony', 'Pony of the Americas', 'Appaloosa', 'Palomino', 'Buckskin', 'Pinto', 'Thoroughbred', 'Arabian', 'Paint'];

    /** Horsefax breed names. */
    private const BREED_NAMES = ['Paint Horse' => 'Paint', 'Pony of the Americas' => 'POA'];

    /**
     * @return array{name: string, foal_year: ?int, color: ?string, breed: ?string, registry: ?string, sex: ?string,
     *   height: ?float, died_year: ?int, pedigree: array, genetics: ?array, fees: ?array, standing: ?array,
     *   nominations: list<string>, earnings_cents: ?int, highlights: list<string>, progeny: list<array>,
     *   articles: list<array>, links: list<string>}|null
     */
    public static function page(string $html): ?array
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $x = new \DOMXPath($dom);
        $t = fn (?\DOMNode $n) => $n ? trim(preg_replace('/\s+/u', ' ', str_replace("\u{a0}", ' ', $n->textContent))) : '';

        $h1 = $x->query('//h1[contains(@class,"text-navy")]')->item(0);
        $name = $t($h1);
        if ($name === '') {
            return null;
        }
        $out = ['name' => $name, 'foal_year' => null, 'color' => null, 'breed' => null, 'registry' => null, 'sex' => null, 'height' => null,
            'died_year' => null, 'pedigree' => [], 'genetics' => null, 'fees' => null, 'standing' => null, 'nominations' => [],
            'earnings_cents' => null, 'highlights' => [], 'progeny' => [], 'articles' => [], 'links' => []];

        // "1997 Bay Quarter Horse (AQHA ) Stallion, 16.3h", then "Sire x Dam (Damsire)", then "In loving memory of …, 1997 – 2019."
        $p = $h1->nextSibling;
        $lines = [];
        for (; $p && count($lines) < 4; $p = $p->nextSibling) {
            if ($p instanceof \DOMElement && $p->nodeName === 'p') {
                $lines[] = $t($p);
            }
        }
        $out = array_merge($out, self::info($lines[0] ?? ''));
        foreach ($lines as $l) {
            if (preg_match('/In loving memory of .*?,\s*\d{4}\s*[–-]\s*(\d{4})/u', $l, $m)) {
                $out['died_year'] = (int) $m[1];
            }
        }
        if (preg_match('/^(.+?)\s+x\s+(.+?)(?:\s*\(([^)]+)\))?$/u', $lines[1] ?? '', $m)) {
            $out['pedigree']['s'] = ['name' => trim($m[1])];
            $out['pedigree']['d'] = ['name' => trim($m[2])];
            if (! empty($m[3])) {
                $out['pedigree']['ds'] = ['name' => trim($m[3])];
            }
        }

        // Pedigree chart: cells in tree order with a generation number; sire first under each parent.
        $last = [0 => ''];
        $kids = [];
        foreach ($x->query('//div[@id="pedigree"]//td[@generation]') as $td) {
            $g = (int) $td->getAttribute('generation');
            $parent = $last[$g - 1] ?? null;
            if ($g < 1 || $parent === null) {
                continue;
            }
            $pos = $parent.(($kids[$parent] = ($kids[$parent] ?? 0) + 1) === 1 ? 's' : 'd');
            $last[$g] = $pos;
            $last = array_slice($last, 0, $g + 1, true);
            $line = ['name' => $t($x->query('.//div[contains(@class,"fw-medium")]', $td)->item(0)) ?: trim($td->getAttribute('name'))];
            foreach ($x->query('.//p', $td) as $pp) {
                $v = $t($pp);
                if (preg_match('/^\$([\d,]+)$/', $v, $m)) {
                    $line['earnings_cents'] = (int) str_replace(',', '', $m[1]) * 100;
                } elseif ($v !== '') {
                    $line += array_filter(['foal_year' => self::info($v)['foal_year'], 'color' => self::info($v)['color']]);
                }
            }
            if ($line['name'] !== '' && ! isset($out['pedigree'][$pos]['foal_year'])) {
                $out['pedigree'][$pos] = $line + ($out['pedigree'][$pos] ?? []);
            }
        }

        // Overview cards: Genetics, Fees, Breeding; contact cards: Standing At.
        foreach ($x->query('//p[contains(@class,"text-spaced")]') as $label) {
            $card = $label->parentNode;
            $head = $t($x->query('.//p[contains(@class,"fs-5")]', $card)->item(0));
            switch (strtolower($t($label))) {
                case 'genetics':
                    $tests = [];
                    foreach ($x->query('.//div[contains(@class,"column-genetics")]//div[contains(@class,"col")]', $card) as $col) {
                        foreach (preg_split('/<br\s*\/?>/i', $dom->saveHTML($col)) as $bit) {
                            $bit = trim(html_entity_decode(strip_tags($bit), ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \u{a0}");
                            if ($bit !== '') {
                                $tests[] = preg_replace('/\s+/u', ' ', str_replace("\u{a0}", ' ', $bit));
                            }
                        }
                    }
                    if ($head !== '' || $tests) {
                        $out['genetics'] = ['summary' => $head ?: null, 'tests' => array_values(array_unique($tests))];
                    }
                    break;
                case 'fees':
                    $fee = preg_match('/\$([\d,]+)\s*([A-Z]{3})?/', $head, $m) ? ['fee_cents' => (int) str_replace(',', '', $m[1]) * 100, 'currency' => $m[2] ?? 'USD'] : ['fee_text' => $head ?: null];
                    if (preg_match('/Chute Fee:\s*\$([\d,]+)/', $t($card), $m)) {
                        $fee['chute_fee_cents'] = (int) str_replace(',', '', $m[1]) * 100;
                    }
                    $out['fees'] = ($out['fees'] ?? []) + $fee;
                    break;
                case 'breeding':
                    $notes = [];
                    foreach ($x->query('.//*[@data-bs-title]', $card) as $b) {
                        $notes[] = trim($b->getAttribute('data-bs-title')); // shipping badges: "Available in Europe"
                    }
                    foreach ($x->query('.//p[not(contains(@class,"text-spaced"))]', $card) as $pp) {
                        foreach (self::lines($dom, $pp) as $l) {
                            $notes[] = $l;
                        }
                    }
                    $notes = array_values(array_unique(array_filter($notes)));
                    if ($notes) {
                        $out['fees'] = ($out['fees'] ?? []) + ['breeding' => implode('; ', $notes)];
                    }
                    break;
                case 'standing at':
                    // Lines: [street], "Whitesboro, TX 76273", country, then links. The street is not kept.
                    $where = $x->query('.//p[not(contains(@class,"text-spaced")) and not(contains(@class,"fs-5"))]', $card)->item(0);
                    $bits = $where ? array_values(array_filter(self::lines($dom, $where), fn ($l) => ! preg_match('/^(Website|Email|Phone)\b/i', $l))) : [];
                    $city = $region = $country = null;
                    foreach ($bits as $i => $l) {
                        if (preg_match('/^(.+?),\s*(.+?)(?:\s+(\d{5}(?:-\d{4})?|[A-Z]\d[A-Z]\s?\d[A-Z]\d|\d{4}))?$/u', $l, $m)) {
                            [$city, $region] = [trim($m[1]), trim($m[2])];
                            $country = $bits[$i + 1] ?? null;
                            break;
                        }
                    }
                    if ($country === null && count($bits) === 1 && ! preg_match('/\d/', $bits[0])) {
                        $country = $bits[0];
                    }
                    $web = null;
                    foreach ($x->query('.//a[starts-with(@href,"http")]', $card) as $a) {
                        $web ??= $a->getAttribute('href');
                    }
                    if ($head !== '') {
                        $out['standing'] = ['name' => $head, 'city' => $city, 'region' => $region, 'country' => $country, 'website' => $web];
                    }
                    break;
            }
        }

        foreach ($x->query('//div[@id="nominations"]//*[@data-bs-title]') as $n) {
            $out['nominations'][] = trim($n->getAttribute('data-bs-title'));
        }
        $out['nominations'] = array_values(array_unique(array_filter($out['nominations'])));

        // Highlights ("Overview" on horse pages): "$40,110 in LTE" then a list of titles.
        $hl = $x->query('//div[@id="highlights"]')->item(0);
        if ($hl) {
            foreach ($x->query('.//p[contains(@class,"fs-5")]', $hl) as $pp) {
                if (preg_match('/\$([\d,]+)\s+in\s+LTE/i', $t($pp), $m)) {
                    $out['earnings_cents'] = (int) str_replace(',', '', $m[1]) * 100;
                }
            }
            foreach ($x->query('.//li[not(.//a) and not(ancestor::div[@id="articles"]) and not(ancestor::div[@id="progeny"])]', $hl) as $li) {
                if (($v = $t($li)) !== '') {
                    $out['highlights'][] = $v;
                }
            }
        }

        // Progeny table: name, "2014 Sorrel Mare", sire, dam.
        foreach ($x->query('//div[@id="progeny"]//table//tr[td]') as $tr) {
            $tds = $x->query('./td', $tr);
            if ($tds->length < 5) {
                continue;
            }
            $a = $x->query('.//a', $tds->item(1))->item(0);
            $pname = $t($tds->item(1));
            if ($pname === '') {
                continue;
            }
            $info = $t($tds->item(2));
            $sex = preg_match('/\b(Stallion|Mare|Gelding)\b/i', $info, $m) ? strtolower($m[1]) : null;
            $rest = trim(preg_replace('/\b(Stallion|Mare|Gelding)\b/i', '', preg_replace('/^\d{4}\s*/', '', $info)));
            $out['progeny'][] = ['name' => $pname, 'foal_year' => preg_match('/^(\d{4})/', $info, $m) ? (int) $m[1] : null, 'sex' => $sex,
                'color' => $rest !== '' ? $rest : null, 'sire' => $t($tds->item(3)) ?: null, 'dam' => $t($tds->item(4)) ?: null,
                'slug' => $a ? self::slug($a->getAttribute('href')) : null];
        }

        foreach ($x->query('//div[@id="articles"]//a[starts-with(@href,"/article/")]') as $a) {
            $title = $t($x->query('.//p[contains(@class,"fw-semibold")]', $a)->item(0));
            if ($title !== '') {
                $out['articles'][] = ['title' => $title, 'url' => self::BASE.$a->getAttribute('href')];
            }
        }

        // Linked horse and stallion pages (pedigree and progeny), for the depth-one crawl.
        foreach ($x->query('//div[@id="pedigree"]//a[@href] | //div[@id="progeny"]//a[@href]') as $a) {
            if (preg_match('#^/(horse|stallion)/[a-z0-9-]+$#', $a->getAttribute('href'))) {
                $out['links'][] = $a->getAttribute('href');
            }
        }
        $out['links'] = array_values(array_unique($out['links']));

        return $out;
    }

    /** "1997 Bay Quarter Horse (AQHA ) Stallion, 16.3h" → year, color, breed, registry, sex, height. */
    public static function info(string $line): array
    {
        $o = ['foal_year' => null, 'color' => null, 'breed' => null, 'registry' => null, 'sex' => null, 'height' => null];
        $line = trim(preg_replace('/\s+/u', ' ', $line));
        if (preg_match('/,\s*([\d.]+)\s*h\b/i', $line, $m)) {
            $o['height'] = (float) $m[1];
            $line = trim(preg_replace('/,\s*[\d.]+\s*h\b.*/i', '', $line));
        }
        if (preg_match('/\b(Stallion|Mare|Gelding)\s*$/i', $line, $m)) {
            $o['sex'] = strtolower($m[1]);
            $line = trim(substr($line, 0, -strlen($m[0])));
        }
        if (preg_match('/\(([^)]*)\)\s*$/', $line, $m)) {
            $regs = array_values(array_filter(array_map('trim', preg_split('/[\/,]/', $m[1]))));
            $o['registry'] = $regs[0] ?? null;
            $line = trim(substr($line, 0, -strlen($m[0])));
        }
        if (preg_match('/^(\d{4})\s*/', $line, $m)) {
            $o['foal_year'] = (int) $m[1];
            $line = trim(substr($line, strlen($m[0])));
        }
        foreach (self::BREEDS as $b) {
            if (preg_match('/^(.*?)\s*\b'.preg_quote($b, '/').'$/i', $line, $m)) {
                $o['breed'] = self::BREED_NAMES[$b] ?? $b;
                $line = trim($m[1]);
                break;
            }
        }
        $o['color'] = $line !== '' ? $line : null;
        if (! $o['registry'] && $o['breed'] === 'Quarter Horse') {
            $o['registry'] = 'AQHA';
        }

        return $o;
    }

    /** A paragraph's lines, split at <br>, footnote marks (<sup>) and tags removed. */
    private static function lines(\DOMDocument $dom, \DOMNode $p): array
    {
        $html = preg_replace('#<sup>.*?</sup>#is', '', $dom->saveHTML($p));
        $out = [];
        foreach (preg_split('/<br\s*\/?>/i', $html) as $bit) {
            $bit = trim(preg_replace('/\s+/u', ' ', str_replace("\u{a0}", ' ', html_entity_decode(strip_tags($bit), ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
            if ($bit !== '') {
                $out[] = $bit;
            }
        }

        return $out;
    }

        public static function slug(string $href): ?string
    {
        return preg_match('#/(?:horse|stallion)/([a-z0-9-]+)#', $href, $m) ? $m[1] : null;
    }
}
