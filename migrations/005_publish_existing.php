<?php
declare(strict_types=1);

/**
 * Publishes every trail already in an owned library (accepted decision of 28 September 2026:
 * existing trails become shared). Walks, planned days and statistics stay private. The legacy
 * owner's copies come first, then older accounts, so a line added twice keeps its first name.
 */
return static function (PDO $db): void {
    $publisher = new App\Infrastructure\PostgresPublicTrails($db, new App\Infrastructure\OwnerContext());
    $rows = $db->query("SELECT t.id, t.owner_id, t.payload FROM trails t JOIN users u ON u.id = t.owner_id
        WHERE NOT t.deleted AND jsonb_typeof(t.payload->'walk') IS DISTINCT FROM 'object'
        ORDER BY u.legacy_owner DESC, u.created_at, t.id")->fetchAll(PDO::FETCH_ASSOC);
    $link = $db->prepare('UPDATE trails SET public_id = ? WHERE owner_id = ? AND id = ?');
    foreach ($rows as $row) {
        $payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
        $public = $publisher->publish($row['owner_id'], $row['id'], $payload);
        if ($public !== null) $link->execute([$public, $row['owner_id'], $row['id']]);
    }
};
