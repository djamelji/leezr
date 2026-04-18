<?php

namespace App\Core\Email;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class EmailAttachment extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'email_log_id',
        'filename',
        'original_filename',
        'mime_type',
        'size_bytes',
        'disk',
        'path',
        'created_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'created_at' => 'datetime',
    ];

    protected $appends = ['human_size'];

    public function emailLog(): BelongsTo
    {
        return $this->belongsTo(EmailLog::class);
    }

    public function getHumanSizeAttribute(): string
    {
        $bytes = $this->size_bytes;
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1).' Mo';
        }

        return round($bytes / 1024, 1).' Ko';
    }

    public function getUrlAttribute(): string
    {
        return url("/api/platform/email/inbox/attachments/{$this->id}/download");
    }

    public function getStoragePath(): string
    {
        return Storage::disk($this->disk)->path($this->path);
    }
}
