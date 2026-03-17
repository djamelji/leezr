<?php

namespace App\Core\Documentation;

use Illuminate\Database\Eloquent\Model;

class DocumentationSearchLog extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'query',
        'results_count',
        'audience',
        'user_type',
        'user_id',
    ];

    protected $casts = [
        'results_count' => 'integer',
    ];
}
