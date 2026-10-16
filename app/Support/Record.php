<?php

namespace App\Support;

/**
 * Shared vocabularies for the Horsefax record.
 */
final class Record
{
    public const VISIBILITY = [
        'private' => 'Private (you and Horsefax staff)',
        'shared' => 'Shared (only in report links you create)',
        'public' => 'Public (on the public summary, if turned on)',
    ];

    public const STATUS = [
        'self_reported' => 'Self-reported',
        'document_supplied' => 'Document supplied',
        'independently_verified' => 'Independently verified',
        'disputed' => 'Disputed',
        'superseded' => 'Superseded',
    ];

    public const EVENT_CATEGORIES = [
        'health' => 'Health & veterinary',
        'care' => 'Care (farrier, dental, deworming)',
        'training' => 'Training',
        'competition' => 'Competition & achievements',
        'ownership' => 'Ownership & custody',
        'transport' => 'Transport',
        'other' => 'Other',
    ];

    /** Health detail is sensitive: private unless the owner chooses otherwise. */
    public const DEFAULT_EVENT_VISIBILITY = [
        'health' => 'private', 'care' => 'private', 'training' => 'shared', 'competition' => 'shared',
        'ownership' => 'private', 'transport' => 'private', 'other' => 'private',
    ];

    /** Entry types within each category. Health detail is what buyers and vets ask for first. */
    public const EVENT_TYPES = [
        'health' => ['coggins' => 'Coggins (EIA) test', 'health_certificate' => 'Health certificate (CVI)', 'vaccination' => 'Vaccination', 'exam' => 'Veterinary exam', 'pre_purchase' => 'Pre-purchase exam', 'treatment' => 'Treatment / procedure', 'injury' => 'Injury or illness', 'imaging' => 'Radiographs / imaging', 'medication' => 'Medication'],
        'care' => ['farrier' => 'Farrier', 'dental' => 'Dental', 'deworming' => 'Deworming', 'bodywork' => 'Chiropractic / massage', 'nutrition' => 'Feeding / nutrition'],
        'training' => ['program' => 'Training program', 'evaluation' => 'Evaluation / assessment', 'skill' => 'Skill learned', 'temperament' => 'Temperament observation'],
        'competition' => ['show' => 'Show / class result', 'award' => 'Award / title', 'certification' => 'Certification / inspection'],
        'ownership' => ['sale' => 'Sale', 'purchase' => 'Purchase', 'auction' => 'Auction', 'lease' => 'Lease', 'gift' => 'Gift / transfer', 'breeding' => 'Foaled / bred by'],
        'transport' => ['haul' => 'Transport'],
        'other' => ['note' => 'Note', 'retirement' => 'Retirement', 'death' => 'Death'],
    ];

    public const REGISTRIES = [
        'AQHA' => 'American Quarter Horse Association (AQHA)',
        'APHA' => 'American Paint Horse Association (APHA)',
        'ApHC' => 'Appaloosa Horse Club (ApHC)',
        'Jockey Club' => 'The Jockey Club (Thoroughbred)',
        'AHA' => 'Arabian Horse Association (AHA)',
        'PtHA' => 'Pinto Horse Association (PtHA)',
        'ABRA' => 'American Buckskin Registry (ABRA)',
        'IBHA' => 'International Buckskin Horse Assn (IBHA)',
        'PHBA' => 'Palomino Horse Breeders (PHBA)',
        'TWHBEA' => 'Tennessee Walking Horse (TWHBEA)',
        'AMHA' => 'American Morgan Horse Assn (AMHA)',
        'GVHS' => 'Gypsy Vanner Horse Society (GVHS)',
        'FHANA' => 'Friesian Horse Assn of N. America (FHANA)',
        'MFTHBA' => 'Missouri Fox Trotting Horse (MFTHBA)',
        'ASHA' => 'American Saddlebred (ASHA)',
        'USTA' => 'U.S. Trotting Assn (Standardbred)',
        'AHHA' => 'American Haflinger Registry',
        'ARHA' => 'American Ranch Horse Association (ARHA)',
        'Other' => 'Other registry',
    ];

    /** Pedigrees are recorded to this many generations (allbreedpedigree shows five). */
    public const PEDIGREE_GENERATIONS = 5;

    public const GENERATION_NAMES = [
        1 => 'Sire & dam', 2 => 'Grandparents', 3 => 'Great-grandparents', 4 => 'Great-great-grandparents', 5 => '3rd great-grandparents',
    ];

    /**
     * Every chart position with its label, in chart order: s, d, ss, sd, ds, dd, sss, ... A position's parents are
     * pos.'s' and pos.'d'.
     *
     * @return array<string, string>
     */
    public static function pedigreePositions(): array
    {
        static $out = null;
        if ($out !== null) {
            return $out;
        }
        $out = [];
        $level = ['s', 'd'];
        for ($g = 1; $g <= self::PEDIGREE_GENERATIONS; $g++) {
            foreach ($level as $pos) {
                $out[$pos] = self::pedigreeLabel($pos);
            }
            $level = array_merge(...array_map(fn ($p) => [$p.'s', $p.'d'], $level));
        }

        return $out;
    }

    /** "sds" becomes "Sire's dam's sire". */
    public static function pedigreeLabel(string $pos): string
    {
        $words = array_map(fn ($c) => $c === 's' ? 'sire' : 'dam', str_split($pos));

        return ucfirst(implode("'s ", $words));
    }

    public const DOCUMENT_KINDS = [
        'registration_papers' => 'Registration papers',
        'coggins' => 'Coggins test',
        'health_certificate' => 'Health certificate',
        'vet_record' => 'Veterinary record',
        'bill_of_sale' => 'Bill of sale / transfer',
        'competition_record' => 'Competition record',
        'photo' => 'Photo',
        'other' => 'Other',
    ];

    public const IDENTIFIER_KINDS = [
        'registration' => 'Registration number',
        'microchip' => 'Microchip',
        'other' => 'Other identifier (brand, passport, DNA case)',
    ];

    /** Professions an owner can invite to contribute to a record. The key is stored as the entry's contributor_role. */
    public const CONTRIBUTOR_ROLES = [
        'veterinarian' => 'Veterinarian',
        'farrier' => 'Farrier',
        'trainer' => 'Trainer',
        'breeder' => 'Breeder',
    ];

    /** Breed and color lists from Richard's Search Terms.xlsx (same as Trusted Horse's TermSeeder). "Other" lets people type their own. */
    public const BREEDS = [
        'Akhal Teke', 'American Cream', 'Andalusian', 'Anglo-Arabian', 'Appaloosa', 'Appendix',
        'AraAppaloosa', 'Arabian', 'Araloosa', 'Australian Stock', 'Azteca', 'Barb',
        'Barock Pinto', 'Bashkir Curly', 'Belgian', 'Belgian Warmblood', 'Budyonny', 'Canadian',
        'Chincoteague', 'Cleveland Bay', 'Clydesdale', 'Connemara Pony', 'Crossbred Pony', 'Curly',
        'Dales Pony', 'Dartmoor Pony', 'Donkey', 'Draft', 'Drum', 'Dutch Warmblood',
        'Exmoor Pony', 'Fell Pony', 'Fjord', 'Florida Cracker', 'Friesian', 'Galiceno',
        'Gotland Pony', 'Gypsy Vanner', 'Hackney', 'Haflinger', 'Half Arabian', 'Hanoverian',
        'Harlequin', 'Highland Pony', 'Holsteiner', 'Hungarian', 'Iberian', 'Icelandic',
        'Irish Draught', 'Kentucky Mountain', 'Knabstrupper', 'Lipizzan', 'Lusitano', 'Marchador',
        'Miniature', 'Missouri Fox Trotter', 'Morab', 'Morgan', 'Mountain Pleasure', 'Mule',
        'Mustang', 'National Show', 'National Sport Performance Pony', 'New Forest Pony', 'Newfoundland Pony', 'Nokota',
        'Oldenburg', 'Paint', 'Paint Pony', 'Paso Fino', 'Percheron', 'Peruvian Paso',
        'Pintabian', 'Pony', 'POA', 'Quarab', 'Quarter Horse', 'Quarter Pony',
        'Racking', 'Rocky Mountain', 'Saddlebred', 'Selle Français', 'Shagya', 'Shetland Pony',
        'Shire', 'Spanish Mustang', 'Spotted Saddle', 'Standardbred', 'Tennessee Walking', 'Thoroughbred',
        'Tiger', 'Trakehner', 'Walkaloosa', 'Warlander', 'Warmblood', 'Welsh Cob',
        'Welsh Pony', 'Westphalian',
    ];

    public const COLORS = [
        'Bay', 'Bay Overo', 'Bay Roan', 'Black', 'Black Overo', 'Blue Roan',
        'Brown', 'Buckskin', 'Buckskin Overo', 'Champagne', 'Chestnut', 'Chestnut Overo',
        'Chocolate', 'Cremello', 'Dun', 'Dun with Black Points', 'Dunalino', 'Grey',
        'Grulla', 'Blue Grulla', 'Isabelo', 'Liver Chestnut', 'Palomino', 'Perlino',
        'Red Dun', 'Red Roan', 'Seal Brown', 'Silver Bay', 'Silver Dapple', 'Smoky Black',
        'Sorrel', 'Sorrel Overo', 'White',
    ];

    /** Heights in hands, 7.0 to 19.0 (x.0 to x.3). */
    public static function heights(): array
    {
        $out = [];
        for ($h = 7; $h <= 19; $h++) {
            foreach ([0, 1, 2, 3] as $i) {
                if ($h === 19 && $i > 0) {
                    break;
                }
                $out[] = $h.'.'.$i;
            }
        }

        return $out;
    }

    public const SEXES = ['mare' => 'Mare', 'stallion' => 'Stallion', 'gelding' => 'Gelding', 'filly' => 'Filly', 'colt' => 'Colt'];

    /** Sections a share link can include. Identity basics are always in a report. */
    public const SHARE_SECTIONS = [
        'identifiers' => 'Registration and other identifiers',
        'health' => 'Health & veterinary',
        'care' => 'Care',
        'training' => 'Training',
        'competition' => 'Competition & achievements',
        'ownership' => 'Ownership & custody history',
        'transport' => 'Transport',
        'other' => 'Other events',
        'pedigree' => 'Pedigree and offspring',
        'media' => 'Photos and videos (released ones only)',
        'sale_prices' => 'Sale prices (only entries where you made the price shareable)',
        'documents' => 'Document list (titles and dates, not the files)',
    ];

    public static function eventTypeLabel(?string $category, ?string $type): ?string
    {
        return $type ? (self::EVENT_TYPES[$category][$type] ?? ucfirst(str_replace('_', ' ', $type))) : null;
    }

    public static function normalizeIdentifier(string $value): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $value));
    }

    /** Show only the last four characters of a microchip number. */
    public static function mask(string $value): string
    {
        $v = self::normalizeIdentifier($value);

        return strlen($v) <= 4 ? '••••' : str_repeat('•', max(4, strlen($v) - 4)).substr($v, -4);
    }
}
