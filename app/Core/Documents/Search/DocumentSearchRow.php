<?php

namespace App\Core\Documents\Search;

/** ADR-778 (UX3) — one search result : what a list screen already shows, nothing more. */
final class DocumentSearchRow
{
    /**
     * @param  array{ref: string, name: string}|null  $person  null = document of the company itself
     * @param  array  $open      route location of the place where the document lives
     * @param  string|null  $download  API path of the (guarded) download, null when not downloadable
     */
    public static function make(string $source, int $id, string $name, ?string $typeCode, ?string $typeLabel, ?array $person, string $status, ?string $expiresAt, ?string $date, array $open, ?string $download): array
    {
        return [
            'key' => "{$source}:{$id}", 'source' => $source, 'name' => $name,
            'type_code' => $typeCode, 'type_label' => $typeLabel, 'person' => $person,
            'status' => $status, 'expires_at' => $expiresAt, 'date' => $date,
            'open' => $open, 'download' => $download,
        ];
    }
}
