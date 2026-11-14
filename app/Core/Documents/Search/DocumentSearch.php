<?php

namespace App\Core\Documents\Search;

use App\Core\Home\HomeContext;
use App\Core\Modules\ModuleGate;
use App\Core\Modules\ModuleRegistry;

/**
 * ADR-778 (UX3) — transversal document search, assembled from the sources contributed by the
 * ACTIVE modules. The Core does not know a document type nor an access rule : each source filters
 * its own rows. Server-side, tenant-scoped, paginated.
 *
 * Each source is read up to a bounded depth (WINDOW rows after authorisation) : beyond it the
 * result says `truncated` and the user narrows the criteria — the browser never receives a whole
 * document base.
 */
class DocumentSearch
{
    public const WINDOW = 200;
    public const PER_PAGE = 20;

    public static function run(HomeContext $viewer, DocumentSearchQuery $query, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        $rows = [];
        $truncated = false;
        $unavailable = [];
        $sources = [];

        foreach (ModuleRegistry::forScope('company') as $manifest) {
            if ($manifest->capabilities->documentSearchSources === [] || ! ModuleGate::isActive($viewer->company, $manifest->key)) {
                continue;
            }
            foreach ($manifest->capabilities->documentSearchSources as $class) {
                /** @var DocumentSearchSource $source */
                $source = app($class);
                if ($query->source !== null && $query->source !== $source->key()) {
                    continue;
                }
                try {
                    $found = $source->search($viewer, $query, self::WINDOW);
                    $rows = array_merge($rows, $found['rows']);
                    $truncated = $truncated || $found['truncated'];
                    $sources[] = $source->key();
                } catch (\Throwable $e) {
                    report($e);
                    $unavailable[] = $source->key();
                }
            }
        }

        usort($rows, fn (array $a, array $b) => strcmp((string) $b['date'], (string) $a['date']));
        $perPage = max(1, min(50, $perPage));
        $page = max(1, $page);

        return [
            'data' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'meta' => ['total' => count($rows), 'page' => $page, 'per_page' => $perPage, 'truncated' => $truncated, 'sources' => $sources, 'unavailable' => $unavailable],
        ];
    }
}
