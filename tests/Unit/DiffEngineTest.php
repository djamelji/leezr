<?php

namespace Tests\Unit;

use App\Core\Audit\DiffEngine;
use PHPUnit\Framework\TestCase;

class DiffEngineTest extends TestCase
{
    public function test_detects_added_keys(): void
    {
        $diff = DiffEngine::diff(
            ['name' => 'Alice'],
            ['name' => 'Alice', 'email' => 'alice@example.com'],
        );

        $this->assertArrayHasKey('email', $diff['added']);
        $this->assertEquals('alice@example.com', $diff['added']['email']);
        $this->assertEmpty($diff['removed']);
        $this->assertEmpty($diff['changed']);
    }

    public function test_detects_removed_keys(): void
    {
        $diff = DiffEngine::diff(
            ['name' => 'Alice', 'email' => 'alice@example.com'],
            ['name' => 'Alice'],
        );

        $this->assertArrayHasKey('email', $diff['removed']);
        $this->assertEquals('alice@example.com', $diff['removed']['email']);
        $this->assertEmpty($diff['added']);
        $this->assertEmpty($diff['changed']);
    }

    public function test_detects_changed_values(): void
    {
        $diff = DiffEngine::diff(
            ['name' => 'Alice', 'role' => 'user'],
            ['name' => 'Alice', 'role' => 'admin'],
        );

        $this->assertArrayHasKey('role', $diff['changed']);
        $this->assertEquals('user', $diff['changed']['role']['from']);
        $this->assertEquals('admin', $diff['changed']['role']['to']);
        $this->assertEmpty($diff['added']);
        $this->assertEmpty($diff['removed']);
    }

    public function test_empty_diff_when_identical(): void
    {
        $diff = DiffEngine::diff(
            ['name' => 'Alice', 'role' => 'user'],
            ['name' => 'Alice', 'role' => 'user'],
        );

        $this->assertTrue(DiffEngine::isEmpty($diff));
    }

    public function test_empty_arrays(): void
    {
        $diff = DiffEngine::diff([], []);

        $this->assertTrue(DiffEngine::isEmpty($diff));
    }

    public function test_filters_sensitive_password_field(): void
    {
        $diff = DiffEngine::diff(
            ['password' => 'old_hash'],
            ['password' => 'new_hash'],
        );

        // Both sides should be redacted, so no change detected
        $this->assertEmpty($diff['changed']);
    }

    public function test_filters_sensitive_api_key_field(): void
    {
        $diff = DiffEngine::diff(
            ['api_key' => 'key_123'],
            ['api_key' => 'key_456'],
        );

        $this->assertEmpty($diff['changed']);
    }

    public function test_filters_sensitive_fields_in_nested_arrays(): void
    {
        $diff = DiffEngine::diff(
            ['config' => ['token' => 'abc']],
            ['config' => ['token' => 'xyz']],
        );

        // Nested sensitive fields should be redacted
        $this->assertEmpty($diff['changed']);
    }

    public function test_complex_diff_with_mixed_operations(): void
    {
        $diff = DiffEngine::diff(
            ['name' => 'Acme', 'plan' => 'free', 'old_field' => 'x'],
            ['name' => 'Acme Corp', 'plan' => 'free', 'new_field' => 'y'],
        );

        $this->assertArrayHasKey('name', $diff['changed']);
        $this->assertEquals('Acme', $diff['changed']['name']['from']);
        $this->assertEquals('Acme Corp', $diff['changed']['name']['to']);
        $this->assertArrayHasKey('new_field', $diff['added']);
        $this->assertArrayHasKey('old_field', $diff['removed']);
    }

    public function test_is_empty_returns_false_for_non_empty_diff(): void
    {
        $diff = DiffEngine::diff(
            ['a' => 1],
            ['a' => 2],
        );

        $this->assertFalse(DiffEngine::isEmpty($diff));
    }

    public function test_sensitive_fields_redacted_not_removed(): void
    {
        // When a sensitive field is added, it should appear as [REDACTED]
        $diff = DiffEngine::diff(
            [],
            ['password' => 'secret123', 'name' => 'Test'],
        );

        $this->assertArrayHasKey('password', $diff['added']);
        $this->assertEquals('[REDACTED]', $diff['added']['password']);
        $this->assertArrayHasKey('name', $diff['added']);
        $this->assertEquals('Test', $diff['added']['name']);
    }
}
