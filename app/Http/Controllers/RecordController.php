<?php

namespace App\Http\Controllers;

use App\Actions\Record\AddIdentifier;
use App\Actions\Record\RecordEvent;
use App\Actions\Record\StoreDocument;
use App\Models\Document;
use App\Models\Horse;
use App\Models\HorseEvent;
use App\Models\HorseIdentifier;
use App\Models\HorseName;
use App\Support\Audit;
use App\Support\Record;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RecordController extends Controller
{
    private function guard(Request $request, Horse $horse): void
    {
        abort_unless($horse->canBeEditedBy($request->user()), 403);
    }

    public function addIdentifier(Request $request, Horse $horse, AddIdentifier $add, StoreDocument $store)
    {
        $this->guard($request, $horse);
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(Record::IDENTIFIER_KINDS))],
            'registry' => 'nullable|string|max:60',
            'value' => 'required|string|max:60',
            'visibility' => ['required', Rule::in(array_keys(Record::VISIBILITY))],
            'proof' => 'nullable|file|mimes:'.StoreDocument::MIMES.'|max:'.StoreDocument::MAX_KB,
        ]);
        if ($request->hasFile('proof')) {
            $doc = $store($horse, $request->user(), $request->file('proof'), ['kind' => $data['kind'] === 'registration' ? 'registration_papers' : 'other', 'title' => 'Proof of '.strtolower(Record::IDENTIFIER_KINDS[$data['kind']]), 'visibility' => 'private']);
            $data['document_id'] = $doc->id;
            $data['status'] = 'document_supplied';
        }
        $identifier = $add($horse, $request->user(), $data);

        return redirect()->route('horses.show', $horse)->with('status', $identifier->in_conflict
            ? 'Saved. That number is also on another Horsefax record, so staff will review both.'
            : 'Identifier added.');
    }

    public function addName(Request $request, Horse $horse)
    {
        $this->guard($request, $horse);
        $data = $request->validate(['name' => 'required|string|max:160', 'kind' => 'required|in:previous,alias', 'visibility' => ['required', Rule::in(array_keys(Record::VISIBILITY))]]);
        $name = HorseName::create($data + ['horse_id' => $horse->id, 'added_by' => $request->user()->id]);
        Audit::record('name.added', $name, null, $data);

        return redirect()->route('horses.show', $horse)->with('status', 'Name added.');
    }

    public static function eventRules(): array
    {
        return [
            'category' => ['required', Rule::in(array_keys(Record::EVENT_CATEGORIES))],
            'type' => 'nullable|string|max:40',
            'title' => 'required|string|max:160',
            'result' => 'nullable|string|max:120',
            'expires_on' => 'nullable|date',
            'organization' => 'nullable|string|max:120',
            'event_name' => 'nullable|string|max:160',
            'class_name' => 'nullable|string|max:160',
            'placing' => 'nullable|string|max:40',
            'score' => 'nullable|string|max:40',
            'points' => 'nullable|numeric|min:0|max:999999',
            'earnings' => 'nullable|numeric|min:0|max:99999999',
            'rider' => 'nullable|string|max:120',
            'discipline' => 'nullable|string|max:80',
            'division' => 'nullable|string|max:80',
            'round' => 'nullable|string|max:40',
            'amount' => 'nullable|numeric|min:0|max:99999999',
            'amount_visibility' => ['nullable', Rule::in(array_keys(Record::VISIBILITY))],
            'sale_venue' => 'nullable|string|max:160',
            'occurred_on' => 'nullable|date|before_or_equal:today',
            'date_precision' => 'required|in:day,month,year,unknown',
            'provider' => 'nullable|string|max:160',
            'details' => 'nullable|string|max:5000',
            'visibility' => ['required', Rule::in(array_keys(Record::VISIBILITY))],
            'document' => 'nullable|file|mimes:'.StoreDocument::MIMES.'|max:'.StoreDocument::MAX_KB,
            'document_kind' => ['nullable', Rule::in(array_keys(Record::DOCUMENT_KINDS))],
        ];
    }

    public function addEvent(Request $request, Horse $horse, RecordEvent $record, StoreDocument $store)
    {
        $this->guard($request, $horse);
        $data = $request->validate($this->eventRules());
        $data = $this->attachDocument($request, $horse, $store, $data);
        $record($horse, $request->user(), $data);

        return redirect()->route('horses.show', ['horse' => $horse, 'tab' => 'history'])->with('status', 'History entry added.');
    }

    public function correctForm(Request $request, Horse $horse, HorseEvent $event)
    {
        $this->guard($request, $horse);
        abort_unless($event->horse_id === $horse->id && ! $event->superseded_by, 404);
        abort_unless($event->contributor_id === $request->user()->id || $request->user()->isStaff(), 403);

        return view('horses.correct', ['horse' => $horse, 'event' => $event]);
    }

    /** Owners correct their own entries by superseding them; entries other people contributed can only be disputed. */
    public function correct(Request $request, Horse $horse, HorseEvent $event, RecordEvent $record, StoreDocument $store)
    {
        $this->guard($request, $horse);
        abort_unless($event->horse_id === $horse->id && ! $event->superseded_by, 404);
        abort_unless($event->contributor_id === $request->user()->id || $request->user()->isStaff(), 403);
        $data = $request->validate($this->eventRules());
        $data = $this->attachDocument($request, $horse, $store, $data);
        if (empty($data['document_id']) && $event->document_id) {
            $data['document_id'] = $event->document_id;
        }
        $record($horse, $request->user(), $data, $event);

        return redirect()->route('horses.show', ['horse' => $horse, 'tab' => 'history'])->with('status', 'Correction saved. The original entry is kept in the record history.');
    }

    private function attachDocument(Request $request, Horse $horse, StoreDocument $store, array $data): array
    {
        if ($request->hasFile('document')) {
            $doc = $store($horse, $request->user(), $request->file('document'), [
                'kind' => $data['document_kind'] ?? 'other', 'title' => $data['title'], 'issued_on' => $data['occurred_on'] ?? null,
                'visibility' => $data['visibility'] === 'public' ? 'shared' : $data['visibility'],
            ]);
            $data['document_id'] = $doc->id;
        }
        unset($data['document'], $data['document_kind']);

        return $data;
    }

    public function savePedigree(Request $request, Horse $horse, \App\Actions\Record\SavePedigree $save)
    {
        $this->guard($request, $horse);
        $data = $request->validate([
            'lines' => 'array',
            'lines.*.name' => 'nullable|string|max:160',
            'lines.*.registry' => 'nullable|string|max:60',
            'lines.*.registration_number' => 'nullable|string|max:60',
            'lines.*.foal_year' => 'nullable|integer|min:1900|max:'.now()->year,
            'lines.*.color' => 'nullable|string|max:60',
            'lines.*.linked_horse_id' => 'nullable|uuid',
            'visibility' => ['required', Rule::in(array_keys(Record::VISIBILITY))],
        ]);
        $lines = array_intersect_key($data['lines'] ?? [], Record::pedigreePositions());
        $n = $save($horse, $request->user(), $lines, $data['visibility']);
        $created = app(\App\Actions\Record\CreateAncestorRecords::class)($horse, $request->user());

        return redirect()->route('horses.show', ['horse' => $horse, 'tab' => 'pedigree'])->with('status', ($n ? 'Pedigree saved.' : 'No pedigree changes.')
            .($created ? ' '.$created.' '.\Illuminate\Support\Str::plural('horse', $created).' in the pedigree '.($created === 1 ? 'was' : 'were').' new to Horsefax and now '.($created === 1 ? 'has its' : 'have their').' own record, marked identity unverified.' : ''));
    }

    public function addMedia(Request $request, Horse $horse, \App\Actions\Record\AddMedia $add)
    {
        $this->guard($request, $horse);
        $data = $request->validate([
            'file' => 'nullable|required_without:url|file|mimes:jpg,jpeg,png,webp,heic,mp4,mov|max:102400',
            'url' => ['nullable', 'required_without:file', 'url', 'max:300', 'regex:~^https://(www\.)?(youtube\.com|youtu\.be|vimeo\.com)/~'],
            'caption' => 'nullable|string|max:200',
            'taken_on' => 'nullable|date|before_or_equal:today',
            'rights_attested' => 'nullable|boolean',
            'release' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:20480',
            'visibility' => ['required', Rule::in(array_keys(Record::VISIBILITY))],
        ], ['url.regex' => 'Use a YouTube or Vimeo link.']);
        $media = $add($horse, $request->user(), $data, $request->file('file'), $request->file('release'));

        return redirect()->route('horses.show', ['horse' => $horse, 'tab' => 'media'])->with('status', $media->rights_attested
            ? 'Added.' : 'Added as private. To share it, confirm you hold the rights and releases.');
    }

    public function addDocument(Request $request, Horse $horse, StoreDocument $store)
    {
        $this->guard($request, $horse);
        $data = $request->validate([
            'file' => 'required|file|mimes:'.StoreDocument::MIMES.'|max:'.StoreDocument::MAX_KB,
            'kind' => ['required', Rule::in(array_keys(Record::DOCUMENT_KINDS))],
            'title' => 'nullable|string|max:160',
            'issuer' => 'nullable|string|max:160',
            'issued_on' => 'nullable|date|before_or_equal:today',
            'expires_on' => 'nullable|date|after_or_equal:issued_on',
            'visibility' => ['required', Rule::in(array_keys(Record::VISIBILITY))],
        ]);
        $store($horse, $request->user(), $request->file('file'), $data);

        return redirect()->route('horses.show', ['horse' => $horse, 'tab' => 'documents'])->with('status', 'Document uploaded. The original is stored as evidence and is only shown to you and Horsefax staff.');
    }

    public function setPhoto(Request $request, Horse $horse, StoreDocument $store)
    {
        $this->guard($request, $horse);
        $data = $request->validate([
            'photo' => 'required|file|mimes:jpg,jpeg,png,webp|max:15360',
            'visibility' => ['required', Rule::in(array_keys(Record::VISIBILITY))],
        ]);
        $doc = $store($horse, $request->user(), $request->file('photo'), ['kind' => 'photo', 'title' => 'Profile photo', 'visibility' => $data['visibility']]);
        $before = $horse->profile_photo_id;
        $horse->forceFill(['profile_photo_id' => $doc->id])->save();
        Audit::record('horse.photo_set', $horse, ['profile_photo_id' => $before], ['profile_photo_id' => $doc->id]);

        return redirect()->route('horses.show', $horse)->with('status', 'Photo saved.');
    }

    /** Change who can see one item. The change itself is logged. */
    public function setVisibility(Request $request, Horse $horse)
    {
        $this->guard($request, $horse);
        $data = $request->validate([
            'type' => 'required|in:identifier,event,document,name,media,amount',
            'id' => 'required|uuid',
            'visibility' => ['required', Rule::in(array_keys(Record::VISIBILITY))],
        ]);
        $model = match ($data['type']) {
            'identifier' => HorseIdentifier::class, 'event', 'amount' => HorseEvent::class, 'document' => Document::class,
            'name' => HorseName::class, 'media' => \App\Models\Media::class,
        };
        $item = $model::where('horse_id', $horse->id)->findOrFail($data['id']);
        if ($data['type'] === 'amount') {
            $item->forceFill(['amount_visibility' => $data['visibility']])->save();
        } elseif ($data['type'] === 'media' && ! $item->rights_attested && $data['visibility'] !== 'private') {
            return back()->withErrors(['visibility' => 'Confirm you hold the rights and releases before sharing this item.']);
        } else {
            $item->forceFill(['visibility' => $data['visibility']])->save();
        }
        Audit::changes($data['type'].'.visibility_changed', $item);

        return back()->with('status', 'Visibility updated.');
    }
}
