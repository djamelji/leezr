<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ADR-818 — server messages follow the interface language sent by the SPA (X-Locale) ; default unchanged. */
class Adr818RequestLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_messages_follow_x_locale(): void
    {
        $fr = $this->withHeaders(['X-Locale' => 'fr'])->postJson('/api/login', [])->assertStatus(422)->json('message');
        $en = $this->withHeaders(['X-Locale' => 'en'])->postJson('/api/login', [])->assertStatus(422)->json('message');
        $none = $this->postJson('/api/login', [])->assertStatus(422)->json('message');
        $this->assertNotSame($fr, $en, 'French and English messages differ');
        $this->assertSame($en, $none, 'without the header : application default');
        $this->withHeaders(['X-Locale' => 'xx'])->postJson('/api/login', [])->assertStatus(422)->assertJsonPath('message', $none);
    }
}
