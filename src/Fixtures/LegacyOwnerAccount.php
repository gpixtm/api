<?php
declare(strict_types=1);
namespace App\Fixtures;

use App\Infrastructure\Accounts;

/**
 * Explicit, append-only development fixture; installation and deployment never
 * invoke it. It creates (or finds) the one historical-owner account and gives it
 * any library data created before accounts existed (rows without an owner).
 */
final readonly class LegacyOwnerAccount
{
    /** $password only creates a missing account; it never lives in the repository. */
    public function __construct(
        private \PDO $db,
        private string $username,
        private string $email,
        private ?string $password = null,
    ) {}

    public function load(): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->query('SELECT pg_advisory_xact_lock(7140933)');
            $query = $this->db->prepare('SELECT * FROM users WHERE lower(username) = lower(?) OR lower(email) = lower(?) FOR UPDATE');
            $query->execute([$this->username, $this->email]);
            $rows = $query->fetchAll(\PDO::FETCH_ASSOC);
            if (count($rows) > 1 || ($rows && (strtolower($rows[0]['username']) !== strtolower($this->username) || strtolower($rows[0]['email']) !== strtolower($this->email)))) {
                throw new \RuntimeException('Fixture identity conflict; nothing changed.');
            }
            if (!$rows) {
                if ($this->password === null || strlen($this->password) < 8) {
                    throw new \RuntimeException('Set GPIX_FIXTURE_PASSWORD (8+ characters) to create the fixture account; nothing changed.');
                }
                $id = Accounts::uuid();
                $this->db->prepare('INSERT INTO users(id, username, email, password_hash, legacy_owner) VALUES(?, ?, ?, ?, true)')
                    ->execute([$id, $this->username, $this->email, Accounts::hasher()->hash($this->password)]);
            } else {
                if (!$rows[0]['legacy_owner']) {
                    throw new \RuntimeException('Existing non-fixture account: explicit ownership review required; nothing changed.');
                }
                $id = $rows[0]['id'];
            }
            $this->db->prepare('UPDATE trails SET owner_id = ? WHERE owner_id IS NULL')->execute([$id]);
            $this->db->prepare('UPDATE receipts SET owner_id = ? WHERE owner_id IS NULL')->execute([$id]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
