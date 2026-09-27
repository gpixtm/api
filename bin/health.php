<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
try {
    App\Infrastructure\Database::connect()->query('SELECT 1 FROM migrations LIMIT 1');
    $response = @file_get_contents('http://127.0.0.1/health');
    exit($response !== false && (json_decode($response, true)['status'] ?? null) === 'ok' ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, "API or database is unavailable.\n");
    exit(1);
}
