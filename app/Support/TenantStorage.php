<?php

namespace App\Support;

use LogicException;

/**
 * Builds every file path of tenant data. Files live on a shared disk, so isolation is the path: everything of a
 * tenant sits under `exports/{tenant id}/`. Like TenantKey it throws outside tenant context rather than produce
 * an un-namespaced path, and `owns()` lets a reader refuse a path that is not its tenant's.
 */
final class TenantStorage
{
    public static function exportPath(string $filename): string
    {
        return self::exportDirectory().'/'.ltrim($filename, '/');
    }

    public static function owns(string $path): bool
    {
        return str_starts_with($path, self::exportDirectory().'/') && ! str_contains($path, '..');
    }

    private static function exportDirectory(): string
    {
        $id = tenant('id');

        if (! is_string($id) || $id === '') {
            throw new LogicException('TenantStorage used outside tenant context.');
        }

        return 'exports/'.$id;
    }
}
