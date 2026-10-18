<?php

namespace App\Harvest;

/** English labels for the IFCE–SIRE file's French breed, color and country labels. Unknown labels keep their own wording. */
class IfceLabels
{
    /** Donkeys, mules, hinnies and zebra hybrids: not imported for now (Richard, Oct 18 2026: horses and ponies). */
    public static function isDonkeyOrMule(string $breed): bool
    {
        return (bool) preg_match('/^(ANE|MULE|MULET|BARDOT|BAUDET|MINI MED\.DONKEY|ORIG\.ETR\.ANE|ORIG\. NC ZEBRE)/', strtoupper(trim($breed)));
    }

    public const BREEDS = [
        'TROTTEUR FR.' => 'French Trotter', 'PUR SANG' => 'Thoroughbred', 'PONEY NON CONST' => 'Pony (breed not recorded)',
        'SELLE NON CONST' => 'Riding horse (breed not recorded)', 'SF SECTION A' => 'Selle Français (section A)', 'SF SECTION B' => 'Selle Français (section B)',
        'TRAIT' => 'Draft horse', 'ORIGINE CONST.' => 'Recorded origin', 'TRAIT COMTOIS' => 'Comtois', 'SELLE FRANCAIS' => 'Selle Français', 'SF' => 'Selle Français',
        'BRETON' => 'Breton', 'OR. INCONNUE' => 'Unknown origin', 'ARABE' => 'Arabian', 'TRAIT NON CONST' => 'Draft horse (breed not recorded)',
        'OI TYPE PONEY' => 'Unknown origin (pony type)', 'OI TYPE TRAIT' => 'Unknown origin (draft type)', 'CHEV. DE SELLE' => 'Riding horse',
        'PURA RAZA ESPAN' => 'Pura Raza Española', 'PONEY FRANCAIS' => 'French Pony', 'ORIG.ETR.SEL' => 'Foreign origin (riding horse)',
        'PERCHERON' => 'Percheron', 'SHETLAND' => 'Shetland Pony', 'ANGLO-ARABE' => 'Anglo-Arabian', '*ANGLO-ARABE*' => 'Anglo-Arabian',
        'QUARTER HORSE' => 'Quarter Horse', 'Q H APPENDIX' => 'Appendix Quarter Horse', 'QUARTER PONY.' => 'Quarter Pony', 'PAINT HORSE' => 'Paint Horse',
        'APPALOOSA' => 'Appaloosa', 'PINTO' => 'Pinto', 'MORGAN HORSE' => 'Morgan', 'PB MORGAN' => 'Morgan (part-bred)', 'ROCKY MOUNTAIN' => 'Rocky Mountain Horse',
        'MINIATURE US' => 'American Miniature Horse', 'MINIATURE US HR' => 'American Miniature Horse', 'AMER. SHETLAND' => 'American Shetland Pony',
        'PONY OF AMERICA' => 'Pony of the Americas', 'NOKOTA' => 'Nokota', 'CURLY' => 'American Curly', 'AM. AZTECA H.' => 'American Azteca', 'CHEVAL AZTEQUE' => 'Azteca',
        'CONNEMARA' => 'Connemara Pony', 'CONNEMARA PB' => 'Connemara (part-bred)', 'ARDENNAIS' => 'Ardennes', 'DEMI-SANG ARABE' => 'Half-Arabian', 'PONEY' => 'Pony',
        'PURE RACE LUSIT' => 'Lusitano', 'KON. WARMB. NED' => 'KWPN (Dutch Warmblood)', 'CAMARGUE' => 'Camargue', 'PONEY HAFLINGER' => 'Haflinger', 'MERENS' => 'Mérens',
        'AUTRE QUE PS' => 'AQPS (non-Thoroughbred racehorse)', 'ZANGERSHEIDE' => 'Zangersheide', 'COB NORMAND' => 'Cob Normand', 'SELLE ETRANGER' => 'Foreign riding horse',
        'BELGIAN WARMB.' => 'Belgian Warmblood', 'POTTOK' => 'Pottok', 'HANNOVERANER' => 'Hanoverian', 'RACE INCONNUE' => 'Unknown breed', 'NEW FOREST' => 'New Forest Pony',
        'FJORD' => 'Fjord', 'WELSH MOUNTAIN' => 'Welsh Mountain Pony', 'TROTTEUR ETR.' => 'Foreign Trotter', 'ISLANDAIS' => 'Icelandic Horse', 'BOULONNAIS' => 'Boulonnais',
        'FRISON' => 'Friesian', 'WELSH PONY' => 'Welsh Pony', 'WELSH COB' => 'Welsh Cob', 'WELSH PART-BRED' => 'Welsh Part-Bred', 'WELSH TYPE COB' => 'Welsh Pony of Cob Type',
        'HOLST. WARMBLUT' => 'Holsteiner', 'OLDENBURGER' => 'Oldenburg', 'FRANCHES-MONT.' => 'Franches-Montagnes', 'PONEY ETRANGER' => 'Foreign pony',
        'CHEV. SP BELGE' => 'Belgian Sport Horse', 'TRAIT DU NORD' => 'Trait du Nord', 'DEMI SANG' => 'Half-bred', 'IRISH COB' => 'Irish Cob', 'COB' => 'Cob',
        'TRAIT AUXOIS' => 'Auxois', 'WESTF. RH' => 'Westphalian', 'DARTMOOR' => 'Dartmoor Pony', 'ARABE-BARBE' => 'Arab-Barb', 'BARBE' => 'Barb', 'TRAKEHNER' => 'Trakehner',
        'IRISH SH' => 'Irish Sport Horse', 'POITEVIN' => 'Poitevin', 'PONEY LANDAIS' => 'Landais Pony', 'SHAGYA' => 'Shagya Arabian', 'LIPIZZAN' => 'Lipizzaner',
        'HIGHLAND' => 'Highland Pony', 'DEUTS. REITPONY' => 'German Riding Pony', 'CRIOLLO' => 'Criollo', 'TRAIT BELGE' => 'Belgian Draft', 'AKHAL TEKE P.S.' => 'Akhal-Teke',
        'GD NOIR BERRY' => 'Grand Noir du Berry', 'TRAD GYPSY COB' => 'Gypsy Cob', 'GYPSY COB' => 'Gypsy Cob', 'CHEVAL HENSON' => 'Henson', 'RHEIN. WARMB.' => 'Rhinelander',
        'CHEVAL AUVERGNE' => 'Auvergne Horse', 'SHIRE' => 'Shire', 'CLYDESDALE' => 'Clydesdale', 'HACKNEY' => 'Hackney', 'IRISH DRAUGHT' => 'Irish Draught',
        'DANISH WARMB.' => 'Danish Warmblood', 'SWEDISH WARMB.' => 'Swedish Warmblood', 'BAYER WARMB.' => 'Bavarian Warmblood', 'FELL PONY' => 'Fell Pony',
        'DALES PONY' => 'Dales Pony', 'EXMOOR' => 'Exmoor Pony', 'KNABSTRUPPER' => 'Knabstrupper', 'CLEVELAND BAY' => 'Cleveland Bay', 'TINKER' => 'Tinker',
        'SELLA ITALIANO' => 'Sella Italiano', 'IRISH P.S.' => 'Irish Thoroughbred', 'WARMBLUT SPORT' => 'Sport Warmblood', 'MINIATURE FR' => 'French Miniature Horse',
    ];

    public const COLORS = [
        'BAI' => 'Bay', 'ALEZAN' => 'Chestnut', 'GRIS' => 'Grey', 'BAI FONCE' => 'Dark Bay', 'NOIR PANGARE' => 'Brown', 'NOIR' => 'Black',
        'ALEZAN CRINS LAVES' => 'Flaxen Chestnut', 'BAI PIE' => 'Bay Pinto', 'ISABELLE' => 'Buckskin', 'ALEZAN PIE' => 'Chestnut Pinto', 'ALEZAN BRULE' => 'Liver Chestnut',
        'ROUAN' => 'Roan', 'NOIR PIE' => 'Black Pinto', 'GRIS CLAIR' => 'Light Grey', 'PALOMINO' => 'Palomino', 'GRIS FONCE' => 'Dark Grey', 'AUBERE' => 'Strawberry Roan',
        'ALEZAN FONCE' => 'Dark Chestnut', 'BAI CLAIR' => 'Light Bay', 'ALEZAN MELANGE' => 'Chestnut with white hairs', 'BAI MELANGE' => 'Bay with white hairs',
        'SOURIS' => 'Grulla', 'CHOCOLAT' => 'Chocolate', 'GRIS TOURTERELLE' => 'Dove Grey', 'GRIS TRUITE' => 'Flea-bitten Grey', 'CREME' => 'Cremello',
        'GRIS PIE' => 'Grey Pinto', 'ALEZAN CUIVRE' => 'Copper Chestnut', 'GRIS FER' => 'Iron Grey', 'BAI CERISE' => 'Blood Bay', 'INDETERMINE' => null,
        'LEOPARD' => 'Leopard', 'NOIR PANGARE PIE' => 'Brown Pinto', 'ISABELLE PIE' => 'Buckskin Pinto', 'CAFE AU LAIT' => 'Café au Lait', 'GRIS MOUCHETE' => 'Speckled Grey',
        'BLANC' => 'White', 'PALOMINO PIE' => 'Palomino Pinto', 'BAI CRINS LAVES' => 'Bay with flaxen mane', 'PIE' => 'Pinto', 'LOUVET' => 'Dun', 'DUN' => 'Dun',
        'CHOCOLAT PIE' => 'Chocolate Pinto', 'SOURIS PIE' => 'Grulla Pinto', 'CREME PIE' => 'Cremello Pinto', 'TACHETE' => 'Spotted', 'BAI TACHETE' => 'Bay Spotted',
        'ALEZAN TACHETE' => 'Chestnut Spotted', 'NOIR TACHETE' => 'Black Spotted', 'GRIS TACHETE' => 'Grey Spotted',
    ];

    public const COUNTRIES = [
        'FRANCE' => 'France', 'ALLEMAGNE' => 'Germany', 'PAYS-BAS' => 'Netherlands', 'BELGIQUE' => 'Belgium', 'ETATS-UNIS' => 'United States', 'IRLANDE' => 'Ireland',
        'GRANDE-BRETAGNE' => 'Great Britain', 'ROYAUME-UNI' => 'United Kingdom', 'ESPAGNE' => 'Spain', 'PORTUGAL' => 'Portugal', 'ITALIE' => 'Italy', 'SUISSE' => 'Switzerland',
        'DANEMARK' => 'Denmark', 'SUEDE' => 'Sweden', 'AUTRICHE' => 'Austria', 'POLOGNE' => 'Poland', 'CANADA' => 'Canada', 'ARGENTINE' => 'Argentina', 'BRESIL' => 'Brazil',
        'LUXEMBOURG' => 'Luxembourg', 'NORVEGE' => 'Norway', 'ISLANDE' => 'Iceland', 'MAROC' => 'Morocco', 'AUSTRALIE' => 'Australia', 'NOUVELLE-ZELANDE' => 'New Zealand',
        'TCHEQUIE' => 'Czechia', 'HONGRIE' => 'Hungary', 'MEXIQUE' => 'Mexico', 'EMIRATS ARABES UNIS' => 'United Arab Emirates', 'JAPON' => 'Japan',
    ];

    public static function breed(string $s): ?string
    {
        $k = strtoupper(trim($s));

        return $k === '' || $k === 'NON CONSTATEE' ? null : (self::BREEDS[$k] ?? ucwords(strtolower(trim($s))));
    }

    public static function color(string $s): ?string
    {
        $k = strtoupper(trim($s));

        return $k === '' ? null : (array_key_exists($k, self::COLORS) ? self::COLORS[$k] : ucwords(strtolower(trim($s))));
    }

    public static function country(string $s): ?string
    {
        $k = strtoupper(trim($s));

        return $k === '' ? null : (self::COUNTRIES[$k] ?? ucwords(strtolower(trim($s))));
    }

    public static function sex(string $s): ?string
    {
        return ['M' => 'stallion', 'F' => 'mare', 'H' => 'gelding'][strtoupper(trim($s))] ?? null;
    }
}
