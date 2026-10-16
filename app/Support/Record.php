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

    public const PEDIGREE_POSITIONS = [
        's' => 'Sire', 'd' => 'Dam',
        'ss' => "Sire's sire", 'sd' => "Sire's dam", 'ds' => "Dam's sire", 'dd' => "Dam's dam",
        'sss' => 'sss', 'ssd' => 'ssd', 'sds' => 'sds', 'sdd' => 'sdd', 'dss' => 'dss', 'dsd' => 'dsd', 'dds' => 'dds', 'ddd' => 'ddd',
    ];

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
