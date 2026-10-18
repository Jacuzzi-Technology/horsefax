<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\ShareLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Original files are only served to the horse's stewards and staff. Readers of a public summary or report link can
 * see the profile photo when its visibility allows; documents are listed to them, never served.
 */
class FileController extends Controller
{
    public function show(Request $request, Document $document)
    {
        $horse = $document->horse;
        $allowed = $horse->canBeViewedPrivatelyBy($request->user())
            || ($document->uploaded_by === $request->user()?->id);

        if (! $allowed && $document->id === $horse->profile_photo_id) {
            if ($document->visibility === 'public' && $horse->public_summary) {
                $allowed = true;
            } elseif ($request->filled('r') && in_array($document->visibility, ['shared', 'public'], true)) {
                $link = ShareLink::where('token', $request->query('r'))->where('horse_id', $horse->id)->first();
                $allowed = $link?->isActive() ?? false;
            }
        }
        if (! $allowed && ($media = \App\Models\Media::where('document_id', $document->id)->where('rights_attested', true)->first())) {
            if ($media->visibility === 'public' && $horse->public_summary) {
                $allowed = true;
            } elseif ($request->filled('r') && in_array($media->visibility, ['shared', 'public'], true)) {
                $link = ShareLink::where('token', $request->query('r'))->where('horse_id', $horse->id)->first();
                $allowed = ($link?->isActive() ?? false) && $link->includes('media');
            }
        }
        abort_unless($allowed, 404);
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        $inline = $document->isImage() || $document->mime === 'application/pdf';

        return Storage::disk('local')->response($document->path, $document->original_name, [
            'Content-Type' => $document->mime,
            'Cache-Control' => 'private, max-age=300',
            'X-Robots-Tag' => 'noindex',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ], $inline ? 'inline' : 'attachment');
    }
}
