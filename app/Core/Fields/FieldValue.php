<?php

namespace App\Core\Fields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class FieldValue extends Model
{
    protected $fillable = [
        'field_definition_id',
        'model_type',
        'model_id',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(FieldDefinition::class, 'field_definition_id');
    }

    public function model(): MorphTo
    {
        return $this->morphTo();
    }
}
