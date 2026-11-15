<?php

namespace App\Core\Requirements;

use App\Core\Documents\DocumentType;
use App\Core\Fields\FieldDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-791 (L1) — one OBLIGATION to provide a field or a document, with its source and level.
 * Not a definition (catalogues), not a value, not a permission.
 *
 * Invariants (checked on every save, DomainException otherwise) :
 *  - exactly one target, consistent with `target_kind` ;
 *  - `waive` (dispense) only from the Company, without level ;
 *  - `require` always has a level ;
 *  - `imperative` only with an explicit authority (`legal_reference`) AND a verification date — never
 *    inferred, never from a JobDomain nor from the Company itself ;
 *  - a `company` source belongs to a Company.
 */
class Requirement extends Model
{
    public const KIND_FIELD = 'field';
    public const KIND_DOCUMENT = 'document';

    public const EFFECT_REQUIRE = 'require';
    public const EFFECT_WAIVE = 'waive';

    public const SOURCES = ['platform', 'market', 'jobdomain', 'preset', 'module', 'company'];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'company_id', 'target_kind', 'field_definition_id', 'document_type_id', 'effect', 'level', 'applicability', 'stage',
        'source', 'source_ref', 'source_version', 'derived_from_id', 'is_template', 'legal_reference', 'verified_at', 'status',
        'effective_from', 'effective_until', 'customized_at', 'reason', 'created_by',
    ];

    protected $casts = [
        'applicability' => 'array',
        'source_version' => 'integer',
        'is_template' => 'boolean',
        'verified_at' => 'datetime',
        'effective_from' => 'datetime',
        'effective_until' => 'datetime',
        'customized_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(fn (self $r) => $r->assertValid());
    }

    public function assertValid(): void
    {
        $field = $this->field_definition_id !== null;
        $doc = $this->document_type_id !== null;
        if ($field === $doc) {
            throw new \DomainException('A requirement targets exactly one field definition or one document type.');
        }
        if (($this->target_kind === self::KIND_FIELD) !== $field || ! in_array($this->target_kind, [self::KIND_FIELD, self::KIND_DOCUMENT], true)) {
            throw new \DomainException('target_kind does not match the target.');
        }
        if (! in_array($this->source, self::SOURCES, true)) {
            throw new \DomainException("Unknown requirement source « {$this->source} ».");
        }
        if ($this->is_template && ($this->company_id !== null || ! in_array($this->source, ['module', 'preset'], true))) {
            throw new \DomainException('A template (proposal) has no company and comes from a module or a preset.');
        }
        if ($this->source === 'company' && $this->company_id === null) {
            throw new \DomainException('A company requirement belongs to a company.');
        }
        $effect = $this->effect ?? self::EFFECT_REQUIRE;
        if ($effect === self::EFFECT_WAIVE) {
            if ($this->source !== 'company' || $this->level !== null) {
                throw new \DomainException('Only the company waives a requirement, without level.');
            }

            return;
        }
        if ($effect !== self::EFFECT_REQUIRE || ! RequirementLevel::isValid($this->level)) {
            throw new \DomainException('A requirement needs a valid level.');
        }
        if ($this->level === RequirementLevel::IMPERATIVE) {
            if (blank($this->legal_reference) || $this->verified_at === null) {
                throw new \DomainException('An imperative requirement needs an explicit authority (legal_reference) and a verification date.');
            }
            if (in_array($this->source, ['jobdomain', 'company', 'preset'], true)) {
                throw new \DomainException('A JobDomain, a preset or the company itself is not a legal authority.');
            }
        }
    }

    public function targetKey(): string
    {
        return $this->target_kind.':'.($this->field_definition_id ?? $this->document_type_id);
    }

    public function fieldDefinition(): BelongsTo
    {
        return $this->belongsTo(FieldDefinition::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }
}
