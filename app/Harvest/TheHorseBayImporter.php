<?php

namespace App\Harvest;

use App\Models\DataSource;
use App\Models\SaleLot;

/**
 * TheHorseBay.com online auctions (Zack & Kay Stevens, SW Missouri). Richard, Nov 9 2026: load the registered lots
 * into Horsefax ("Yes, load them"), after the Nov 8 research pull.
 *
 * The site is never fetched from here. The research folder (Auction Info/TheHorseBay raw data: fetch.py at 8-11 s a
 * page, parse.py, export_horsefax.py) writes a facts-only CSV with no seller, bidder or location in it; copy it to
 * storage/app/private/thehorsebay/horsefax-lots.csv and run hf:import-thehorsebay.
 *
 * Checked Nov 9 2026: robots.txt disallows only WooCommerce cart/account/search paths and wp-admin; the Terms and
 * Conditions have no scraping, copying or commercial-use clause, only a ban on collecting information about users
 * without consent, which is why no person is ever stored. Same WooCommerce auction plugin as Platinum, so the same
 * result rules: a winning bid is shown like a sale, as reported by the site (Richard, Oct 18 2026); "reserve not met"
 * is a high bid; no bids is "offered". Lot titles put the registered name first, often with a barn name in quotes or
 * brackets ("Captains Classy Dun “Classy”"); the barn name is dropped.
 */
class TheHorseBayImporter
{
    public const SALE_NAME = 'TheHorseBay online auction';
    public const LABEL = 'TheHorseBay';

    /** Breed as listed => registry, only where the breed has one main registry. Everything else stays breed-only. */
    private const GAITED = [
        '/TENNESSEE WALK/' => 'TWHBEA',
        '/MISSOURI FOX ?TROT/' => 'MFTHBA',
    ];

    /** Acronyms the site prints in the breed field or ahead of the number: "Tennessee Walking Horse (TWHBEA)". */
    private const NAMED = ['AQHA' => 'AQHA', 'APHA' => 'APHA', 'APHC' => 'ApHC', 'TWHBEA' => 'TWHBEA', 'MFTHBA' => 'MFTHBA',
        'RMHA' => 'RMHA', 'KMSHA' => 'KMSHA', 'AHR' => 'AHA', 'AHA' => 'AHA', 'PTHA' => 'PtHA', 'GVHS' => 'GVHS', 'IALHA' => 'IALHA',
        'ABRA' => 'ABRA', 'PHBA' => 'PHBA', 'AMHA' => 'AMHA', 'POA' => 'POA', 'ASHA' => 'ASHA', 'FHANA' => 'FHANA'];
    public function __construct(private string $file) {}

    public static function source(): DataSource
    {
        return DataSource::firstOrCreate(['key' => 'thehorsebay'], [
            'name' => 'TheHorseBay', 'url' => 'https://thehorsebay.com/', 'permission' => 'terms_allow',
            'terms_note' => 'Checked Nov 9 2026: robots.txt disallows only cart/account/search paths and wp-admin; Terms and Conditions have no scraping, copying or commercial-use clause. They bar collecting information about users without consent, so no seller or bidder is stored. Richard approved loading (Nov 9 2026).',
        ]);
    }

    /** @return list<array<string, string>> */
    public function rows(): array
    {
        $h = fopen($this->file, 'r');
        $cols = fgetcsv($h, escape: '');
        $out = [];
        while (($r = fgetcsv($h, escape: '')) !== false) {
            if (count($r) === count($cols)) {
                $out[] = array_combine($cols, $r);
            }
        }
        fclose($h);

        return $out;
    }

    /** Lot title => the horse's name: barn names in quotes or brackets and "aka …" dropped, a quoted-only title kept. */
    public static function name(string $title): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if (preg_match('/^[“"]([^”"]+)[”"]$/u', $t, $m)) {
            return trim($m[1]);
        }
        $t = preg_replace('/^\s*[“"][^”"]*[”"]\s*/u', '', $t);          // “Hydro” Plenty Blue Revue
        $t = preg_split('/\s+(?:aka|a\.k\.a\.?|a\/k\/a)\s+/iu', $t)[0];
        $t = preg_split('/\s+[–—-]\s*|[–—-]\s+/u', $t)[0];               // "Foxy – Missouri Foxtrotter", "… Jade- Cabarello"
        $t = preg_replace(['/\s*[“"][^”"]*[”"]?\s*/u', '/\s*\([^)]*\)?\s*/u'], ' ', $t);

        return trim(preg_replace('/\s+/u', ' ', $t), " -–,");
    }

    /** Not a horse, or not one horse: mules and donkeys, test lots, several horses in one listing. */
    public static function skip(array $r, string $name): bool
    {
        $b = strtoupper($r['breed'].' '.$r['sex']);

        return $name === '' || (bool) preg_match('/\b(MULES?|DONKEYS?|HINNY|JOHN|MOLLY|JENNY|JACK|BURRO)\b/', $b)
            || (bool) preg_match('/\b(test|auction supporter)\b/i', $name.' '.$r['slug'])
            || (bool) preg_match('/&|\band\b|\bpair\b|geldings|mares/i', $r['sex'].' '.$r['breed'])
            || (bool) preg_match('/^pair\b|\bpair of\b/i', $name); // "Diamonds & Pearls" is one mare
    }

    /** [registry, registration number] from the breed and number fields. A 15-digit microchip is never a number. */
    public static function registration(string $breed, string $number): array
    {
        $b = strtoupper($breed);
        $n = strtoupper(trim($number));
        $reg = null;
        foreach (self::NAMED as $k => $v) {
            if (preg_match('/\b'.$k.'\b/', $b.' '.$n)) {
                $reg = $v;
                break;
            }
        }
        if (! $reg) {
            foreach (self::GAITED as $re => $v) {
                if (preg_match($re, $b) && ! preg_match('/CROSS|\bX\b|\//', $b)) {
                    $reg = $v;
                }
            }
        }
        // A cross gets no registry, nor a Gypsy horse (GVHS, GHA, GHRA and others all register them).
        if (! $reg && ! preg_match('/CROSS|\bX\b|\/|GYPSY|VANNER/', $b)) {
            $reg = LiveAgParser::registry(preg_replace('/^AMERICAN\s+/', '', $b)); // a cross gets no registry
        }
        if (preg_match('/FREEZE ?MARK|BRAND/', $n)) {
            return [$reg, null];
        }
        // The number: the first run with a digit, commas dropped ("1,051,808"), a trailing "/BL/TO" or "(S/P)" dropped.
        $num = null;
        // A letter prefix of up to 3 counts only as a whole word ("GV08155P", "C00003114"); a longer one is dropped
        // ("SHOBA-2016201263" => "2016201263").
        if (preg_match('/(?<![A-Z])[A-Z]{0,3}-?\d[\dA-Z,\-]*/', preg_replace('/\b(?:'.implode('|', array_keys(self::NAMED)).')\b\s*[#*:]?\s*-?\s*/', '', $n), $m)) {
            $num = trim(str_replace(',', '', $m[0]), '-');
            if (preg_match('/^\d{15}$|^(19|20)\d\d$/', $num) || strlen(preg_replace('/\D/', '', $num)) < 4) {
                $num = null; // microchip, a year, or too short to be a number
            }
        }

        return [$reg, $num];
    }

    private static function birth(string $d, int $saleYear): ?string
    {
        if (! preg_match('#^(\d{1,2})[-/](\d{1,2})[-/](\d{4})$#', trim($d), $m) || ! checkdate((int) $m[1], (int) $m[2], (int) $m[3])) {
            return null;
        }

        return ((int) $m[3] >= 1980 && (int) $m[3] <= $saleYear) ? sprintf('%04d-%02d-%02d', $m[3], $m[1], $m[2]) : null;
    }

    /** One CSV row => sale_lots values, or null to skip. */
    public static function values(array $r): ?array
    {
        if ($r['status'] !== 'expired') {
            return null; // still running
        }
        $name = self::name($r['name']);
        if (self::skip($r, $name)) {
            return null;
        }
        $bids = (int) $r['n_bids'];
        $price = $bids > 0 && (float) $r['top_bid'] > 0 ? (int) round((float) $r['top_bid'] * 100) : null;
        [$status, $note, $saleName] = match (true) {
            $bids === 0 => ['offered', 'Offered online; no bids', self::SALE_NAME],
            $r['sold'] === 'True' && $price !== null => ['sold', 'TheHorseBay reports this as the winning bid', self::SALE_NAME.', winning bid as reported by TheHorseBay'],
            $r['reserve_not_met'] === 'True' => ['high_bid', 'Reserve not met', self::SALE_NAME],
            default => ['high_bid', 'High bid; the sale did not mark it sold', self::SALE_NAME],
        };
        // No end time is published: a lot with bids closes on its last bid; one without is dated by its start month.
        $when = $bids > 0 ? $r['closed'] : $r['auction_started'];
        if (! preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $when)) {
            return null;
        }
        $date = substr($when, 0, 10);
        $year = (int) substr($date, 0, 4);
        [$registry, $number] = self::registration($r['breed'], $r['reg_number']);
        $registered = match (true) {
            (bool) preg_match('/^yes/i', trim($r['registered'])) => true,
            (bool) preg_match('/^no\b/i', trim($r['registered'])) && ! $number => false,
            default => $number ? true : null,
        };
        $birth = self::birth($r['foal_date'], $year);
        $sex = strtolower(trim($r['sex']));

        return [
            'sale_name' => $saleName, 'sale_date' => $bids > 0 ? $date : substr($date, 0, 8).'01', 'date_precision' => $bids > 0 ? 'day' : 'month',
            'horse_name' => mb_substr(NrchaHarvester::display($name), 0, 255), 'registered' => $registered,
            'registry' => $registered === false ? null : $registry, 'registration_number' => $registered === false ? null : $number,
            'birth_date' => $birth, 'foal_year' => $birth ? (int) substr($birth, 0, 4) : null,
            'sex' => in_array($sex, ['gelding', 'mare', 'stallion', 'colt', 'filly'], true) ? $sex : null,
            'color' => trim($r['color']) !== '' ? mb_substr(trim($r['color']), 0, 255) : null,
            'breed' => trim($r['breed']) !== '' ? mb_substr(trim($r['breed']), 0, 255) : null,
            'status' => $status, 'price_cents' => $status === 'offered' ? null : $price, 'currency' => 'USD', 'buyers_premium' => 'unknown',
            'note' => $note, 'source_url' => mb_substr($r['url'], 0, 600),
        ];
    }

    /** Store every lot and place it (registration number, then the registered-name rule). */
    public function load(): array
    {
        $src = self::source();
        $out = ['rows' => 0, 'skipped' => 0, 'loaded' => 0, 'sold' => 0, 'high_bid' => 0, 'offered' => 0,
            'matched' => 0, 'conflict' => 0, 'proposed' => 0, 'linked' => 0, 'created' => 0, 'queued' => 0, 'unmatched' => 0, 'already' => 0];
        foreach ($this->rows() as $r) {
            $out['rows']++;
            $v = self::values($r);
            if (! $v) {
                $out['skipped']++;
                continue;
            }
            $lot = SaleLot::record($src->id, 'thb:'.$r['slug'], $v);
            $out['loaded']++;
            $out[$v['status']]++;
            $out[RegistrationOnlyMatch::place($lot, self::LABEL)]++;
        }

        return $out;
    }
}
