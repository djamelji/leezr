<?php

namespace Tests\Feature;

use App\Core\Automation\ScheduledTaskRegistry;
use Tests\TestCase;

/**
 * Drift guard: the Automations cockpit registry (ScheduledTaskRegistry) MUST stay
 * a faithful mirror of the real scheduler (routes/console.php). Any Schedule::command()
 * added without a registry entry would be a "ghost task" — scheduled but invisible and
 * unpilotable from /platform/operations/automations. The reverse (registry entry with no
 * real schedule) would advertise a task that never runs. Both are forbidden.
 */
class ScheduledTaskRegistryConsistencyTest extends TestCase
{
    /** @return string[] command signatures actually wired in routes/console.php */
    /**
     * Infra tasks intentionally scheduled but NOT exposed as cockpit business tasks
     * (documented convention). scheduler:heartbeat is the liveness probe that powers
     * globalHealth — it is always-on, non-disableable, and not instrumented.
     */
    private const REGISTRY_EXCLUSIONS = ['scheduler:heartbeat'];

    private function scheduledCommands(): array
    {
        $source = file_get_contents(base_path('routes/console.php'));
        preg_match_all("/Schedule::command\(\s*'([^']+)'/", $source, $m);

        $commands = collect($m[1])
            // Keep only the command signature (strip arguments/options after the name).
            ->map(fn (string $c) => trim(explode(' ', $c)[0]))
            ->reject(fn (string $c) => in_array($c, self::REGISTRY_EXCLUSIONS, true))
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $commands;
    }

    /** @return string[] command signatures declared in the cockpit registry */
    private function registryCommands(): array
    {
        return collect(ScheduledTaskRegistry::all())
            ->map(fn (array $t) => $t['command'])
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public function test_every_scheduled_command_is_registered_in_the_cockpit(): void
    {
        $scheduled = $this->scheduledCommands();
        $registry = $this->registryCommands();

        $ghosts = array_values(array_diff($scheduled, $registry));

        $this->assertSame(
            [],
            $ghosts,
            'Ghost tasks scheduled in routes/console.php but absent from ScheduledTaskRegistry '
            .'(invisible/unpilotable in the Automations cockpit): '.implode(', ', $ghosts)
        );
    }

    public function test_no_registry_entry_without_a_real_schedule(): void
    {
        $scheduled = $this->scheduledCommands();
        $registry = $this->registryCommands();

        $orphans = array_values(array_diff($registry, $scheduled));

        $this->assertSame(
            [],
            $orphans,
            'Registry entries with no matching Schedule::command() in routes/console.php '
            .'(advertised but never run): '.implode(', ', $orphans)
        );
    }

    public function test_registry_and_scheduler_are_in_bijection(): void
    {
        $this->assertSame(
            $this->scheduledCommands(),
            $this->registryCommands(),
            'ScheduledTaskRegistry must be a 1:1 mirror of routes/console.php.'
        );
    }

    public function test_every_registry_entry_has_complete_metadata(): void
    {
        foreach (ScheduledTaskRegistry::all() as $key => $task) {
            foreach (['command', 'description', 'category', 'frequency', 'cron', 'expected_interval_minutes'] as $field) {
                $this->assertArrayHasKey($field, $task, "Task '{$key}' is missing metadata field '{$field}'.");
            }
            $this->assertNotEmpty($task['cron'], "Task '{$key}' has an empty cron expression.");
        }
    }
}
