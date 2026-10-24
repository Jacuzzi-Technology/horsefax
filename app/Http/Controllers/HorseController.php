<?php

namespace App\Http\Controllers;

use App\Actions\Record\CreateHorse;
use App\Actions\Record\StoreDocument;
use App\Models\Horse;
use App\Models\HorseIdentifier;
use App\Models\ReviewItem;
use App\Models\Transfer;
use App\Support\Audit;
use App\Support\Notify;
use App\Support\Record;
use App\Support\Views;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class HorseController extends Controller
{
    public static function factRules(): array
    {
        return [
            'barn_name' => 'nullable|string|max:120',
            'registered_name' => 'nullable|string|max:160',
            'breed' => 'nullable|string|max:80',
            'sex' => ['nullable', Rule::in(array_keys(Record::SEXES))],
            'birth_precision' => 'required|in:day,year,unknown',
            'birth_date' => 'nullable|required_if:birth_precision,day|date|before_or_equal:today|after:1970-01-01',
            'birth_year' => 'nullable|required_if:birth_precision,year|integer|min:1970|max:'.now()->year,
            'color' => 'nullable|string|max:60',
            'markings' => 'nullable|string|max:1000',
            'height_hands' => 'nullable|numeric|min:6|max:20',
            'height_measured_on' => 'nullable|date|before_or_equal:today',
        ];
    }

    /** Breed and color come from a list or an "Other" text box. */
    private static function mergeChoices(Request $request): void
    {
        foreach (['breed', 'color'] as $f) {
            if (! $request->has($f.'_choice')) {
                continue;
            }
            $choice = (string) $request->input($f.'_choice');
            $request->merge([$f => $choice === '__other' ? trim((string) $request->input($f.'_other')) : $choice]);
        }
    }

    private static function facts(array $data): array
    {
        $facts = collect($data)->only(array_keys(self::factRules()))->all();
        if (($facts['birth_precision'] ?? 'unknown') === 'day' && ! empty($facts['birth_date'])) {
            $facts['birth_year'] = (int) substr($facts['birth_date'], 0, 4);
        } elseif (($facts['birth_precision'] ?? '') !== 'day') {
            $facts['birth_date'] = null;
            if (($facts['birth_precision'] ?? '') === 'unknown') {
                $facts['birth_year'] = null;
            }
        }

        return $facts;
    }

    public function create(Request $request)
    {
        // Prefill from the search the person just ran.
        $prefill = collect($request->query())->only(['registered_name', 'barn_name', 'microchip', 'birth_year', 'sex', 'color', 'breed'])
            ->map(fn ($v) => is_string($v) ? mb_substr($v, 0, 120) : null)->filter()->all();
        if ($request->filled('registration_number') || $request->filled('registry')) {
            $prefill['registrations'] = [['registry' => mb_substr((string) $request->query('registry'), 0, 80), 'number' => mb_substr((string) $request->query('registration_number'), 0, 60)]];
        }
        if (! empty($prefill['birth_year'])) {
            $prefill['birth_precision'] = 'year';
        }

        return view('horses.create', ['old' => $prefill]);
    }

    /**
     * Two steps: first look for this horse already on Horsefax (same registration/microchip number, or same
     * registered name); if anything matches, show it and ask before creating a new record.
     */
    public function store(Request $request, CreateHorse $create)
    {
        self::mergeChoices($request);
        $data = $request->validate(self::factRules() + [
            'registrations' => 'nullable|array|max:10',
            'registrations.*.registry' => 'nullable|string|max:80',
            'registrations.*.number' => 'nullable|required_with:registrations.*.registry|string|max:60',
            'microchip' => 'nullable|string|max:40',
            'confirm_new' => 'nullable|boolean',
            'attest' => 'accepted',
        ], ['attest.accepted' => 'Please confirm the information is true to the best of your knowledge.']);

        if (empty($data['registered_name']) && empty($data['barn_name'])) {
            return back()->withInput()->withErrors(['barn_name' => 'Give the horse a barn name or registered name.']);
        }

        if (! $request->boolean('confirm_new')) {
            $matches = $this->possibleMatches($data);
            if ($matches->isNotEmpty()) {
                return view('horses.create', ['matches' => $matches, 'old' => $data]);
            }
        }

        $identifiers = [];
        foreach (self::registrations($data) as $r) {
            $identifiers[] = ['kind' => 'registration', 'registry' => $r['registry'], 'value' => $r['number']];
        }
        if (! empty($data['microchip'])) {
            $identifiers[] = ['kind' => 'microchip', 'value' => $data['microchip']];
        }
        $horse = $create($request->user(), self::facts($data), $identifiers);

        $message = 'Record created. Horsefax ID '.$horse->horsefax_id.'.';
        if ($horse->identity_status === 'under_review') {
            $message .= ' One of its numbers is already on another record, so Horsefax staff will review it.';
        }

        return redirect()->route('horses.show', $horse)->with('status', $message);
    }

    /** The registrations entered on the form, blanks dropped, each registry normalised to its short code if known. */
    private static function registrations(array $data): array
    {
        $out = [];
        foreach ($data['registrations'] ?? [] as $r) {
            $number = trim((string) ($r['number'] ?? ''));
            if ($number === '') {
                continue;
            }
            $registry = trim((string) ($r['registry'] ?? '')) ?: null;
            if ($registry) {
                foreach (Record::REGISTRIES as $code => $label) {
                    if (strcasecmp($registry, $code) === 0 || strcasecmp($registry, $label) === 0) {
                        $registry = $code;
                        break;
                    }
                }
            }
            $out[] = ['registry' => $registry, 'number' => $number];
        }

        return $out;
    }

    /** Only minimal identity is shown for a match: ID, name, breed, foaling year. */
    private function possibleMatches(array $data)
    {
        $ids = collect();
        $pairs = array_map(fn ($r) => ['registration', $r['number']], self::registrations($data));
        $pairs[] = ['microchip', $data['microchip'] ?? null];
        foreach ($pairs as [$kind, $value]) {
            if ($value && strlen(Record::normalizeIdentifier($value)) >= 3) {
                $ids = $ids->merge(HorseIdentifier::where('kind', $kind)->where('value_normalized', Record::normalizeIdentifier($value))
                    ->whereNull('superseded_by')->pluck('horse_id'));
            }
        }
        if (! empty($data['registered_name'])) {
            $ids = $ids->merge(Horse::named($data['registered_name'])->pluck('id'));
        }

        return Horse::whereIn('id', $ids->unique())->limit(10)->get();
    }

    public function show(Request $request, Horse $horse)
    {
        if (! $horse->canBeViewedPrivatelyBy($request->user())) {
            return $horse->activeContributor($request->user())
                ? redirect()->route('contribute', $horse)
                : redirect()->route('horses.claim', $horse);
        }

        return view('horses.show', [
            'horse' => $horse,
            'canEdit' => $horse->canBeEditedBy($request->user()),
            'identifiers' => Views::identifiers($horse, 'steward'),
            'events' => Views::events($horse, 'steward'),
            'documents' => Views::documents($horse, 'steward'),
            'names' => Views::names($horse, 'steward'),
            'stewardships' => $horse->stewardships()->with('user')->orderBy('created_at')->get(),
            'pendingTransfer' => Transfer::where('horse_id', $horse->id)->where('status', 'pending')->first(),
            'openReviews' => ReviewItem::where('horse_id', $horse->id)->where('status', 'open')->get(),
            'superseded' => $horse->events()->whereNotNull('superseded_by')->count(),
            'pedigree' => Views::pedigree($horse, 'steward'),
            'offspring' => Views::offspring($horse, 'steward'),
            'media' => Views::media($horse, 'steward'),
            'facts' => Views::facts($horse),
            'resultSuggestions' => $horse->registered_name ? \App\Models\ShowRecord::whereNull('horse_id')->where('name_key', \App\Models\ShowRecord::key($horse->registered_name))->get() : collect(),
            'factHistory' => $horse->facts()->where(fn ($q) => $q->where('is_current', false)->orWhereNotNull('superseded_by'))->orderByDesc('created_at')->get()->groupBy('field'),
            'contributors' => $horse->contributors()->with('user')->orderByRaw("case status when 'active' then 0 when 'pending' then 1 else 2 end")->orderByDesc('created_at')->get(),
        ]);
    }

    public function edit(Request $request, Horse $horse)
    {
        abort_unless($horse->canBeEditedBy($request->user()), 403);

        return view('horses.edit', ['horse' => $horse]);
    }

    public function update(Request $request, Horse $horse)
    {
        abort_unless($horse->canBeEditedBy($request->user()), 403);
        self::mergeChoices($request);
        $data = $request->validate(self::factRules());
        if (empty($data['registered_name']) && empty($data['barn_name'])) {
            return back()->withInput()->withErrors(['barn_name' => 'Give the horse a barn name or registered name.']);
        }
        $facts = self::facts($data);
        $oldName = $horse->registered_name;
        $role = \App\Actions\Record\RecordEvent::roleOf($horse, $request->user());
        $held = app(\App\Actions\Record\RecordFacts::class)($horse, $request->user(), \App\Actions\Record\RecordFacts::fromColumns($facts), \App\Support\Record::sourceForRole($role), $role);
        // Values held for review don't change the horse yet.
        $columnsFor = ['birth' => ['birth_precision', 'birth_date', 'birth_year'], 'height' => ['height_hands', 'height_measured_on']];
        foreach ($held as $field) {
            foreach ($columnsFor[$field] ?? [$field] as $col) {
                unset($facts[$col]);
            }
        }
        $horse->fill($facts)->save();
        Audit::changes('horse.identity_updated', $horse);
        $renamedAfterShows = $oldName && $horse->registered_name && \App\Models\ShowRecord::key($oldName) !== \App\Models\ShowRecord::key($horse->registered_name)
            && ($shown = \App\Actions\Record\ShowNameRule::shownAs($horse, $oldName));
        if ($renamedAfterShows) {
            \App\Actions\Record\ShowNameRule::flagRename($horse, $oldName, $horse->registered_name, $shown, $request->user());
        }

        $msg = 'Identity details saved. Each change is kept in the record history.';
        if ($held) {
            $labels = collect($held)->map(fn ($f) => strtolower(\App\Models\HorseFact::FIELDS[$f] ?? $f))->join(', ');
            $msg .= ' Your change to '.$labels.' differs from a verified or professionally supplied value, so it has gone to Horsefax staff for review.';
        }
        if ($renamedAfterShows) {
            $msg .= ' This horse has show results under its old name, and AQHA doesn\'t allow a name change after a horse has shown, so Horsefax staff will check the new name.';
        }

        return redirect()->route('horses.show', $horse)->with('status', $msg);
    }

    public function audit(Request $request, Horse $horse)
    {
        abort_unless($horse->canBeViewedPrivatelyBy($request->user()), 403);
        $entries = DB::table('audit_log')->where('horse_id', $horse->id)->leftJoin('users', 'users.id', '=', 'audit_log.actor_id')
            ->orderByDesc('audit_log.id')->select('audit_log.*', 'users.name as actor_name')->paginate(50);
        (new \App\Support\AuditMask)->rows($entries->items());

        return view('horses.audit', ['horse' => $horse, 'entries' => $entries]);
    }

    public function claimForm(Request $request, Horse $horse)
    {
        if ($horse->isStewardedBy($request->user())) {
            return redirect()->route('horses.show', $horse);
        }
        $pending = ReviewItem::where('kind', 'ownership_claim')->where('horse_id', $horse->id)->where('submitted_by', $request->user()->id)->where('status', 'open')->exists();

        return view('horses.claim', ['horse' => $horse, 'pending' => $pending]);
    }

    /** A claim never displaces anyone by itself: staff review the private evidence first. */
    public function claim(Request $request, Horse $horse, StoreDocument $store)
    {
        abort_if($horse->isStewardedBy($request->user()), 403);
        $data = $request->validate([
            'statement' => 'required|string|min:20|max:5000',
            'evidence' => 'required|array|min:1|max:5',
            'evidence.*' => 'file|mimes:'.StoreDocument::MIMES.'|max:'.StoreDocument::MAX_KB,
            'registration_number' => 'nullable|string|max:40',
            'seller_name' => 'nullable|required_with:seller_email|string|max:160',
            'seller_email' => 'nullable|email|max:190',
        ], ['evidence.required' => 'Attach at least one document that shows ownership (bill of sale, transfer, registration papers in your name).']);

        $docIds = [];
        foreach ($request->file('evidence') as $file) {
            $docIds[] = $store($horse, $request->user(), $file, ['kind' => 'bill_of_sale', 'title' => 'Ownership claim evidence', 'visibility' => 'private'])->id;
        }
        // The registration number on the claimant's papers, checked against the record's own (staff see the result).
        $reg = trim((string) ($data['registration_number'] ?? ''));
        $regMatch = $reg === '' ? null : $horse->identifiers()->where('kind', 'registration')->whereNull('superseded_by')
            ->where('value_normalized', \App\Support\Record::normalizeIdentifier($reg))->exists();
        $item = ReviewItem::create([
            'kind' => 'ownership_claim', 'horse_id' => $horse->id, 'submitted_by' => $request->user()->id,
            'statement' => $data['statement'], 'details' => ['document_ids' => $docIds, 'registration_number' => $reg ?: null,
                'registration_match' => $regMatch, 'record_has_registration' => $horse->identifiers()->where('kind', 'registration')->whereNull('superseded_by')->exists()],
        ]);
        Audit::record('claim.submitted', $item, null, ['documents' => count($docIds)], $horse->id);
        // The seller, when named, is asked to confirm the sale.
        if (! empty($data['seller_email'])) {
            app(\App\Actions\Claims\AskSellerToConfirm::class)($item, $data['seller_name'], $data['seller_email']);
        }
        Notify::staff('Horsefax ownership claim for '.$horse->horsefax_id, $data['statement']);

        return redirect()->route('dashboard')->with('status', 'Your claim for '.$horse->shortName().' is with Horsefax staff. The current record is unchanged until it has been reviewed.');
    }
}
