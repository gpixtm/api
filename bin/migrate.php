<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$db = App\Infrastructure\Database::connect();
$db->beginTransaction();
try {
    $db->query('SELECT pg_advisory_xact_lock(7140932)');
    $db->exec('CREATE TABLE IF NOT EXISTS migrations (version TEXT PRIMARY KEY, applied_at TIMESTAMPTZ NOT NULL DEFAULT now())');
    foreach (glob(dirname(__DIR__).'/migrations/*.sql') as $file) {
        $version = basename($file);
        $q = $db->prepare('SELECT 1 FROM migrations WHERE version=?'); $q->execute([$version]);
        if ($q->fetchColumn()) continue;
        $db->exec(file_get_contents($file));
        $db->prepare('INSERT INTO migrations(version) VALUES (?)')->execute([$version]);
        echo "Applied $version\n";
    }
    $db->commit();
} catch (Throwable $e) { $db->rollBack(); throw $e; }
