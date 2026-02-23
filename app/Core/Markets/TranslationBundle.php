<?php

namespace App\Core\Markets;

use Illuminate\Database\Eloquent\Model;

class TranslationBundle extends Model
{
    protected $fillable = [
        'locale',
        'namespace',
        'translations',
    ];

    protected $casts = [
        'translations' => 'array',
    ];
}
