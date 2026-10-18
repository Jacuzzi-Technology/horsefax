<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Media extends Model
{
    use HasUuids;

    protected $table = 'media';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['taken_on' => 'date', 'rights_attested' => 'boolean'];
    }

    public function horse(): BelongsTo { return $this->belongsTo(Horse::class); }
    public function document(): BelongsTo { return $this->belongsTo(Document::class); }
    public function release(): BelongsTo { return $this->belongsTo(Document::class, 'release_document_id'); }

    /** Only released media can be shown outside the record. */
    public function isReleased(): bool
    {
        return $this->rights_attested;
    }

    /** YouTube / Vimeo embed URL for a video link. */
    public function embedUrl(): ?string
    {
        if (! $this->url) {
            return null;
        }
        if (preg_match('~(?:youtube\.com/(?:watch\?v=|shorts/|embed/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $this->url, $m)) {
            return 'https://www.youtube-nocookie.com/embed/'.$m[1];
        }
        if (preg_match('~vimeo\.com/(?:video/)?(\d+)~', $this->url, $m)) {
            return 'https://player.vimeo.com/video/'.$m[1];
        }

        return null;
    }
}
