<?php

namespace App\Core\Fields;

/**
 * ADR-861 — a module's PROOF that people actually exercise an activity tag (TagDictionary), e.g. the Shipments
 * module knows who is assigned deliveries. Read only ; the Core never knows which module answers.
 */
interface FunctionActivityEvidence
{
    /** @return list<int> user ids of the company who exercise the activity */
    public static function holders(int $companyId): array;
}
