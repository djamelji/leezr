<?php

namespace App\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformFont extends Model
{
    protected $fillable = [
        'family_id',
        'weight',
        'style',
        'format',
        'file_path',
        'original_name',
        'sha256',
    ];

    protected $casts = [
        'weight' => 'integer',
    ];

    public function family(): BelongsTo
    {
        return $this->belongsTo(PlatformFontFamily::class, 'family_id');
    }
}
