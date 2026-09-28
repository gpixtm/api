<?php
declare(strict_types=1);
namespace App\Infrastructure;
use App\Domain\WalkContribution;

/**
 * Running totals per followed GPX. Each mutation adjusts the totals by the difference
 * of one walk's contribution, so averages are never recomputed from all walks.
 */
final readonly class PostgresTrailStatistics
{
    public function __construct(private \PDO $db, private OwnerContext $owner) {}

    /** Must run inside the caller's mutation transaction, after ownership was checked. */
    public function record(string $owner, string $walkId, ?WalkContribution $contribution): void
    {
        $previous = $this->db->prepare('DELETE FROM trail_statistic_walks WHERE owner_id=? AND walk_id=? RETURNING trail_id, metres, seconds');
        $previous->execute([$owner, $walkId]);
        if ($old = $previous->fetch(\PDO::FETCH_ASSOC)) {
            $this->db->prepare('DELETE FROM trail_statistics WHERE owner_id=? AND trail_id=? AND walks<=1')->execute([$owner, $old['trail_id']]);
            $this->db->prepare('UPDATE trail_statistics SET walks=walks-1, metres=metres-?, seconds=seconds-?, updated_at=now() WHERE owner_id=? AND trail_id=?')
                ->execute([$old['metres'], $old['seconds'], $owner, $old['trail_id']]);
        }
        if ($contribution === null) return;
        $this->db->prepare('INSERT INTO trail_statistic_walks(owner_id,walk_id,trail_id,metres,seconds) VALUES (?,?,?,?,?)')
            ->execute([$owner, $walkId, $contribution->trailId, $contribution->metres, $contribution->seconds]);
        $this->db->prepare('INSERT INTO trail_statistics(owner_id,trail_id,walks,metres,seconds) VALUES (?,?,1,?,?) ON CONFLICT (owner_id,trail_id) DO UPDATE SET walks=trail_statistics.walks+1, metres=trail_statistics.metres+excluded.metres, seconds=trail_statistics.seconds+excluded.seconds, updated_at=now()')
            ->execute([$owner, $contribution->trailId, $contribution->metres, $contribution->seconds]);
    }

    public function all(): array
    {
        $q = $this->db->prepare('SELECT trail_id, walks, metres, seconds FROM trail_statistics WHERE owner_id=? ORDER BY trail_id');
        $q->execute([$this->owner->requireId()]);
        $result = [];
        foreach ($q as $row) {
            $result[] = ['trailId' => $row['trail_id'], 'walks' => (int)$row['walks'], 'metres' => round((float)$row['metres'], 1), 'seconds' => (int)$row['seconds']];
        }
        return $result;
    }
}
