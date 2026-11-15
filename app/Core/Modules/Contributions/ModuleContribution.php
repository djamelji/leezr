<?php

namespace App\Core\Modules\Contributions;

use Illuminate\Database\Eloquent\Model;

/**
 * ADR-794 (L4) — PROVENANCE of a definition or a proposal declared by a module (Platform-controlled,
 * no company). Several modules may point to the SAME canonical definition (one row each).
 */
class ModuleContribution extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_WITHDRAWN = 'withdrawn';
    public const STATUS_COLLISION = 'collision';

    protected $fillable = ['module_key', 'kind', 'ref', 'version', 'fingerprint', 'payload', 'field_definition_id', 'document_type_id', 'status', 'detail'];

    protected $casts = ['payload' => 'array', 'version' => 'integer'];
}
