<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Record\Reviews;
use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\Document;
use App\Models\Horse;
use App\Models\HorseEvent;
use App\Models\HorseIdentifier;
use App\Models\ReviewItem;
use App\Support\Audit;
use App\Support\Record;
use App\Support\Views;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function queue(Request $request)
    {
        $status = $request->query('status', 'open');

        return view('admin.queue', [
            'items' => ReviewItem::where('status', $status)->with(['horse', 'submitter'])->orderBy('created_at', $status === 'open' ? 'asc' : 'desc')->paginate(50),
            'status' => $status,
            'counts' => ReviewItem::where('status', 'open')->selectRaw('kind, count(*) as n')->groupBy('kind')->pluck('n', 'kind'),
            'newMessages' => ContactMessage::where('status', 'new')->count(),
        ]);
    }

    public function review(ReviewItem $item)
    {
        $documents = Document::whereIn('id', $item->details['document_ids'] ?? [])->get();
        $subject = match ($item->subject_type) {
            'HorseEvent' => HorseEvent::find($item->subject_id),
            'HorseIdentifier' => HorseIdentifier::find($item->subject_id),
            'HorseFact' => \App\Models\HorseFact::find($item->subject_id),
            'ShowRecord' => \App\Models\ShowRecord::find($item->subject_id),
            default => null,
        };
        $others = HorseIdentifier::whereIn('id', $item->details['other_identifier_ids'] ?? [])->with('horse')->get();

        // Claim evidence at a glance: registration-number match, seller confirmation, the claimant's identity check,
        // the claim code behind it, and any evidence file already used in another claim.
        $claim = $item->kind === 'ownership_claim' ? [
            'confirmations' => \App\Models\ClaimConfirmation::where('review_item_id', $item->id)->get(),
            'code' => isset($item->details['claim_code_id']) ? \App\Models\ClaimCode::find($item->details['claim_code_id']) : null,
            'reused' => $documents->isEmpty() ? collect() : Document::whereIn('sha256', $documents->pluck('sha256'))->whereNotIn('id', $documents->pluck('id'))->with('horse')->get(),
        ] : null;
        $identity = $item->kind === 'identity_check' ? \App\Models\IdentityCheck::find($item->subject_id) : null;

        return view('admin.review', [
            'claim' => $claim, 'identity' => $identity,
            'item' => $item->load(['horse', 'submitter', 'resolver']),
            'documents' => $documents,
            'subject' => $subject,
            'others' => $others,
            'owners' => $item->horse ? $item->horse->activeStewards()->with('user')->get() : collect(),
        ]);
    }

    public function approve(Request $request, ReviewItem $item, Reviews $reviews)
    {
        abort_unless($item->status === 'open', 409);
        $data = $request->validate(['note' => 'required|string|max:2000', 'end_current_owner' => 'nullable|boolean', 'outcome' => 'nullable|in:both_stand,new_is_wrong', 'method' => 'nullable|in:id_document,video_call,third_party']);
        $reviews->approve($item, $request->user(), $data['note'], $data);

        return redirect()->route('admin.queue')->with('status', 'Approved and recorded.');
    }

    public function reject(Request $request, ReviewItem $item, Reviews $reviews)
    {
        abort_unless($item->status === 'open', 409);
        $data = $request->validate(['note' => 'required|string|max:2000']);
        $reviews->reject($item, $request->user(), $data['note']);

        return redirect()->route('admin.queue')->with('status', 'Rejected and recorded.');
    }

    public function horses(Request $request)
    {
        $q = trim((string) $request->query('q'));
        $f = collect(['registry', 'breed', 'sex', 'color', 'source'])->mapWithKeys(fn ($k) => [$k => trim((string) $request->query($k)) ?: null])->all();
        $horses = Horse::query()
            ->when($q !== '', function ($query) use ($q) {
                // Indexed lookups unioned (an OR across them would read all 4M+ records).
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], mb_strtolower($q)).'%';
                $ids = Horse::query()->select('id')->whereRaw('lower(registered_name) like ?', [$like])
                    ->union(Horse::query()->select('id')->whereRaw('lower(barn_name) like ?', [$like]))
                    ->union(Horse::query()->select('id')->where('horsefax_id', strtoupper(trim($q))))
                    ->union(\App\Models\HorseIdentifier::query()->select('horse_id as id')->where('value_normalized', Record::normalizeIdentifier($q)));
                $query->whereIn('id', \Illuminate\Support\Facades\DB::query()->fromSub($ids, 'm')->select('id'));
            })
            ->when($f['registry'], fn ($w, $r) => $w->whereIn('id', \App\Models\HorseIdentifier::select('horse_id')->where('kind', 'registration')->whereNull('superseded_by')->where('registry', $r)))
            ->when($f['breed'], fn ($w, $v) => $v === '(none)' ? $w->whereNull('breed') : $w->where('breed', $v))
            ->when($f['sex'], fn ($w, $v) => $v === '(none)' ? $w->whereNull('sex') : $w->where('sex', $v))
            ->when($f['color'], fn ($w, $v) => $v === '(none)' ? $w->whereNull('color') : $w->where('color', $v))
            ->when($f['source'], fn ($w, $v) => $v === 'not_ifce' ? $w->where(fn ($x) => $x->whereNull('source')->orWhere('source', '!=', 'ifce')) : $w->where('source', $v))
            ->orderByDesc('created_at')->paginate(50)->withQueryString();

        // Filter choices with how many records have each, refreshed hourly (counting 4M+ records on every visit is slow).
        $options = \Illuminate\Support\Facades\Cache::remember('admin.horse-filter-options', 3600, fn () => [
            'registry' => \App\Models\HorseIdentifier::where('kind', 'registration')->whereNull('superseded_by')->whereNotNull('registry')
                ->selectRaw('registry v, count(distinct horse_id) n')->groupBy('registry')->orderByDesc('n')->pluck('n', 'v')->all(),
            'breed' => Horse::selectRaw("coalesce(breed, '(none)') v, count(*) n")->groupBy('v')->orderByDesc('n')->pluck('n', 'v')->all(),
            'sex' => Horse::selectRaw("coalesce(sex, '(none)') v, count(*) n")->groupBy('v')->orderByDesc('n')->pluck('n', 'v')->all(),
            'color' => Horse::selectRaw("coalesce(color, '(none)') v, count(*) n")->groupBy('v')->orderByDesc('n')->pluck('n', 'v')->all(),
            'source' => Horse::selectRaw("coalesce(source, '(none)') v, count(*) n")->groupBy('v')->orderByDesc('n')->pluck('n', 'v')->all(),
        ]);

        return view('admin.horses', ['horses' => $horses, 'q' => $q, 'f' => $f, 'options' => $options]);
    }

    public function horse(Horse $horse)
    {
        return view('admin.horse', [
            'horse' => $horse,
            'identifiers' => $horse->identifiers()->orderBy('created_at')->get(),
            'facts' => $horse->facts()->orderBy('field')->orderByDesc('created_at')->get(),
            'pedigreeLines' => $horse->pedigree()->orderByRaw('length(position)')->orderBy('position')->get(),
            'contributors' => \App\Models\Contributor::where('horse_id', $horse->id)->with('user')->get(),
            'identityVerification' => $horse->identityVerification(),
            'events' => $horse->events()->orderByDesc('created_at')->get(),
            'documents' => $horse->documents()->orderByDesc('created_at')->get(),
            'stewardships' => $horse->stewardships()->with('user')->orderBy('created_at')->get(),
            'audit' => (new \App\Support\AuditMask)->rows(DB::table('audit_log')->where('horse_id', $horse->id)->leftJoin('users', 'users.id', '=', 'audit_log.actor_id')
                ->orderByDesc('audit_log.id')->limit(200)->get(['audit_log.*', 'users.name as actor_name'])),
        ]);
    }

    private function subject(string $type, string $id): \Illuminate\Database\Eloquent\Model
    {
        return match ($type) {
            'identifier' => HorseIdentifier::findOrFail($id),
            'event' => HorseEvent::findOrFail($id),
            'document' => Document::findOrFail($id),
            'pedigree' => \App\Models\PedigreeEntry::findOrFail($id),
            'fact' => \App\Models\HorseFact::findOrFail($id),
            'horse' => Horse::findOrFail($id),
            default => abort(404),
        };
    }

    /** Horsefax Verified: record how one item was checked and exactly what the verification covers. */
    public function verify(Request $request, string $type, string $id, \App\Actions\Record\Verify $verify)
    {
        $data = $request->validate([
            'method' => ['required', Rule::in(array_keys(Record::VERIFICATION_METHODS))],
            'scope' => 'required|string|min:10|max:1000',
            'recheck_on' => 'nullable|date|after:today',
            'note' => 'nullable|string|max:2000',
            'source_type' => ['nullable', Rule::in(array_keys(Record::SOURCES))],
        ]);
        $subject = $this->subject($type, $id);
        if (! empty($data['source_type']) && $type !== 'horse' && $subject->source_type !== $data['source_type']) {
            $before = $subject->source_type;
            $subject->forceFill(['source_type' => $data['source_type']])->save();
            Audit::record($type.'.source_set', $subject, ['source_type' => $before], ['source_type' => $data['source_type']]);
        }
        $verify->verify($subject, $request->user(), $data);

        return back()->with('status', 'Marked Horsefax Verified.');
    }

    public function withdraw(Request $request, string $type, string $id, \App\Actions\Record\Verify $verify)
    {
        $data = $request->validate(['reason' => 'required|string|max:1000', 'status' => 'required|in:unverified,disputed']);
        $verify->withdraw($this->subject($type, $id), $request->user(), $data['reason'], $data['status']);

        return back()->with('status', 'Verification withdrawn.');
    }

    /** A professional's credentials, checked by staff (license, membership). Shown as a ✓ on their attested entries. */
    public function credentials(Request $request, \App\Models\User $user)
    {
        $data = $request->validate([
            'credential_role' => ['required', Rule::in(array_keys(Record::CONTRIBUTOR_ROLES))],
            'credential_detail' => 'required|string|max:200',
            'verified' => 'required|boolean',
        ]);
        $before = $user->only(['credential_role', 'credential_detail', 'credential_verified_at']);
        $user->forceFill([
            'credential_role' => $data['credential_role'], 'credential_detail' => $data['credential_detail'],
            'credential_verified_at' => $data['verified'] ? now() : null,
        ])->save();
        Audit::record('user.credentials_set', $user, $before, $user->only(['credential_role', 'credential_detail', 'credential_verified_at']));

        return back()->with('status', $data['verified'] ? 'Credentials verified.' : 'Credentials saved as not verified.');
    }

    /** The registered-name index: search, see what's harvested, attach a name's results to a horse. */
    public function showRecords(Request $request)
    {
        $q = trim((string) $request->query('q'));
        $records = \App\Models\ShowRecord::query()
            ->when($q !== '', fn ($w) => $w->where('name_key', 'like', '%'.\App\Models\ShowRecord::key($q).'%'))
            ->orderByDesc('appearances')->paginate(50)->withQueryString();

        return view('admin.show-records', [
            'records' => $records, 'q' => $q,
            'stats' => ['names' => \App\Models\ShowRecord::count(), 'results' => \App\Models\ShowAppearance::count(),
                'matched' => \App\Models\ShowRecord::whereNotNull('horse_id')->count(),
                'pages' => \App\Models\HarvestPage::where('status', 'parsed')->count(), 'failed' => \App\Models\HarvestPage::where('status', 'failed')->count()],
            'sources' => \App\Models\DataSource::all(),
        ]);
    }

    public function attachResults(Request $request, \App\Models\ShowRecord $record, \App\Actions\Record\AttachShowResults $attach)
    {
        $data = $request->validate(['horsefax_id' => 'required|string', 'note' => 'required|string|max:1000']);
        $horse = Horse::where('horsefax_id', strtoupper(trim($data['horsefax_id'])))->firstOrFail();
        abort_if($record->horse_id && $record->horse_id !== $horse->id, 409, 'These results are already attached to another horse.');
        if ($former = \App\Actions\Record\ShowNameRule::formerName($record, $horse)) {
            $item = \App\Actions\Record\ShowNameRule::flagFormerName($record, $horse, $former, $request->user());

            return redirect()->route('admin.review', $item)->with('status', '"'.$record->display_name.'" is a previous name of '.$horse->displayName()
                .'. A shown horse can\'t be renamed, so these results were not attached: approve this review only if you have confirmed they are this horse.');
        }
        $n = $attach($record, $horse, $request->user());
        Audit::record('show_results.attach_note', $horse, null, ['note' => $data['note']], $horse->id);

        return back()->with('status', $n.' results attached to '.$horse->displayName().'.');
    }

    public function messages()
    {
        $messages = ContactMessage::orderByDesc('created_at')->paginate(50);
        ContactMessage::where('status', 'new')->update(['status' => 'read']);

        return view('admin.messages', ['messages' => $messages]);
    }
}
