<?php

namespace App\Core\Documents;

use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * DocumentTemplate represents an HTML template with variable placeholders
 * for generating PDF documents.
 *
 * Versioning: Each update to body_html increments version.
 * Resolution: company active > platform active > DomainException.
 * Variables rendered via strict regex parser, never Blade::render.
 */
class DocumentTemplate extends Model
{
    protected $table = 'document_templates';

    // Note: NOT using BelongsToCompany because company_id is nullable (platform templates)

    const SUBJECT_TYPES = ['employee', 'contract', 'leave_request', 'payroll_run', 'payroll_line'];

    protected $fillable = [
        'company_id',
        'platform_template_id',
        'code',
        'name',
        'description',
        'subject_type',
        'body_html',
        'header_html',
        'footer_html',
        'variables_schema',
        'version',
        'is_system',
        'is_active',
        'paper_size',
        'paper_orientation',
        'metadata',
    ];

    protected $casts = [
        'variables_schema' => 'array',
        'version' => 'integer',
        'is_system' => 'boolean',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    // --- Scopes ---

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePlatform($query)
    {
        return $query->whereNull('company_id');
    }

    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeByCode($query, string $code)
    {
        return $query->where('code', $code);
    }

    public function scopeBySubjectType($query, string $subjectType)
    {
        return $query->where('subject_type', $subjectType);
    }

    // --- Relationships ---

    public function company(): BelongsTo
    {
        return $this->belongsTo(\App\Core\Models\Company::class);
    }

    public function platformTemplate(): BelongsTo
    {
        return $this->belongsTo(self::class, 'platform_template_id');
    }

    public function companyOverrides(): HasMany
    {
        return $this->hasMany(self::class, 'platform_template_id');
    }

    public function generatedDocuments(): HasMany
    {
        return $this->hasMany(GeneratedDocument::class, 'document_template_id');
    }

    // --- Helpers ---

    public function isPlatform(): bool
    {
        return $this->company_id === null;
    }

    public function isOverride(): bool
    {
        return $this->platform_template_id !== null;
    }

    /**
     * Extract all variable keys used in body_html, header_html, footer_html.
     * Pattern: {{ variable.name }}
     */
    public function extractUsedVariables(): array
    {
        $html = ($this->header_html ?? '') . ($this->body_html ?? '') . ($this->footer_html ?? '');
        preg_match_all('/\{\{\s*([\w.]+)\s*\}\}/', $html, $matches);

        return array_unique($matches[1] ?? []);
    }

    /**
     * Validate that all variables used in HTML exist in variables_schema.
     */
    public function validateVariables(): array
    {
        $used = $this->extractUsedVariables();
        $schema = $this->variables_schema ?? [];
        $schemaKeys = array_column($schema, 'key');

        $missing = array_diff($used, $schemaKeys);

        return $missing;
    }
}
