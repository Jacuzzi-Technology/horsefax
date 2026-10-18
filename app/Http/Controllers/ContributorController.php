<?php

namespace App\Http\Controllers;

use App\Actions\Record\Contributors;
use App\Actions\Record\RecordEvent;
use App\Actions\Record\StoreDocument;
use App\Models\Contributor;
use App\Models\Horse;
use App\Support\Record;
use App\Support\Views;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Professional contributors. Stewards invite and remove them; the professional accepts from their dashboard and adds
 * entries and documents from the contribute page. A professional's entries arrive private: the owner decides who sees
 * them, but cannot edit or supersede them (disagreements go to staff as a dispute).
 */
class ContributorController extends Controller
{
    public function invite(Request $request, Horse $horse, Contributors $contributors)
    {
        abort_unless($horse->canBeEditedBy($request->user()), 403);
        $data = $request->validate([
            'email' => 'required|email|max:200',
            'role' => ['required', Rule::in(array_keys(Record::CONTRIBUTOR_ROLES))],
        ]);
        $contributors->invite($horse, $request->user(), $data['email'], $data['role']);

        return redirect()->route('horses.show', ['horse' => $horse, 'tab' => 'contributors'])
            ->with('status', 'Invitation sent. When '.$data['email'].' signs in to Horsefax with that address, they can accept it from their dashboard.');
    }

    public function revoke(Request $request, Horse $horse, Contributor $contributor, Contributors $contributors)
    {
        abort_unless($horse->canBeEditedBy($request->user()), 403);
        abort_unless($contributor->horse_id === $horse->id && in_array($contributor->status, ['pending', 'active'], true), 404);
        $was = $contributor->status;
        $contributors->revoke($contributor, $request->user());

        return redirect()->route('horses.show', ['horse' => $horse, 'tab' => 'contributors'])
            ->with('status', $was === 'pending' ? 'Invitation cancelled.' : 'Access removed. Entries they already added stay on the record.');
    }

    private function forMe(Request $request, Contributor $contributor): void
    {
        abort_unless($contributor->status === 'pending' && strcasecmp($contributor->email, $request->user()->email) === 0, 404);
    }

    public function accept(Request $request, Contributor $contributor, Contributors $contributors)
    {
        $this->forMe($request, $contributor);
        $contributors->accept($contributor, $request->user());

        return redirect()->route('contribute', $contributor->horse)->with('status', 'You can now add entries to this record as its '.strtolower($contributor->roleLabel()).'.');
    }

    public function decline(Request $request, Contributor $contributor, Contributors $contributors)
    {
        $this->forMe($request, $contributor);
        $contributors->decline($contributor, $request->user());

        return back()->with('status', 'Invitation declined.');
    }

    /** The professional's view of the record: identity basics plus what they themselves added. */
    public function show(Request $request, Horse $horse)
    {
        abort_unless($horse->canBeContributedToBy($request->user()), 403);
        $contributor = $horse->activeContributor($request->user());

        return view('horses.contribute', [
            'horse' => $horse,
            'contributor' => $contributor,
            'events' => Views::contributedEvents($horse, $request->user()),
            'documents' => Views::contributedDocuments($horse, $request->user()),
        ]);
    }

    public function addEvent(Request $request, Horse $horse, RecordEvent $record, StoreDocument $store)
    {
        abort_unless($horse->canBeContributedToBy($request->user()), 403);
        $rules = RecordController::eventRules();
        unset($rules['visibility'], $rules['amount'], $rules['amount_visibility'], $rules['sale_venue']); // prices are the owner's business
        $data = $request->validate($rules);
        $data['visibility'] = 'private';
        $role = $horse->activeContributor($request->user())->role;

        if ($request->hasFile('document')) {
            $data['document_id'] = $store($horse, $request->user(), $request->file('document'), [
                'kind' => $data['document_kind'] ?? 'other', 'title' => $data['title'], 'issued_on' => $data['occurred_on'] ?? null,
                'visibility' => 'private', 'contributor_role' => $role,
            ])->id;
        }
        unset($data['document'], $data['document_kind']);
        $record($horse, $request->user(), $data);

        return redirect()->route('contribute', $horse)->with('status', 'Entry added. The owner decides who else can see it.');
    }

    public function addDocument(Request $request, Horse $horse, StoreDocument $store)
    {
        abort_unless($horse->canBeContributedToBy($request->user()), 403);
        $data = $request->validate([
            'file' => 'required|file|mimes:'.StoreDocument::MIMES.'|max:'.StoreDocument::MAX_KB,
            'kind' => ['required', Rule::in(array_keys(Record::DOCUMENT_KINDS))],
            'title' => 'nullable|string|max:160',
            'issuer' => 'nullable|string|max:160',
            'issued_on' => 'nullable|date|before_or_equal:today',
            'expires_on' => 'nullable|date|after_or_equal:issued_on',
        ]);
        $store($horse, $request->user(), $request->file('file'), $data + [
            'visibility' => 'private', 'contributor_role' => $horse->activeContributor($request->user())->role,
        ]);

        return redirect()->route('contribute', $horse)->with('status', 'Document uploaded. The owner decides whether it is listed in reports.');
    }
}
