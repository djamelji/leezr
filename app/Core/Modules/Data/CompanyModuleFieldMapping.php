<?php

namespace App\Core\Modules\Data;

use Illuminate\Database\Eloquent\Model;

/** ADR-820 — confirmed binding of a module data need to a company field (by stable id). */
class CompanyModuleFieldMapping extends Model
{
    protected $fillable = ['company_id', 'module_key', 'need_key', 'field_definition_id', 'confirmed_at', 'confirmed_by'];

    protected $casts = ['confirmed_at' => 'datetime'];
}
