<?php

namespace App\Core\Fields;

use App\Core\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldActivation extends Model
{
    protected $fillable = [
        'company_id',
        'field_definition_id',
        'enabled',
        'required_override',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'required_override' => 'boolean',
        ];
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(FieldDefinition::class, 'field_definition_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
