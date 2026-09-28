<?php
declare(strict_types=1);

use App\Domain\TrailFingerprint;
use App\Domain\TrailOutline;
use App\Infrastructure\Accounts;

/**
 * Publishes every trail already in an owned library (accepted decision of 28 September 2026:
 * existing trails become shared). Walks, planned days and statistics stay private. The legacy
 * owner's copies come first, then older accounts, so a line added twice keeps its first name.
 *
 * The insert is frozen at the schema of migration 004: later migrations add columns that
 * PostgresPublicTrails::publish() writes, which do not exist yet when this migration runs.
 */
return static function (PDO $db): void {
    $idFor = function (string $fingerprint) use ($db): ?string {
        $q = $db->prepare('SELECT id FROM public_trails WHERE fingerprint = ?');
        $q->execute([$fingerprint]);
        return $q->fetchColumn() ?: null;
    };
    $publish = function (string $author, string $privateId, array $payload) use ($db, $idFor): ?string {
        $fingerprint = TrailFingerprint::of($payload['segments'] ?? []);
        if ($fingerprint === null) return null;
        if (($existing = $idFor($fingerprint)) !== null) return $existing;
        $outline = TrailOutline::of($payload['segments']);
        $id = $privateId;
        foreach ([$privateId, TrailFingerprint::publicId($fingerprint), Accounts::uuid()] as $candidate) {
            $taken = $db->prepare('SELECT 1 FROM public_trails WHERE id = ?');
            $taken->execute([$candidate]);
            if (!$taken->fetchColumn()) { $id = $candidate; break; }
        }
        $db->prepare('INSERT INTO public_trails (id, fingerprint, name, description, segments, pois, estimated, outline, metres, south, west, north, east, author_id)
            VALUES (?, ?, ?, ?, ?::jsonb, ?::jsonb, ?::boolean, ?::jsonb, ?, ?, ?, ?, ?, ?) ON CONFLICT (fingerprint) DO NOTHING')
            ->execute([$id, $fingerprint, $payload['name'], $payload['description'] ?? '', json_encode($payload['segments'], JSON_THROW_ON_ERROR),
                json_encode($payload['pois'] ?? [], JSON_THROW_ON_ERROR), ($payload['estimated'] ?? false) ? 'true' : 'false',
                json_encode($outline->lines, JSON_THROW_ON_ERROR), $outline->metres, $outline->south, $outline->west, $outline->north, $outline->east, $author]);
        return $idFor($fingerprint);
    };
    $rows = $db->query("SELECT t.id, t.owner_id, t.payload FROM trails t JOIN users u ON u.id = t.owner_id
        WHERE NOT t.deleted AND jsonb_typeof(t.payload->'walk') IS DISTINCT FROM 'object'
        ORDER BY u.legacy_owner DESC, u.created_at, t.id")->fetchAll(PDO::FETCH_ASSOC);
    $link = $db->prepare('UPDATE trails SET public_id = ? WHERE owner_id = ? AND id = ?');
    foreach ($rows as $row) {
        $payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
        $public = $publish($row['owner_id'], $row['id'], $payload);
        if ($public !== null) $link->execute([$public, $row['owner_id'], $row['id']]);
    }
};
