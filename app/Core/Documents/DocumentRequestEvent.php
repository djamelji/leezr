<?php

namespace App\Core\Documents;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-759 (DMS M2) — événement APPEND-ONLY du cycle d'une demande documentaire. Immuable.
 * Conserve chaque transition (demandé, déposé, en vérification, accepté, refusé+motif, à remplacer,
 * annulé) avec acteur, note et version liée → traçabilité complète, aucune preuve supprimée.
 */
class DocumentRequestEvent extends Model
{
    public const UPDATED_AT = null; // immuable

    public const E_REQUESTED = 'requested';
    public const E_SUBMITTED = 'submitted';
    public const E_IN_REVIEW = 'in_review';
    public const E_APPROVED = 'approved';
    public const E_REJECTED = 'rejected';
    public const E_TO_REPLACE = 'to_replace';
    public const E_CANCELLED = 'cancelled';
    public const E_REMINDED = 'reminded';   // relance (tier dans metadata)
    public const E_RENEWED = 'renewed';
    public const E_MERGED = 'merged';       // ADR-770 : demande du même type repliée dans celle-ci (transition de sujet)
    public const E_SUBJECT_MOVED = 'subject_moved'; // ADR-770 : la demande suit la personne (fiche ⇄ compte)     // demande de renouvellement ouverte à l'expiration

    protected $fillable = [
        'company_id', 'document_request_id', 'event', 'actor_id', 'note', 'version_id', 'metadata', 'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(DocumentRequest::class, 'document_request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
