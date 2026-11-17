<?php

/*
 * Source-level guards for the hard rules in CLAUDE.md. They fail the build when someone bypasses the
 * single place that is allowed to do a dangerous thing.
 */

/** @return array<string, string> relative path => contents, for every PHP file under app/ */
function appSources(): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app'));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[substr(str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname()), strlen(dirname(__DIR__, 2)) + 1)] = file_get_contents($file->getPathname());
        }
    }

    return $files;
}

function offendingFiles(string $pattern, array $allowed): array
{
    $bad = [];
    foreach (appSources() as $path => $source) {
        $normalized = str_replace(DIRECTORY_SEPARATOR, '/', $path);
        // Comments may talk about the forbidden thing; only code counts.
        $code = preg_replace('~/\*.*?\*/|(?<![:"\'])//[^\n]*~s', '', $source);
        if (! in_array($normalized, $allowed, true) && preg_match($pattern, (string) $code)) {
            $bad[] = $normalized;
        }
    }

    return $bad;
}

it('writes orders.status only in the state machine', function () {
    $bad = offendingFiles(
        '/Order::query\(\)[^;]*->update\([^)]*[\'"]status[\'"]|->update\(\s*\[[^\]]*[\'"]status[\'"]\s*=>|\$order->status\s*=[^=]/s',
        ['app/Domain/Orders/OrderStateMachine.php'],
    );

    expect($bad)->toBe([]);
});

it('changes ticket_types.sold and held only in TicketStock', function () {
    $bad = offendingFiles('/\b(sold|held)\s*=\s*(tt\.)?(sold|held)\s*[+-]|SET\s+(sold|held)\s*=/i', ['app/Domain/Inventory/TicketStock.php']);

    expect($bad)->toBe([]);
});

it('never uses floats or round() for money', function () {
    $bad = offendingFiles('/\bround\(|\(float\)|\bfloatval\(|[\'"]decimal:/', []);

    expect($bad)->toBe([]);
});

it('keeps DB:: out of controllers', function () {
    $bad = [];
    foreach (appSources() as $path => $source) {
        if (str_contains(str_replace(DIRECTORY_SEPARATOR, '/', $path), 'app/Http/Controllers/') && preg_match('/\bDB::/', $source)) {
            $bad[] = $path;
        }
    }

    expect($bad)->toBe([]);
});
