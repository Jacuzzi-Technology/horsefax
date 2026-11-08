<?php

namespace App\Harvest;

use App\Actions\Record\CleanPendingFoalRecords;
use App\Models\DataSource;
use App\Models\SaleLot;
use Illuminate\Support\Facades\DB;

/**
 * The other sale catalogs in Richard's PDFNotes library (Richard, Oct 28 2026: "we should look through all the catalog
 * files in that folder then. I have them from other sales as well"). The library is Dropbox › Horsefax › Sale Catalogs ›
 * DoNotEdit, listed in Catalog Inventory.xlsx; Lolli and Billings have their own loaders. Each catalog is copied to
 * storage/app/private/printed-catalogs/<sale>/<year>-<month>.pdf and read there, never changed.
 *
 * One data source per sale company. The catalogs give no prices, so every lot is "offered" (catalogued for the sale)
 * and placed on its registration number, then by the registered-name rule (RegistrationOnlyMatch::place). Facts only:
 * consignors, owners, phone numbers and descriptions are never stored. See docs/printed-catalogs.md.
 */
class PrintedCatalogs
{
    /**
     * sale => data source, layout (PrintedCatalogParser, or "qdata" for QData hip pages read by LegacyCatalogParser) and
     * catalogs [file inside DoNotEdit, year, month or null, layout for that catalog]. "overlap": a source whose lots of the
     * same sale and lot number come first (Robin Glenn posts some of these sales' QData hip pages).
     */
    public const SALES = [
        'southwest' => [
            'key' => 'southwesthorsesale', 'name' => 'Southwest Horse Sale (Bush, LA)', 'sale' => 'Southwest Horse Sale', 'url' => 'https://www.swhorseco.com/',
            'layout' => 'southwest',
            'terms' => 'Checked Oct 29 2026: southwesthorsesale.com redirects to swhorseco.com (Wix); robots.txt allows all; no terms of use or legal page (Home, Bid Online, About Us, Contact only); no bot protection. Catalogs are Richard\'s own copies (PDFNotes); nothing fetched from the site. Facts only.',
            'catalogs' => [
                ['BBEB2DA70C8B051F/2766F2CCDDAF877B.pdf', 2014, 4], ['9ADCB11DF7010A5D/ABC9EC8994715B4E.pdf', 2014, 10],
                ['BDCE0B8E47072B06/49F957E4D6292CFB.pdf', 2016, 11], ['91D848386381B293/FD215A8A19CFE113.pdf', 2019, 11],
                ['29AE61BA620278AA/8E3F4E76FEBD1FCA.pdf', 2021, null], ['DE5FFEE132839308/79C79A4FBF8C4358.pdf', 2022, 4],
                ['F23B80F0FA6AB551/BD8C2CD1B1F83B49.pdf', 2024, null], ['1E4784F39403DF91/4FACBE3B003E3B9D.pdf', 2025, 3],
                ['9199BACA0B006F48/E406C3396FADBD8C.pdf', 2026, 3],
            ],
        ],
        'boone' => [
            'key' => 'boonecounty', 'name' => 'Boone County Draft Horse & Mule Sale (Sedalia, MO)', 'sale' => 'Boone County Draft Horse & Mule Sale',
            'url' => 'https://boonecountydrafthorseandmules.com/catalog/', 'layout' => 'boone',
            'terms' => 'Checked Oct 29 2026: boonecountyhorsesale.com redirects to boonecountydrafthorseandmules.com (WordPress); robots.txt (Yoast) allows all; no terms of use or legal page (Forms & Fees has sale terms only); no bot protection. Sale Results page gives category top prices only. Catalogs are Richard\'s own copies (PDFNotes); 2022-2023 copies are scans, read by OCR. Facts only.',
            'catalogs' => [
                ['CCE5C2BEDFB555DB/8A933998AEE3783D.pdf', 2020, 9], ['03CFC1BCE3CC4521/BC6B9652EB0B67B8.pdf', 2021, 3],
                // Scans: read from text-only OCR copies (tesseract, see docs/printed-catalogs.md), never copied over.
                ['A8AB920EB25E7CE7/7778753DDF5E5D52.pdf', 2022, 3, null, null, 'ocr'], ['C23E227CB84FA960/C587B9A8BE9D3247.pdf', 2022, 9, null, null, 'ocr'],
                ['E738D2D2F0CA7CDC/021D50FD13BEDDF5.pdf', 2023, 3, null, null, 'ocr'], ['BA29E2A0045867C1/536AAB223E9F1B63.pdf', 2023, 9, null, null, 'ocr'],
                ['5C9CD86EFAB9C0AA/B0D7B385E11092C6.pdf', 2024, 3], ['FF5089ACE66911C6/4AAC3726B2BA21B1.pdf', 2024, 9],
                ['E304368CC8A7D0C5/372C13B53E653766.pdf', 2025, 3], ['171C387D9D7FF512/69F04CC1BCEFF63D.pdf', 2025, 9],
                ['4850E16E4007450E/F25838A29A2DD87B.pdf', 2026, 3], ['9DF99B20136B0539/FCC231D7ED59FF40.pdf', 2026, 9],
                ['EF4CF3CD4968CE7D/E14288B3BF5841E1.pdf', 2021, 9, 'form'], // a form ("Horse: / Age: / Breed:") instead of the usual lot line
            ],
        ],
        'clovis' => [
            'key' => 'clovishorsesales', 'name' => 'Clovis Horse Sales (Clovis, NM; Levelland Horse Sales, Levelland, TX)', 'sale' => 'Clovis Horse Sale',
            'url' => 'https://www.levellandhorsesales.com/salecatalog', 'layout' => 'cla',
            'terms' => 'Checked Oct 29 2026: the sale moved from Clovis Livestock Auction (clovislivestock.auction, Joomla default robots.txt, no terms page) to Levelland Horse Sales (levellandhorsesales.com, Wix, robots.txt allows all). Its Terms of Use page is an image of the conditions of sale (read by OCR): no clause on copying or collecting. Catalogs are Richard\'s own copies (PDFNotes). Facts only.',
            'catalogs' => [['64011DBE7E519A88/D7091612F513B402.pdf', 2016, 3, 'cla', 'CLA Spring Horse Sale'], ['6C5A6402E766D6E0/769A8FD81F9F1DC3.pdf', 2021, 8, 'consignor', 'Clovis Horse Sales Fall Horse Sale']],
        ],
        'farmers' => [
            'key' => 'fandrlive', 'name' => 'Farmers & Ranchers Livestock Commission horse sales (Salina, KS)', 'sale' => 'Farmers & Ranchers Fall Classic Horse Sale',
            'url' => 'https://www.fandrlive.com/horse-sale-catalog.html', 'layout' => 'farmers',
            'terms' => 'Checked Oct 29 2026: fandrlive.com (Weebly) robots.txt disallows only /ajax/ and /apps/; no terms of use or legal page; no bot protection. Catalog is Richard\'s own copy (PDFNotes); its trees are set in justified type, so only sire and dam are read. Facts only.',
            'catalogs' => [['89EB5C508ED1061C/278008494B982883.pdf', 2021, 10]],
        ],
        'anderson' => [
            'key' => 'andersoncountyhorsesale', 'name' => 'Anderson County Catalog Horse Sale (Anderson County Livestock Exchange, Elkhart, TX)', 'sale' => 'Anderson County Catalog Horse Sale',
            'url' => 'https://lmaauctions.com/', 'layout' => 'lotname',
            'terms' => 'Checked Nov 6-8 2026: the sale has no website of its own; later catalogs are posted on lmaauctions.com, whose Terms of Use (found Nov 8; missed Nov 6) allow personal use only without written permission, so nothing is collected from LMA. Catalog is Richard\'s own copy (PDFNotes), April 17 [2021]; whether it stays is Richard\'s call (asked Nov 8 2026). Facts only.',
            'catalogs' => [['02029BE7AEDBB21B/ED63366CEC452E5D.pdf', 2021, 4, null, null, null, '2021-04-17']],
        ],
        'colorado' => [
            'key' => 'coloradohorsesale', 'name' => 'Colorado Horse Sale (Adams County Fairgrounds, Brighton, CO)', 'sale' => 'Colorado Horse Sale', 'url' => 'https://coloradohorsesale.com/',
            'layout' => 'lotname',
            'terms' => 'Checked Nov 6-8 2026: coloradohorsesale.com (WordPress) robots.txt allows all; its only legal page is Sale Terms (conditions of sale: no clause on copying or collecting); no bot protection. Catalog is Richard\'s own copy (PDFNotes); the Sale Results page\'s files (full tables for 2026 and Oct 2025, top-seller summaries 2023-2025) were fetched Nov 8 2026. Facts only: consignors, buyers and destinations are never read.',
            'catalogs' => [['3A5E5DA0F3A7EA10/08A80926AF6F3083.pdf', 2025, 5, null, null, null, '2025-05-17']],
            'results' => ColoradoHorseSaleResults::class, // the published results, 2023-2026 (printed-catalogs/colorado/results)
        ],
        'fivestarequine' => [
            'key' => 'fivestarequinesale', 'name' => '5 Star Equine Sale (5 Star Arena, Arcola, IL)', 'sale' => '5 Star Equine Sale', 'url' => 'https://fliphtml5.com/',
            'layout' => 'starred',
            'terms' => 'Checked Nov 6 2026: no website found for the sale (its catalogs are FlipHTML5 books), so no robots.txt or terms to read. Catalog is Richard\'s own copy (PDFNotes), May 28-29 2026; his 2025 copy is only a screenshot of the cover. Facts only.',
            'catalogs' => [['092D7ECE4CA47FCA/6DF55069F462F549.pdf', 2026, 5]],
        ],
        'greatamerican' => [
            'key' => 'turnbull', 'name' => 'Great American Ranch & Trail Horse Sale (Turnbull Equine Productions)', 'sale' => 'Great American Ranch & Trail Horse Sale',
            'url' => 'https://greatamericantrailhorsesale.com/catalog/', 'layout' => 'gatrhs21',
            'terms' => 'Already a source (TurnbullHarvester reads 2025-26; robots.txt allows all, Crawl-delay 60). Its 2018-2024 copies had been damaged in the download (cut to about 60% of their size; 2022 thought damaged at the source), not scanned: fresh copies were fetched Nov 6 2026 at one a minute and have text; 2023 (never had) from the site Nov 8; 2016 from the Internet Archive (its link on the site now returns a web page). 2021 is Richard\'s own copy (filed in PDFNotes as "Virginia Horse Center"). Facts only.',
            // 'site': fetched from the sale's site into printed-catalogs/greatamerican (TurnbullHarvester::CATALOGS), not in PDFNotes.
            'catalogs' => [
                ['', 2016, 4, null, null, 'site'], ['', 2018, 4, null, null, 'site'], ['', 2019, 4, null, null, 'site'], ['3D5FBED964D6E8DD/557FFB52CA570E46.pdf', 2021, 4], ['', 2022, 4, null, null, 'site'], ['', 2023, 4, null, null, 'site'], ['', 2024, 4, null, null, 'site'],
            ],
        ],
        'heartandsoul' => [
            'key' => 'heartandsoulhorseco', 'name' => 'Heart and Soul Horse Co. (Ranch & Trail Sale)', 'sale' => 'Heart and Soul Horse Co. November Ranch & Trail Sale', 'url' => 'https://www.facebook.com/',
            'layout' => 'heartandsoul',
            'terms' => 'Checked Nov 6 2026: no website found for the sale, so no robots.txt or terms to read. Catalog is Richard\'s own copy (PDFNotes), November 2019. Its text layer loses word breaks, so a header is kept only where OCR of the page (2019-11.pdf.ocr.txt beside it) agrees letter for letter. Facts only.',
            'catalogs' => [['8489F7D3411F5FB9/29BCC46FBF0F43FB.pdf', 2019, 11]],
        ],
        'heartland' => [
            'key' => 'heartlandhorselivestock', 'name' => 'Heartland Horse & Livestock Sales LLC (Premier Sale)', 'sale' => 'Heartland Horse & Livestock Premier Sale', 'url' => 'https://www.cattleusa.com/',
            'layout' => 'heartland',
            'terms' => 'Checked Nov 6-8 2026: no website found for the sale (its online bidding was on CattleUSA), so no robots.txt or terms to read. Catalog is Richard\'s own copy (PDFNotes), October 24 2020. Hips print year, registry and sex; a registered name is read from the first words of a description only when its year matches the hip\'s heading. Nothing else of the descriptions is kept.',
            'catalogs' => [['64A0D0CCC15CE9C5/5D514B311BD04450.pdf', 2020, 10, null, null, null, '2020-10-24']],
        ],
        'heartofcowboy' => [
            'key' => 'heartofcowboycountry', 'name' => 'Heart of Cowboy Country Horse Sales (Payne County Expo Center, Stillwater, OK)', 'sale' => 'Heart of Cowboy Country Horse Sale', 'url' => 'https://www.paynecountyexpo.com/',
            'layout' => 'hipline',
            'terms' => 'Checked Nov 6 2026: no website found for the sale (2013), so no robots.txt or terms to read. Catalog is Richard\'s own copy (PDFNotes); its small capitals come apart in the text layer, so it is read by OCR. Facts only.',
            'catalogs' => [['', 2013, 5, null, null, 'ocr', '2013-05-29']],
        ],
        'midpoint' => [
            'key' => 'midpointridinghorsesale', 'name' => 'Midpoint Riding Horse Sale (Maple Country Farms, Middlefield, OH)', 'sale' => 'Midpoint Riding Horse Sale', 'url' => 'https://www.google.com/maps/search/17717+Newcomb+Road+Middlefield+OH',
            'layout' => 'dots',
            'terms' => 'Checked Nov 6 2026: no website found for the sale, so no robots.txt or terms to read. Catalogs are Richard\'s own copies (PDFNotes). Facts only.',
            'catalogs' => [['B3B309CD4A4B45F9/83DC9C6ECF6E0AC6.pdf', 2025, 7, null, null, null, '2025-07-11'], ['E61F18D75E52A183/5C67B82492D82FF2.pdf', 2026, 6, null, null, null, '2026-06-26']],
        ],
        'midwestdraftcross' => [
            'key' => 'midwestdraftcrossqh', 'name' => 'Midwest Draft Cross & Quarter Horse Sale (Jamesport, MO)', 'sale' => 'Midwest Draft Cross & Quarter Horse Sale', 'url' => 'https://www.gallopingbranch.com/',
            'layout' => 'starred',
            'terms' => 'Checked Nov 6 2026: the website printed in the catalog (gallopingbranch.com) is now an empty WordPress.com page; no other site found, so no terms to read. Catalog is Richard\'s own copy (PDFNotes; three identical copies), August 28-29 2025. Facts only.',
            'catalogs' => [['2A0EAECF71601760/A8989098B91ED751.pdf', 2025, 8]],
        ],
        'schmitt' => [
            'key' => 'schmitthorseranch', 'name' => 'Schmitt Horse Ranch (Holland, IA): Five Star Horse Sale and online sales', 'sale' => 'Schmitt Horse Ranch Five Star Horse Sale',
            'url' => 'https://www.schmitthorseranch.com/five-star-horse-sale/', 'layout' => 'lotname',
            'terms' => 'Checked Nov 6 2026: schmitthorseranch.com robots.txt allows all with Crawl-delay 60; no terms of use or legal page; no bot protection. Its later catalogs are on Robin Glenn (Five Star 2019 on). Catalogs are Richard\'s own copies (PDFNotes): Five Star February 2018 (a scan, read by OCR) and the December 2020 Timed Internet Offering; May 2022 is the catalog on the site\'s Five Star page (fetched Nov 8 2026, one request). Facts only.',
            'catalogs' => [['', 2018, 2, null, null, 'ocr', '2018-02-03'], ['2F04D58EA6042465/D641BB49A12E8A13.pdf', 2020, 12, 'hipfields', 'Schmitt Horse Ranch Timed Internet Offering'],
                ['', 2022, 5, 'qdata', null, 'site', '2022-05-21']], // 2022: the catalog PDF on the Five Star page (QData hip pages), photos stripped
        ],
        'sdelite' => [
            'key' => 'southdakotaselite', 'name' => 'South Dakota\'s Elite Horse Sale', 'sale' => 'South Dakota\'s Elite Horse Sale', 'url' => 'https://southdakotaselite.com/',
            'layout' => 'bignum',
            'terms' => 'Checked Nov 6 2026: southdakotaselite.com (WordPress) robots.txt allows all; no terms of use or legal page; no bot protection. Live Ag already gives the 2025 sale. 2023 is Richard\'s own copy (PDFNotes); 2026 is the catalog PDF on the site\'s Catalog page. Facts only.',
            'catalogs' => [['670E07FE21E997A6/B41CCAF584B24F33.pdf', 2023, 7], ['', 2026, 7, null, null, 'site', '2026-07-25']], // 2026: the site's catalog PDF, fetched Nov 8 2026
        ],
        'southernbelle' => [
            'key' => 'southernbelleclassic', 'name' => 'Southern Belle Classic (Aiken / Camden, SC)', 'sale' => 'Southern Belle Classic', 'url' => 'https://southernbelleclassic.com/',
            'layout' => 'cards',
            'terms' => 'Checked Nov 2-8 2026: southernbelleclassic.com (Shopify) robots.txt allows its pages; Buyer Terms & Conditions and Privacy Policy have no scraping, crawling or database clause. Listings are on southernbelleclassic.horsesalemanagement.com (Yclas; robots.txt Crawl-delay 10; no terms page), empty between sales, and the Internet Archive has none. Results pages give only the top sellers by hip and consignor. Catalog is Richard\'s own screenshot of the 2024 listing (PDFNotes). Facts only: consignor names, ranches, phones, emails and locations in the cards are never read.',
            'catalogs' => [['2E0F54AF2585274C/D3464C396DE7DD0C.pdf', 2024, 3]],
        ],
        'stroud' => [
            'key' => 'stroudhorseauction', 'name' => 'Stroud Oklahoma Horse Auction (Stroud, OK)', 'sale' => 'Stroud Oklahoma Horse Auction 5th Saturday Special Sale',
            'url' => 'https://drive.google.com/file/d/1DsLg_qFGqAD-D1DWUIdR4iQPtK5xGBPv/view', 'layout' => 'model',
            'terms' => 'Nov 1 2026: no official website found, so no robots.txt or terms to read. Catalog is the auction\'s Google Drive PDF, link given by Richard; downloaded once. The catalog gives no prices. Facts only.',
            'catalogs' => [['', 2026, 10, null, null, 'drive', '2026-10-31']],
        ],
        'twincities' => [
            'key' => 'simonhorsecompany', 'name' => 'Simon Horse Company sales (Cannon Falls, MN)', 'sale' => 'Twin Cities Appaloosa, Paint & Quarter Horse Sale',
            'url' => 'https://www.simonhorsecompany.com/catalog-horse-sales', 'layout' => 'regline',
            'terms' => 'Checked Oct 29 2026: simonhorsecompany.com (Wix) robots.txt allows all; no terms of use or legal page; no bot protection. Catalog is Richard\'s own copy (PDFNotes). Facts only.',
            'catalogs' => [['7550B78812DDF58B/10F97E4611A0BB9C.pdf', 2019, 4]],
        ],
        'shawnee' => [
            'key' => 'shawneehorsesale', 'name' => 'Shawnee Horse Sale (Shawnee, OK)', 'sale' => 'Shawnee Horse Sale', 'url' => 'https://www.robinglenn.com/',
            'layout' => 'qdata', 'overlap' => ['robinglenn', 'Shawnee'],
            'terms' => 'Oct 29 2026: the sale\'s catalogs are posted on Robin Glenn (already a Horsefax source, which has Fall 2020 on); shawneehorsesales.com is a parked domain. Only Richard\'s Spring 2020 copy is read: his other Shawnee copies are sales Robin Glenn already gives. Facts only.',
            'catalogs' => [['8EA1F46CB7975932/E4344320883CA48A.pdf', 2020, 5]],
        ],
        'seymour' => [
            'key' => 'seymourselect', 'name' => 'Seymour Select Horse Sale (Seymour, IA)', 'sale' => 'Seymour Select Horse Sale', 'url' => 'https://www.robinglenn.com/',
            'layout' => 'seymour', 'overlap' => ['robinglenn', 'Seymour'],
            'terms' => 'Oct 29 2026: a benefit sale for the Seymour Amish schools with no website of its own; its QData hip pages are on Robin Glenn (already a source), so lots Robin Glenn has are skipped. Catalog is Richard\'s own copy. Facts only.',
            'catalogs' => [['1B551ECFF42C59EE/D505EFA22BB38A73.pdf', 2026, 5]],
        ],
    ];

    private string $dir;

    public function __construct(private ?\Closure $log = null, ?string $dir = null)
    {
        $this->dir = $dir ?? storage_path('app/private/printed-catalogs');
    }

    public static function library(): string
    {
        return getenv('HOME').'/Diamond J Dropbox/Richard Jacuzzi/Horsefax/Sale Catalogs/DoNotEdit';
    }

    public static function file(int $year, ?int $month): string
    {
        return sprintf('%04d-%02d.pdf', $year, $month ?? 0);
    }

    /** Copy the catalogs from the PDFNotes library (on Richard's Mac; the server gets the copies by rsync). */
    public function copy(?string $sale = null): int
    {
        $n = 0;
        foreach (self::SALES as $key => $s) {
            if ($sale && $sale !== $key) {
                continue;
            }
            @mkdir($this->dir.'/'.$key, 0775, true);
            foreach ($s['catalogs'] as $c) {
                [$file, $year, $month] = $c;
                if (! empty($c[5])) {
                    continue; // an OCR copy made from the scan, or a catalog that isn't in the PDFNotes library
                }
                $to = $this->dir.'/'.$key.'/'.self::file($year, $month);
                if (! is_file($to) && is_file(self::library().'/'.$file)) {
                    copy(self::library().'/'.$file, $to);
                    $n++;
                }
            }
        }

        return $n;
    }

    public function source(string $sale): DataSource
    {
        $s = self::SALES[$sale];

        return DataSource::firstOrCreate(['key' => $s['key']], [
            'name' => $s['name'], 'url' => $s['url'], 'permission' => 'no_terms_posted',
            'terms_note' => $s['terms'].' Richard asked to read all his catalogs (Oct 28 2026).',
        ]);
    }

    public function load(?string $sale = null, bool $dryRun = false): array
    {
        $all = [];
        foreach (self::SALES as $key => $s) {
            if ($sale && $sale !== $key) {
                continue;
            }
            $out = ['catalogs' => 0, 'lots' => 0, 'with_reg' => 0, 'pedigrees' => 0, 'matched' => 0, 'conflict' => 0, 'proposed' => 0, 'linked' => 0, 'created' => 0, 'queued' => 0, 'unmatched' => 0, 'already' => 0];
            $src = $dryRun ? null : $this->source($key);
            $overlap = isset($s['overlap']) ? DataSource::where('key', $s['overlap'][0])->value('id') : null;
            foreach ($s['catalogs'] as $c) {
                [, $year, $month] = $c;
                $layout = $c[3] ?? $s['layout'];
                $memory = ($c[5] ?? null) === 'ocr' ? ini_set('memory_limit', '4G') : null;
                $path = $this->dir.'/'.$key.'/'.self::file($year, $month);
                if (! is_file($path)) {
                    $this->say("missing $path");

                    continue;
                }
                $pdf = file_get_contents($path);
                $lots = match ($layout) {
                    'qdata' => self::qdata($pdf),
                    'farmers' => PrintedCatalogParser::farmers(StatePdfHarvester::text($pdf)),
                    'cards' => PrintedCatalogParser::webCards($pdf, $year),
                    'heartland' => PrintedCatalogParser::heartland($pdf, $year),
                    'heartandsoul' => PrintedCatalogParser::heartAndSoul($pdf, (string) @file_get_contents($path.'.ocr.txt')),
                    default => PrintedCatalogParser::read($pdf, $layout, $year),
                };
                $out['catalogs']++;
                $this->say($key.' '.self::file($year, $month).': '.count($lots).' lots, '.count(array_filter($lots, fn ($l) => $l['pedigree'])).' pedigrees');
                $days = $month ? LolliHarvester::saleDays($year, $month, StatePdfHarvester::text($pdf)) : [];
                [$date, $precision] = match (true) {
                    isset($c[6]) => [$c[6], 'day'],
                    count($days) === 1 => [$days[0], 'day'],
                    (bool) $month => [sprintf('%04d-%02d-01', $year, $month), 'month'],
                    default => [$year.'-01-01', 'year'],
                };
                $saleName = ($c[4] ?? $s['sale']).', '.($month ? date('F ', mktime(0, 0, 0, $month, 1, 2000)) : '').$year;
                foreach ($lots as $l) {
                    if ($overlap && SaleLot::where('data_source_id', $overlap)->where('lot', $l['lot'])->where('sale_name', 'ilike', '%'.$s['overlap'][1].'%')
                        ->whereBetween('sale_date', [date('Y-m-d', strtotime($date.' -45 days')), date('Y-m-d', strtotime($date.' +45 days'))])->exists()) {
                        $out['skipped_overlap'] = ($out['skipped_overlap'] ?? 0) + 1;

                        continue; // the same hip from the sale's own posting on Robin Glenn
                    }
                    $out['lots']++;
                    $out['with_reg'] += $l['registration_number'] ? 1 : 0;
                    $out['pedigrees'] += $l['pedigree'] ? 1 : 0;
                    if ($dryRun) {
                        continue;
                    }
                    $lot = SaleLot::record($src->id, $key.':'.sprintf('%04d-%02d', $year, $month ?? 0).':'.$l['lot'], [
                        'sale_name' => $saleName, 'sale_date' => $date, 'date_precision' => $precision, 'lot' => $l['lot'],
                        'horse_name' => mb_substr($l['name'], 0, 255), 'registered' => $l['registered'], 'registry' => $l['registry'],
                        'registration_number' => $l['registration_number'], 'foal_year' => $l['foal_year'], 'birth_date' => $l['birth_date'],
                        'sex' => $l['sex'], 'color' => $l['color'], 'breed' => $l['breed'],
                        'sire_name' => $l['pedigree']['s'] ?? null, 'dam_name' => $l['pedigree']['d'] ?? null, 'dam_sire_name' => $l['pedigree']['ds'] ?? null,
                        'pedigree' => $l['pedigree'] ?: null, 'status' => 'offered', 'price_cents' => null, 'buyers_premium' => 'unknown', 'source_url' => $s['url'],
                        'note' => 'Catalogued for the sale (from a printed catalog copy); the catalog gives no prices',
                    ]);
                    $out[RegistrationOnlyMatch::place($lot, $s['sale'])]++;
                }
            }
            if (isset($s['results'])) {
                $out['results'] = $this->results($key, $src, $dryRun);
            }
            $all[$key] = $out;
        }

        return $all;
    }

    /**
     * A sale's published results (ColoradoHorseSaleResults). A lot the catalog already has keeps its match and gets the
     * price; an unmatched catalog lot also takes the results' name (often the registered one) and is placed again.
     */
    private function results(string $key, ?DataSource $src, bool $dryRun): array
    {
        $s = self::SALES[$key];
        $reader = $s['results'];
        $out = ['rows' => 0, 'priced' => 0, 'updated' => 0, 'new' => 0];
        foreach ($reader::FILES as $file => [$date, $precision, $kind]) {
            $path = $this->dir.'/'.$key.'/results/'.$file;
            if (! is_file($path)) {
                $this->say("missing $path");

                continue;
            }
            $rows = $reader::read($path, $kind);
            $this->say($key.' results '.$file.': '.count($rows));
            $saleName = $s['sale'].', '.date('F Y', strtotime($date));
            foreach ($rows as $r) {
                $out['rows']++;
                $out['priced'] += $r['price_cents'] ? 1 : 0;
                if ($dryRun) {
                    continue;
                }
                [$name, $facts] = PrintedCatalogParser::nameFacts($r['name']);
                $desc = PrintedCatalogParser::free($r['description'], (int) substr($date, 0, 4));
                $values = ['status' => $r['status'], 'price_cents' => $r['price_cents'], 'buyers_premium' => 'unknown', 'source_url' => $s['url'].'sale-results/',
                    'note' => $kind === 'table' ? 'From the sale\'s published results' : 'From the sale\'s published top sellers'];
                $id = $key.':'.substr($date, 0, 7).':'.$r['lot'];
                $lot = SaleLot::where('data_source_id', $src->id)->where('external_id', $id)->first();
                if ($lot && $lot->horse_id) {
                    $lot->fill($values)->save();
                    $out['updated']++;

                    continue;
                }
                $lot = SaleLot::record($src->id, $id, $values + [
                    'sale_name' => $saleName, 'sale_date' => $date, 'date_precision' => $precision, 'lot' => $r['lot'], 'horse_name' => mb_substr($name, 0, 255),
                    'registered' => $facts['registered'] ?? $desc['registered'], 'registry' => $facts['registry'] ?? $desc['registry'], 'registration_number' => $facts['registration_number'] ?? null,
                    'foal_year' => $desc['foal_year'], 'birth_date' => $desc['birth_date'], 'sex' => $desc['sex'], 'color' => $desc['color'], 'breed' => $facts['breed'] ?? $desc['breed'],
                ]);
                $out['new']++;
                $placed = 'placed_'.RegistrationOnlyMatch::place($lot, $s['sale']);
                $out[$placed] = ($out[$placed] ?? 0) + 1;
            }
        }

        return $out;
    }

    /**
     * Boone County team lots ("LOT 131 & 132 - Pete & Paul") were read as one horse named "& 132 - PETE & PAUL" before the
     * anchor skipped them (fixed Oct 29 2026): records made from them go (CleanPendingFoalRecords' checks), then the lots.
     */
    public function cleanTeamLots(): array
    {
        $src = DataSource::where('key', self::SALES['boone']['key'])->value('id');
        if (! $src) {
            return ['team_lots' => 0];
        }
        $team = fn ($n) => (bool) preg_match('/^&\s*\d/', (string) $n);
        [$out] = app(CleanPendingFoalRecords::class)->forLabels($team, '&%', 'A team of two horses, not one horse\'s name')();
        $lots = SaleLot::where('data_source_id', $src)->where('horse_name', 'like', '&%')->whereNull('horse_id')->get()->filter(fn ($l) => $team($l->horse_name));
        DB::table('review_suggestions')->where('subject_type', 'sale_lot')->whereIn('subject_id', $lots->pluck('id'))->whereNull('decided_by')->delete();
        $out['team_lots_deleted'] = SaleLot::whereIn('id', $lots->pluck('id'))->delete();

        return $out;
    }

    /**
     * Lots whose "name" is a description ("SORREL STUD COLT", "AQHA BUCKSKIN GELDING", "TB/CONNEMARA"), read before
     * PrintedCatalogParser::notAName (fixed Nov 8 2026): records made from them go (CleanPendingFoalRecords' checks), then the lots.
     */
    public function cleanUnnamedLots(): array
    {
        $junk = fn ($n) => PrintedCatalogParser::notAName((string) $n);
        $out = ['records_removed' => 0, 'kept' => [], 'lots_deleted' => 0];
        foreach (['%GELDING', '%MARE', '%COLT', '%STALLION', '%FILLY', '%/%', 'AQHA%', 'APHA%'] as $like) {
            [$o] = app(CleanPendingFoalRecords::class)->forLabels($junk, $like, 'A description, not a horse\'s name')();
            $out['records_removed'] += $o['removed'];
            foreach ($o['kept'] as $why => $n) {
                $out['kept'][$why] = ($out['kept'][$why] ?? 0) + $n;
            }
        }
        $sources = DataSource::whereIn('key', array_column(self::SALES, 'key'))->pluck('id');
        $lots = SaleLot::whereIn('data_source_id', $sources)->whereNull('horse_id')->get(['id', 'horse_name'])->filter(fn ($l) => $junk($l->horse_name));
        DB::table('review_suggestions')->where('subject_type', 'sale_lot')->whereIn('subject_id', $lots->pluck('id'))->whereNull('decided_by')->delete();
        $out['lots_deleted'] = SaleLot::whereIn('id', $lots->pluck('id'))->delete();

        return $out;
    }

    /** QData hip pages ("Hip No." layout) in PrintedCatalogParser's lot shape. */
    public static function qdata(string $pdf): array
    {
        return array_map(function ($h) {
            $grade = (bool) preg_match('/\bGrade\b/i', (string) $h['color']);
            $color = trim(preg_replace('/\b(Grade|Pony)\b/i', '', (string) $h['color'])) ?: null;

            return ['lot' => $h['hip'], 'name' => $h['name'], 'registry' => null, 'registration_number' => $grade ? null : $h['registration_number'],
                'registered' => $grade ? false : ($h['registration_number'] || $h['pedigree'] ? true : null), 'breed' => null, 'sex' => $h['sex'], 'color' => $color,
                'birth_date' => $h['birth_date'], 'foal_year' => $h['foal_year'], 'pedigree' => $grade ? [] : $h['pedigree']];
        }, LegacyCatalogParser::catalog($pdf));
    }

    private function say(string $line): void
    {
        if ($this->log) {
            ($this->log)($line);
        }
    }
}
