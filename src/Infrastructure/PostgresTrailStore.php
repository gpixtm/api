<?php
declare(strict_types=1);
namespace App\Infrastructure;
use App\Domain\Mutation;
use App\Domain\TrailStore;
use App\Domain\WalkContribution;

final readonly class PostgresTrailStore implements TrailStore
{
    public function __construct(private \PDO $db, private OwnerContext $owner, private PostgresTrailStatistics $statistics) {}
    public function apply(Mutation $m): array
    {
        $owner=$this->owner->requireId();
        $digest = hash('sha256', json_encode([$m->id, $m->baseRevision, $m->deleted, $m->payload], JSON_THROW_ON_ERROR));
        $this->db->beginTransaction();
        try {
            // Personal server: one short mutation lock also protects operation-id reuse across entities.
            $this->db->query('SELECT pg_advisory_xact_lock(7140931)');
            $receipt = $this->db->prepare('SELECT digest, response FROM receipts WHERE operation_id = ? AND owner_id = ?');
            $receipt->execute([$m->operationId,$owner]);
            if ($row = $receipt->fetch(\PDO::FETCH_ASSOC)) {
                if (!hash_equals($row['digest'], $digest)) throw new \InvalidArgumentException('Operation identity reused with different content');
                $this->db->commit();
                return json_decode($row['response'], true, 512, JSON_THROW_ON_ERROR);
            }
            $query = $this->db->prepare('SELECT revision FROM trails WHERE id = ? FOR UPDATE');
            $query->execute([$m->id]);
            $revision = (int)($query->fetchColumn() ?: 0);
            $ownership=$this->db->prepare('SELECT owner_id FROM trails WHERE id=?');$ownership->execute([$m->id]);$existing=$ownership->fetch(\PDO::FETCH_ASSOC);
            if($existing && $existing['owner_id']!==$owner)throw new \Symfony\Component\HttpKernel\Exception\HttpException(404,'Trace introuvable.');
            if ($revision !== $m->baseRevision) {
                $this->db->rollBack();
                return ['conflict' => true, 'revision' => $revision];
            }
            ++$revision;
            $stmt = $this->db->prepare('INSERT INTO trails (id,payload,revision,deleted,mutation,owner_id) VALUES (?,?::jsonb,?,?::boolean,?,?) ON CONFLICT (id) DO UPDATE SET payload=excluded.payload, revision=excluded.revision, deleted=excluded.deleted, mutation=excluded.mutation');
            $stmt->execute([$m->id, json_encode($m->payload, JSON_THROW_ON_ERROR), $revision, $m->deleted ? 'true' : 'false', $m->operationId,$owner]);
            $this->statistics->record($owner, $m->id, WalkContribution::fromPayload($m->payload, $m->deleted));
            $response = ['conflict' => false, 'revision' => $revision];
            $this->db->prepare('INSERT INTO receipts (operation_id,digest,response,owner_id) VALUES (?,?,?::jsonb,?)')->execute([$m->operationId, $digest, json_encode($response, JSON_THROW_ON_ERROR),$owner]);
            $this->db->commit();
            return $response;
        } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
    public function all(): array
    {
        $result = [];
        $q=$this->db->prepare('SELECT id,payload,revision,deleted,mutation FROM trails WHERE owner_id=? ORDER BY id');$q->execute([$this->owner->requireId()]);
        foreach ($q as $row) {
            $result[] = ['id' => $row['id'], 'payload' => json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR),
                'revision' => (int)$row['revision'], 'deleted' => (bool)$row['deleted'], 'mutation' => $row['mutation']];
        }
        return $result;
    }
}
