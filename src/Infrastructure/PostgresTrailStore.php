<?php
declare(strict_types=1);
namespace App\Infrastructure;
use App\Domain\Mutation;
use App\Domain\TrailStore;
use App\Domain\WalkContribution;

final readonly class PostgresTrailStore implements TrailStore
{
    public function __construct(private \PDO $db, private OwnerContext $owner, private PostgresTrailStatistics $statistics, private PostgresPublicTrails $public) {}
    public function apply(Mutation $m): array
    {
        $owner=$this->owner->requireId();
        $digest = hash('sha256', json_encode([$m->id, $m->baseRevision, $m->deleted, $m->payload], JSON_THROW_ON_ERROR));
        $this->db->beginTransaction();
        try {
            // One short mutation lock also protects operation-id reuse and orders public catalogue changes.
            $this->db->query('SELECT pg_advisory_xact_lock('.PostgresPublicTrails::LOCK.')');
            $receipt = $this->db->prepare('SELECT digest, response FROM receipts WHERE operation_id = ? AND owner_id = ?');
            $receipt->execute([$m->operationId,$owner]);
            if ($row = $receipt->fetch(\PDO::FETCH_ASSOC)) {
                if (!hash_equals($row['digest'], $digest)) throw new \InvalidArgumentException('Operation identity reused with different content');
                $this->db->commit();
                return json_decode($row['response'], true, 512, JSON_THROW_ON_ERROR);
            }
            // Identifiers are per owner: walkers adding the same line derive the same identifier.
            $query = $this->db->prepare('SELECT revision, public_id FROM trails WHERE owner_id = ? AND id = ? FOR UPDATE');
            $query->execute([$owner, $m->id]);
            $current = $query->fetch(\PDO::FETCH_ASSOC) ?: ['revision' => 0, 'public_id' => null];
            $revision = (int)$current['revision'];
            if ($revision !== $m->baseRevision) {
                $this->db->rollBack();
                return ['conflict' => true, 'revision' => $revision];
            }
            ++$revision;
            // A library trail is shared as soon as it syncs; deleting a private copy never unpublishes it.
            $publicId = $current['public_id'];
            if (!$m->deleted && !is_array($m->payload['walk'] ?? null)) {
                $publicId = $this->public->publish($owner, $m->id, $m->payload) ?? $publicId;
            }
            $stmt = $this->db->prepare('INSERT INTO trails (id,payload,revision,deleted,mutation,owner_id,public_id) VALUES (?,?::jsonb,?,?::boolean,?,?,?) ON CONFLICT (owner_id,id) DO UPDATE SET payload=excluded.payload, revision=excluded.revision, deleted=excluded.deleted, mutation=excluded.mutation, public_id=excluded.public_id');
            $stmt->execute([$m->id, json_encode($m->payload, JSON_THROW_ON_ERROR), $revision, $m->deleted ? 'true' : 'false', $m->operationId,$owner,$publicId]);
            $this->statistics->record($owner, $m->id, WalkContribution::fromPayload($m->payload, $m->deleted));
            $response = ['conflict' => false, 'revision' => $revision];
            if ($publicId !== null) $response['publicId'] = $publicId;
            $this->db->prepare('INSERT INTO receipts (operation_id,digest,response,owner_id) VALUES (?,?,?::jsonb,?)')->execute([$m->operationId, $digest, json_encode($response, JSON_THROW_ON_ERROR),$owner]);
            $this->db->commit();
            return $response;
        } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
    public function all(): array
    {
        $result = [];
        $q=$this->db->prepare('SELECT id,payload,revision,deleted,mutation,public_id FROM trails WHERE owner_id=? ORDER BY id');$q->execute([$this->owner->requireId()]);
        foreach ($q as $row) {
            $entry = ['id' => $row['id'], 'payload' => json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR),
                'revision' => (int)$row['revision'], 'deleted' => (bool)$row['deleted'], 'mutation' => $row['mutation']];
            if ($row['public_id'] !== null) $entry['publicId'] = $row['public_id'];
            $result[] = $entry;
        }
        return $result;
    }
}
