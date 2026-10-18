<?php

namespace App\Actions\Record;

use App\Models\Horse;
use App\Models\Media;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;

/**
 * Photos and videos are entered by the current owner. Without the rights/release attestation an item stays private,
 * whatever visibility was asked for.
 */
class AddMedia
{
    public function __invoke(Horse $horse, User $user, array $data, ?UploadedFile $file, ?UploadedFile $release): Media
    {
        $store = app(StoreDocument::class);
        $docId = $file ? $store($horse, $user, $file, ['kind' => 'photo', 'title' => $data['caption'] ?: 'Photo or video', 'visibility' => 'private'])->id : null;
        $releaseId = $release ? $store($horse, $user, $release, ['kind' => 'other', 'title' => 'Media release', 'visibility' => 'private'])->id : null;
        $attested = (bool) ($data['rights_attested'] ?? false);

        $media = Media::create([
            'horse_id' => $horse->id,
            'uploaded_by' => $user->id,
            'kind' => $file ? (str_starts_with((string) $file->getMimeType(), 'video/') ? 'video' : 'photo') : 'video_link',
            'caption' => $data['caption'] ?? null,
            'taken_on' => $data['taken_on'] ?? null,
            'document_id' => $docId,
            'url' => $data['url'] ?? null,
            'rights_attested' => $attested,
            'release_document_id' => $releaseId,
            'visibility' => $attested ? $data['visibility'] : 'private',
        ]);
        Audit::record('media.added', $media, null, $media->only(['kind', 'caption', 'url', 'rights_attested', 'visibility']));

        return $media;
    }
}
