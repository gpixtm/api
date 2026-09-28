<?php
declare(strict_types=1);

use App\Infrastructure\PostgresPublicTrails;

/**
 * Makes the trails walkers shared before the catalogue searchable by name. The folding is
 * PHP's, the same one queries use. Nothing else changes, so phones see no new change.
 */
return static function (PDO $db): void {
    $update = $db->prepare('UPDATE public_trails SET search_text = ? WHERE id = ?');
    foreach ($db->query("SELECT id, name FROM public_trails WHERE search_text = ''")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $update->execute([PostgresPublicTrails::searchText($row['name']), $row['id']]);
    }
};
