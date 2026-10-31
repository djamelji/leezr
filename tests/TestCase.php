<?php

namespace Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * W-HR-UX-5 (P5) — restaure l'état de mass-assignment après CHAQUE test.
     *
     * `Model::unguard()` bascule un flag STATIQUE GLOBAL (`Model::$unguarded`) qui survit d'un test
     * à l'autre dans le même process. Une vingtaine de tests appellent `unguard()` en setUp sans le
     * remettre → dès qu'un seul s'exécute, tous les modèles restent déguardés pour le reste de la
     * suite, ce qui faisait échouer en lot (mais pas en isolation) des tests vérifiant justement la
     * protection d'affectation de masse (NameSplitTest, InvoiceTaxExemptionTest…). Reguard systématique
     * ici = isolation garantie quel que soit l'ordre, sans toucher au comportement métier.
     */
    protected function tearDown(): void
    {
        Model::reguard();
        parent::tearDown();
    }
}
