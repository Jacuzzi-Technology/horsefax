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

        return view('admin.review', [
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
        $data = $request->validate(['note' => 'required|string|max:2000', 'end_current_owner' => 'nullable|boolean', 'outcome' => 'nullable|in:both_stand,new_is_wrong']);
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
        $horses = Horse::query()
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.$q.'%';
                $norm = Record::normalizeIdentifier($q);
                $query->where(fn ($w) => $w->where('registered_name', 'ilike', $like)->orWhere('barn_name', 'ilike', $like)->orWhere('horsefax_id', 'ilike', $like)
                    ->orWhereHas('identifiers', fn ($i) => $i->where('value_normalized', $norm)));
            })
            ->orderByDesc('created_at')->paginate(50)->withQueryString();

        return view('admin.horses', ['horses' => $horses, 'q' => $q]);
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
            'audit' => DB::table('audit_log')->where('horse_id', $horse->id)->leftJoin('users', 'users.id', '=', 'audit_log.actor_id')
                ->orderByDesc('audit_log.id')->limit(200)->get(['audit_log.*', 'users.name as actor_name']),
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
