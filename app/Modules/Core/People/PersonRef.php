<?php

namespace App\Modules\Core\People;

/**
 * ADR-753 (Consolidation Company — Phase 1) — référence de personne NON AMBIGUË pour la fiche
 * Collaborateur unifiée. Format URL-safe : `user-{id}` | `employee-{id}` (pas de `:` à encoder).
 *
 * Elle est toujours interprétée DANS le contexte de la Company courante (jamais cross-tenant),
 * et résolue via un lien `user_id` EXPLICITE — jamais par égalité de nom/e-mail (ADR-752).
 */
final class PersonRef
{
    public const TYPE_USER = 'user';
    public const TYPE_EMPLOYEE = 'employee';

    public function __construct(public readonly string $type, public readonly int $id)
    {
    }

    /** Parse `user-5` / `employee-12` ; null si invalide. */
    public static function parse(?string $ref): ?self
    {
        if ($ref !== null && preg_match('/^(user|employee)-(\d+)$/', $ref, $m)) {
            return new self($m[1], (int) $m[2]);
        }

        return null;
    }

    public static function forUser(int $id): self
    {
        return new self(self::TYPE_USER, $id);
    }

    public static function forEmployee(int $id): self
    {
        return new self(self::TYPE_EMPLOYEE, $id);
    }

    public function isUser(): bool
    {
        return $this->type === self::TYPE_USER;
    }

    public function isEmployee(): bool
    {
        return $this->type === self::TYPE_EMPLOYEE;
    }

    public function __toString(): string
    {
        return "{$this->type}-{$this->id}";
    }
}
