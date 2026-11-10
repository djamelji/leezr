<?php

namespace App\Core\Documents;

use App\Modules\Core\People\PersonRef;

/**
 * ADR-756 (DMS M0) — SUJET documentaire universel : l'entité métier à laquelle un document
 * est rattaché, DÉCOUPLÉE du compte User. Un document appartient à une Company, à une personne
 * (Employee RH **ou** User), ou à une entité fournie par un autre module (ex. véhicule).
 *
 * Compatible PersonRef (`user-{id}` | `employee-{id}`). AUCUN rapprochement automatique :
 * on ne déduit jamais un sujet d'un nom/e-mail — seul un lien explicite (user_id / personRef /
 * référence de module) détermine le sujet. Ajouter un compte User à un Employee NE change PAS
 * le sujet d'un document déjà rattaché à cet Employee (le sujet reste `employee-{id}`).
 */
final class DocumentSubject
{
    public const TYPE_COMPANY = 'company';
    public const TYPE_EMPLOYEE = 'employee';
    public const TYPE_USER = 'user';

    public function __construct(
        public readonly string $type,
        public readonly int $id,
    ) {
    }

    public static function company(int $companyId): self
    {
        return new self(self::TYPE_COMPANY, $companyId);
    }

    public static function employee(int $employeeId): self
    {
        return new self(self::TYPE_EMPLOYEE, $employeeId);
    }

    public static function user(int $userId): self
    {
        return new self(self::TYPE_USER, $userId);
    }

    /** Compatibilité PersonRef — `user-{id}` → sujet user, `employee-{id}` → sujet employee. */
    public static function fromPersonRef(PersonRef $ref): self
    {
        return new self($ref->type, $ref->id);
    }

    /** Sujet d'un document du coffre : la colonne polymorphe si posée, sinon le User propriétaire. */
    public static function fromMemberDocument(MemberDocument $doc): self
    {
        if ($doc->document_subject_type && $doc->document_subject_id) {
            return new self((string) $doc->document_subject_type, (int) $doc->document_subject_id);
        }

        return self::user((int) $doc->user_id);
    }

    /** Référence d'un module tiers (ex. `vehicle`, `contract`) — sujet générique. */
    public static function forModuleEntity(string $type, int $id): self
    {
        return new self($type, $id);
    }

    public function is(string $type): bool
    {
        return $this->type === $type;
    }

    public function __toString(): string
    {
        return "{$this->type}:{$this->id}";
    }
}
