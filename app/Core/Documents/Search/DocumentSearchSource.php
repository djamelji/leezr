<?php

namespace App\Core\Documents\Search;

use App\Core\Home\HomeContext;

/**
 * ADR-778 (UX3) — a module contributes the documents IT owns to the transversal search
 * (`Capabilities::$documentSearchSources`). The source is the only one that knows its access
 * rules : it returns ONLY documents the viewer may see, with NON-SENSITIVE metadata.
 *
 * Contract of a returned row (see DocumentSearchRow::make) :
 *   - authorised for THIS viewer (same authority as the screens that already list the document) ;
 *   - scoped to `$viewer->company` ;
 *   - no file path, no extracted text, no snapshot — never anything the list screens do not show.
 */
interface DocumentSearchSource
{
    /** Provenance key of the rows this source returns (company | person | generated…). */
    public function key(): string;

    /**
     * Most recent first, at most `$limit` rows AFTER authorisation.
     *
     * @return array{rows: array<int, array<string, mixed>>, truncated: bool}
     */
    public function search(HomeContext $viewer, DocumentSearchQuery $query, int $limit): array;
}
