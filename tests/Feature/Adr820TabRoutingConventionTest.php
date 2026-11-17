<?php

namespace Tests\Feature;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * ADR-475 / ADR-820 — a navigable tab lives in the PATH (`/company/people/roles`, `/company/workforce/time/
 * timesheets`, `/platform/jobdomains/{id}/presets`), never in `?tab=`. Search, filters, pagination and
 * one-shot actions stay legitimate query parameters.
 *
 * Readers of an old `?tab=` remain allowed (bookmarks and notifications are redirected to the path) ; WRITERS
 * are forbidden : no link, push, replace or navigation declaration may produce `?tab=`.
 */
class Adr820TabRoutingConventionTest extends TestCase
{
    private static function stripComments(string $src): string
    {
        $src = preg_replace('#<!--.*?-->#s', '', $src);
        $src = preg_replace('#/\*.*?\*/#s', '', $src);

        return preg_replace('#(^|[^:"\'`])//[^\n]*#', '$1', $src);
    }

    public function test_no_frontend_code_writes_a_tab_query(): void
    {
        $violations = [];
        $files = Finder::create()->files()->in(resource_path('js'))->name(['*.vue', '*.js'])->notPath(['plugins/i18n', '@core', '@layouts']);
        foreach ($files as $file) {
            $src = self::stripComments($file->getContents());
            foreach ([
                '/query:\s*\{[^}]*\btab\s*:/' => 'query: { tab: … }',
                '/\bquery\s*=\s*\{[^}]*\btab\s*:/' => 'query = { tab: … }',
                '/[?&]tab=/' => '?tab= in a link',
                '/\bq\.tab\s*=(?!=)/' => 'q.tab = …',
                '/query\.tab\s*=(?!=)/' => 'query.tab = …',
            ] as $re => $what) {
                if (preg_match($re, $src, $m)) {
                    $violations[] = $file->getRelativePathname().' — '.$what.' ('.trim($m[0]).')';
                }
            }
        }
        $this->assertSame([], $violations, "Navigable tabs must use the path (ADR-475).\n".implode("\n", $violations));
    }

    public function test_navigation_declarations_never_use_a_tab_query(): void
    {
        $violations = [];
        foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
            if (preg_match("/'query'\s*=>\s*\[[^\]]*'tab'\s*=>/", $file->getContents(), $m)) {
                $violations[] = $file->getRelativePathname();
            }
        }
        $this->assertSame([], $violations, 'Module navigation / home links must target the tab path.');
    }
}
