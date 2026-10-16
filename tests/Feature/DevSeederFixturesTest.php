<?php

namespace Tests\Feature;

use Database\Seeders\DevSeeder;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Non-regression for the `UnableToCreateDirectory: documents/seed` deploy error.
 *
 * The seeder must copy its demo document fixtures into a disk where the target
 * directory does NOT pre-exist, reproducibly, and be safe to run more than once.
 */
class DevSeederFixturesTest extends TestCase
{
    public function test_copies_fixtures_when_target_directory_does_not_exist(): void
    {
        Storage::fake('local');

        // Precondition: the directory is absent (the exact failing scenario).
        $this->assertFalse(Storage::exists('documents/seed'));

        $stored = DevSeeder::copySeedFixtures();

        foreach (DevSeeder::SEED_FIXTURES as $dest) {
            Storage::assertExists($dest);
        }
        $this->assertNotEmpty($stored);
    }

    public function test_is_idempotent_when_run_twice(): void
    {
        Storage::fake('local');

        DevSeeder::copySeedFixtures();
        // A second run (deploy re-run) must not throw and must keep the files.
        $stored = DevSeeder::copySeedFixtures();

        $this->assertCount(count(DevSeeder::SEED_FIXTURES), $stored);
        foreach (DevSeeder::SEED_FIXTURES as $dest) {
            Storage::assertExists($dest);
        }
    }
}
