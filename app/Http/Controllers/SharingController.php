<?php

namespace App\Http\Controllers;

use App\Actions\Record\ShareLinks;
use App\Models\Horse;
use App\Models\ShareLink;
use App\Support\Audit;
use App\Support\Record;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SharingController extends Controller
{
    public function index(Request $request, Horse $horse)
    {
        abort_unless($horse->canBeEditedBy($request->user()), 403);

        return view('horses.sharing', [
            'horse' => $horse,
            'links' => $horse->shareLinks()->orderByDesc('created_at')->get(),
            'newLink' => session('new_link_id') ? ShareLink::find(session('new_link_id')) : null,
        ]);
    }

    public function togglePublic(Request $request, Horse $horse)
    {
        abort_unless($horse->canBeEditedBy($request->user()), 403);
        $horse->forceFill(['public_summary' => $request->boolean('public_summary'), 'listed' => $request->boolean('listed')])->save();
        Audit::changes('horse.publication_changed', $horse);

        return back()->with('status', 'Saved. '.($horse->public_summary ? 'Public summary is on; only items marked Public appear on it.' : 'Public summary is off.').($horse->listed ? ' Basic identity is listed in search.' : ' Not listed in search.'));
    }

    public function store(Request $request, Horse $horse, ShareLinks $links)
    {
        abort_unless($horse->canBeEditedBy($request->user()), 403);
        $data = $request->validate([
            'label' => 'nullable|string|max:120',
            'sections' => 'nullable|array',
            'sections.*' => [Rule::in(array_keys(Record::SHARE_SECTIONS))],
            'days' => 'nullable|in:7,30,90',
        ]);
        $link = $links->create($horse, $request->user(), $data['sections'] ?? [], $data['label'] ?? null, isset($data['days']) ? (int) $data['days'] : null);

        return redirect()->route('sharing', $horse)->with('new_link_id', $link->id)->with('status', 'Report link created.');
    }

    public function revoke(Request $request, Horse $horse, ShareLink $link, ShareLinks $links)
    {
        abort_unless($horse->canBeEditedBy($request->user()) && $link->horse_id === $horse->id, 403);
        $links->revoke($link);

        return back()->with('status', 'Link revoked. It no longer opens the report.');
    }
}
