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
            'events' => $horse->events()->orderByDesc('created_at')->get(),
            'documents' => $horse->documents()->orderByDesc('created_at')->get(),
            'stewardships' => $horse->stewardships()->with('user')->orderBy('created_at')->get(),
            'audit' => DB::table('audit_log')->where('horse_id', $horse->id)->leftJoin('users', 'users.id', '=', 'audit_log.actor_id')
                ->orderByDesc('audit_log.id')->limit(200)->get(['audit_log.*', 'users.name as actor_name']),
        ]);
    }

    /** Staff set the verification status of one fact or document (e.g. independently verified). */
    public function setStatus(Request $request, string $type, string $id)
    {
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(Record::STATUS))], 'note' => 'required|string|max:1000']);
        $model = match ($type) {
            'identifier' => HorseIdentifier::findOrFail($id),
            'event' => HorseEvent::findOrFail($id),
            'document' => Document::findOrFail($id),
            default => abort(404),
        };
        $before = $model->status;
        $model->forceFill(['status' => $data['status']])->save();
        Audit::record($type.'.status_set', $model, ['status' => $before], ['status' => $data['status'], 'note' => $data['note']]);

        return back()->with('status', 'Status updated.');
    }

    /** Staff confirm (or withdraw) a horse's identity after cross-checking papers, registry and microchip. */
    public function setIdentity(Request $request, Horse $horse)
    {
        $data = $request->validate(['identity_status' => 'required|in:unverified,under_review,verified', 'note' => 'required|string|max:1000']);
        $before = $horse->identity_status;
        $horse->forceFill(['identity_status' => $data['identity_status']])->save();
        Audit::record('horse.identity_status_set', $horse, ['identity_status' => $before], $data);

        return back()->with('status', 'Identity status updated.');
    }

    public function messages()
    {
        $messages = ContactMessage::orderByDesc('created_at')->paginate(50);
        ContactMessage::where('status', 'new')->update(['status' => 'read']);

        return view('admin.messages', ['messages' => $messages]);
    }
}
