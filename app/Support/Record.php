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

    /**
     * Kate's evidence model: where a claim came from (source) is separate from whether it has been checked (status).
     * "Horsefax Verified" is the top verification tier; it covers one fact, never a whole horse.
     */
    public const SOURCES = [
        'owner_reported' => 'Owner reported',
        'professional_attested' => 'Professional attested',
        'official_source' => 'Official source',
        'historical' => 'Historical / third-party',
    ];

    public const SOURCE_DESCRIPTIONS = [
        'owner_reported' => 'Entered by the owner or their authorized representative.',
        'professional_attested' => 'Submitted by a veterinarian, farrier, trainer or breeder in their own name. It authenticates who said it, not the horse\'s overall soundness.',
        'official_source' => 'Supplied or confirmed by a breed registry, competition association or other authoritative organization.',
        'historical' => 'From legacy records, other people\'s pedigrees or third-party submissions whose reliability hasn\'t been established.',
    ];

    public const STATUS = [
        'unverified' => 'Not verified',
        'verified' => 'Horsefax Verified',
        'disputed' => 'Disputed',
        'superseded' => 'Superseded',
    ];

    public const VERIFICATION_METHODS = [
        'registry_record' => 'Matched against the registry\'s record',
        'document_review' => 'Original document reviewed and authenticated',
        'microchip_scan' => 'Microchip scanned and matched',
        'physical_inspection' => 'Physical inspection of the horse',
        'professional_confirmation' => 'Confirmed with the professional who supplied it',
        'association_results' => 'Matched against the association\'s official results',
        'transaction_record' => 'Matched against a transaction record',
        'other' => 'Other documented check',
    ];

    /** Suggested wording for what a verification covers (staff can edit it). Always says what it does NOT cover. */
    public static function defaultScope(\Illuminate\Database\Eloquent\Model $s): string
    {
        return match (class_basename($s)) {
            'Horse' => "This horse's identity (registration, microchip and markings) confirmed by Horsefax. Does not establish ownership, soundness or suitability.",
            'HorseIdentifier' => match ($s->kind) {
                'registration' => 'Registered name and registration number matched against the '.($s->registry ?: 'registry').' record. Does not establish current ownership or physical identity.',
                'microchip' => 'Microchip scanned and matched to this horse. Does not establish ownership.',
                default => 'This identifier matched against its issuing source. Covers this identifier only.',
            },
            'HorseEvent' => match ($s->category) {
                'health', 'care' => 'This entry matched against the issuing veterinarian\'s or laboratory\'s record. Covers this entry only, not the horse\'s current health.',
                'competition' => 'This result matched against the association\'s official results. Covers this result only.',
                'ownership' => 'This transaction matched against a transaction record. Private terms are not shown.',
                default => 'This entry checked against supporting evidence. Covers this entry only.',
            },
            'Document' => 'This document authenticated with its issuer. Covers the document only, not anything it does not state.',
            'PedigreeEntry' => 'This pedigree line matched against the registry\'s record.',
            'HorseFact' => 'This '.strtolower(\App\Models\HorseFact::FIELDS[$s->field] ?? 'value').' confirmed by Horsefax. Covers this value only.',
            default => 'Covers this item only.',
        };
    }

    /** Who supplied a claim, from the role of the person adding it. */
    public static function sourceForRole(?string $role): string
    {
        return match ($role) {
            'veterinarian', 'farrier', 'trainer', 'breeder' => 'professional_attested',
            'staff' => 'historical',
            default => 'owner_reported',
        };
    }

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
        'training' => ['program' => 'Training program (dates, trainer, facility)', 'skill' => 'Documented skill, title or qualification'],
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
        'MLOSZ' => 'Hungarian stud book (MLOSZ)',
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
        'ueln' => 'UELN (Universal Equine Life Number)',
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

    /**
     * Factual record policy (Richard and Kate, Oct 19 2026): Horsefax documents what can be established, not opinions.
     * These types are no longer offered for new entries; entries made earlier keep their label and history.
     */
    public const RETIRED_EVENT_TYPES = [
        'training' => ['evaluation' => 'Evaluation / assessment', 'temperament' => 'Temperament observation'],
    ];

    public static function eventTypeLabel(?string $category, ?string $type): ?string
    {
        return $type ? (self::EVENT_TYPES[$category][$type] ?? self::RETIRED_EVENT_TYPES[$category][$type] ?? ucfirst(str_replace('_', ' ', $type))) : null;
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

    /**
     * A stand-in printed where a horse has no name yet ("Pending", "AQHA Pending", "Name pending", "Unnamed", "Foal"),
     * not a name: never matched, paired or made into a record by it. Also the unnamed-foal labels sale catalogs print as
     * "Pending/<dam>", "Pending A/<dam>", "PendingB/<dam>", "Pending (P)/<dam>", "Pending - <dam>", "AQHA Name Pending/<dam>"
     * or "Pending 3" (the name after the label is the dam's, not the foal's). A name followed by "(pending)" is a name.
     */
    public static function placeholderName(?string $name): bool
    {
        return (bool) preg_match('/^\s*(horse name:\s*)?((aqha|apha|reg(istration|istered)?|name)\s+)*(pending( registration)?|unnamed( (colt|filly|foal))?|foal|no name|none|n\/?a|tba|tbd)\s*$/i', (string) $name)
            || (bool) preg_match('/^\s*((aqha|apha|reg(istration|istered)?|name)\s+)*pending\s*([a-z]{1,2}|\d{1,2})?(\s*\([^)]*\))?\s*([\/:\-–—]|$)/iu', (string) $name);
    }
}
