<?php
declare(strict_types=1);
namespace App\Infrastructure\Catalogue;

/**
 * The acquisition queue. An operator queues a country; the worker claims one run at a time,
 * so a run survives the operator's session and never overlaps another. Countries acquired
 * once are queued again every night.
 */
final readonly class CatalogueRuns
{
    public const NIGHTLY_HOUR = 2;

    public function __construct(private \PDO $db) {}

    /** Queues a country unless it is already waiting or running; returns the run identifier. */
    public function queue(string $country, string $source = CatalogueWriter::SOURCE): int
    {
        $country = strtoupper($country);
        if (!preg_match('/^[A-Z]{2}$/D', $country)) throw new \InvalidArgumentException('Country must be an ISO 3166-1 alpha-2 code, such as FR.');
        $this->db->beginTransaction();
        try {
            $this->db->query("SELECT pg_advisory_xact_lock(hashtext('catalogue-runs'))");
            $existing = $this->db->prepare("SELECT id FROM catalogue_runs WHERE country = ? AND source = ? AND status IN ('queued', 'running') ORDER BY id LIMIT 1");
            $existing->execute([$country, $source]);
            $id = $existing->fetchColumn();
            if ($id === false) {
                $insert = $this->db->prepare("INSERT INTO catalogue_runs (country, source, status) VALUES (?, ?, 'queued') RETURNING id");
                $insert->execute([$country, $source]);
                $id = $insert->fetchColumn();
            }
            $this->db->commit();
            return (int)$id;
        } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }

    /** @return array{id:int, country:string, source:string}|null the oldest queued run, now running */
    public function claim(): ?array
    {
        $q = $this->db->query("UPDATE catalogue_runs SET status = 'running', started_at = now() WHERE id = (
                SELECT id FROM catalogue_runs WHERE status = 'queued' ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED)
            RETURNING id, country, source");
        $row = $q->fetch(\PDO::FETCH_ASSOC);
        return $row ? ['id' => (int)$row['id'], 'country' => $row['country'], 'source' => $row['source']] : null;
    }

    /** Creates a run that starts immediately, for a synchronous acquisition. */
    public function start(string $country, string $source = CatalogueWriter::SOURCE): int
    {
        $q = $this->db->prepare("INSERT INTO catalogue_runs (country, source, status, started_at) VALUES (?, ?, 'running', now()) RETURNING id");
        $q->execute([strtoupper($country), $source]);
        return (int)$q->fetchColumn();
    }

    public function succeed(int $id, array $stats): void
    {
        $this->db->prepare("UPDATE catalogue_runs SET status = 'succeeded', finished_at = now(), stats = ?::jsonb WHERE id = ?")
            ->execute([json_encode($stats, JSON_THROW_ON_ERROR), $id]);
    }

    public function fail(int $id, string $error, array $stats = []): void
    {
        $this->db->prepare("UPDATE catalogue_runs SET status = 'failed', finished_at = now(), error = ?, stats = ?::jsonb WHERE id = ?")
            ->execute([mb_substr($error, 0, 4000), json_encode($stats, JSON_THROW_ON_ERROR), $id]);
    }

    /** Runs left running by a stopped worker failed; the next night retries them. */
    public function recover(): int
    {
        return $this->db->exec("UPDATE catalogue_runs SET status = 'failed', finished_at = now(), error = 'Interrupted: the worker stopped during the run.' WHERE status = 'running'") ?: 0;
    }

    /** Queues every country acquired before whose last run started more than 20 hours ago. */
    public function nightly(\DateTimeImmutable $now): array
    {
        if ((int)$now->setTimezone(new \DateTimeZone('UTC'))->format('G') < self::NIGHTLY_HOUR) return [];
        $due = $this->db->query("SELECT country, source FROM catalogue_runs GROUP BY country, source
            HAVING bool_or(status = 'succeeded') AND NOT bool_or(status IN ('queued', 'running'))
               AND max(COALESCE(started_at, queued_at)) < now() - interval '20 hours'")->fetchAll(\PDO::FETCH_NUM);
        return array_map(fn(array $r) => $this->queue($r[0], $r[1]), $due);
    }

    /** Hides what a run created; its updates to older entries stay. */
    public function rollback(int $id): array
    {
        $this->db->beginTransaction();
        try {
            $this->db->query('SELECT pg_advisory_xact_lock('.\App\Infrastructure\PostgresPublicTrails::LOCK.')');
            $trails = $this->db->prepare("UPDATE public_trails SET hidden = true, change = nextval('public_trail_changes') WHERE created_run_id = ? AND NOT hidden");
            $trails->execute([$id]);
            $groups = $this->db->prepare("UPDATE trail_groups SET hidden = true, change = nextval('trail_group_changes') WHERE created_run_id = ? AND NOT hidden");
            $groups->execute([$id]);
            $this->db->prepare("UPDATE catalogue_runs SET status = 'rolled_back' WHERE id = ?")->execute([$id]);
            $this->db->commit();
            return ['trails' => $trails->rowCount(), 'groups' => $groups->rowCount()];
        } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }

    /** @return list<array> the latest runs, newest first */
    public function latest(int $limit = 20): array
    {
        $q = $this->db->prepare("SELECT id, country, source, status, to_char(queued_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') AS queued,
                to_char(finished_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') AS finished, stats, error FROM catalogue_runs ORDER BY id DESC LIMIT ?");
        $q->execute([$limit]);
        return $q->fetchAll(\PDO::FETCH_ASSOC);
    }
}
