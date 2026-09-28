<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$db = App\Infrastructure\Database::connect();
$db->beginTransaction();
try {
    $db->query('SELECT pg_advisory_xact_lock(7140932)');
    $db->exec('CREATE TABLE IF NOT EXISTS migrations (version TEXT PRIMARY KEY, applied_at TIMESTAMPTZ NOT NULL DEFAULT now())');
    // SQL files change the schema; a PHP file returns a callable for data that needs domain rules.
    $files = array_merge(glob(dirname(__DIR__).'/migrations/*.sql'), glob(dirname(__DIR__).'/migrations/*.php'));
    usort($files, fn(string $a, string $b) => strcmp(basename($a), basename($b)));
    foreach ($files as $file) {
        $version = basename($file);
        $q = $db->prepare('SELECT 1 FROM migrations WHERE version=?'); $q->execute([$version]);
        if ($q->fetchColumn()) continue;
        if (str_ends_with($file, '.php')) (require $file)($db);
        else $db->exec(file_get_contents($file));
        $db->prepare('INSERT INTO migrations(version) VALUES (?)')->execute([$version]);
        echo "Applied $version\n";
    }
    $db->commit();
} catch (Throwable $e) { $db->rollBack(); throw $e; }
