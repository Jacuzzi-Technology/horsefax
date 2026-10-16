<?php

namespace App\Models;

use App\Support\Record;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Document extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'expires_on' => 'date'];
    }

    public function horse(): BelongsTo { return $this->belongsTo(Horse::class); }
    public function uploader(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by'); }

    public function kindLabel(): string
    {
        return Record::DOCUMENT_KINDS[$this->kind] ?? $this->kind;
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }
}
