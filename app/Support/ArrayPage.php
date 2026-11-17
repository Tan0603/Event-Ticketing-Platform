<?php

namespace App\Support;

/**
 * Pages a small, already-aggregated list (a day series, the ticket types of one event). The aggregation itself
 * happened in SQL; this only slices the finished rows so every report answers with the same `meta` as a normal list.
 */
final class ArrayPage
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{rows: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public static function slice(array $rows, int $page, int $perPage): array
    {
        $total = count($rows);
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        return [
            'rows' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }
}
