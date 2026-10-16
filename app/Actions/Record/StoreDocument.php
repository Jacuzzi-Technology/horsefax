<?php

namespace App\Actions\Record;

use App\Models\Document;
use App\Models\Horse;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Originals are kept as evidence on the private disk and only ever served through DocumentController, which checks
 * who is asking. They are never edited or replaced.
 */
class StoreDocument
{
    public const MIMES = 'pdf,jpg,jpeg,png,webp,heic';
    public const MAX_KB = 20480;

    public function __invoke(Horse $horse, User $user, UploadedFile $file, array $data): Document
    {
        $id = (string) Str::uuid();
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $path = $file->storeAs('documents/'.$horse->id, $id.'.'.$ext, 'local');

        $document = Document::create([
            'id' => $id,
            'horse_id' => $horse->id,
            'uploaded_by' => $user->id,
            'kind' => $data['kind'],
            'title' => ($data['title'] ?? null) ?: (\App\Support\Record::DOCUMENT_KINDS[$data['kind']] ?? 'Document'),
            'issued_on' => $data['issued_on'] ?? null,
            'expires_on' => $data['expires_on'] ?? null,
            'issuer' => $data['issuer'] ?? null,
            'original_name' => Str::limit($file->getClientOriginalName(), 200, ''),
            'mime' => $file->getMimeType() ?: 'application/octet-stream',
            'size' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'path' => $path,
            'visibility' => $data['visibility'] ?? 'private',
            'contributor_role' => $data['contributor_role'] ?? null,
            'source_type' => $data['source_type'] ?? \App\Support\Record::sourceForRole($data['contributor_role'] ?? null),
            'status' => 'unverified',
        ]);
        Audit::record('document.uploaded', $document, null, $document->only(['kind', 'title', 'issued_on', 'expires_on', 'visibility', 'sha256', 'original_name', 'contributor_role']));

        return $document;
    }
}
