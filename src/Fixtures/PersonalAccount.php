<?php
declare(strict_types=1);
namespace App\Fixtures;
use App\Infrastructure\Accounts;
// Explicit, append-only fixture; production installation never invokes it.
final readonly class PersonalAccount {
 public function __construct(private \PDO $db) {}
 public function load(): void {
  $this->db->beginTransaction();try {
   $this->db->query('SELECT pg_advisory_xact_lock(7140933)');
   $q=$this->db->query("SELECT * FROM users WHERE lower(username)='walker' OR lower(email)='walker@example.test' FOR UPDATE");$rows=$q->fetchAll(\PDO::FETCH_ASSOC);
   if(count($rows)>1 || ($rows && (strtolower($rows[0]['username'])!=='walker'||strtolower($rows[0]['email'])!=='walker@example.test')))throw new \RuntimeException('Fixture identity conflict; nothing changed.');
   if(!$rows){$id=Accounts::uuid();$this->db->prepare('INSERT INTO users(id,username,email,password_hash,legacy_owner) VALUES(?,?,?,?,true)')->execute([$id,'walker','walker@example.test',Accounts::hasher()->hash('REDACTED-FIXTURE-PASSWORD')]);}
   else {if(!$rows[0]['legacy_owner'])throw new \RuntimeException('Existing non-fixture account: explicit ownership review required; nothing changed.');$id=$rows[0]['id'];}
   $this->db->prepare('UPDATE trails SET owner_id=? WHERE owner_id IS NULL')->execute([$id]);
   $this->db->prepare('UPDATE receipts SET owner_id=? WHERE owner_id IS NULL')->execute([$id]);
   $this->db->commit();
  }catch(\Throwable $e){$this->db->rollBack();throw $e;}
 }
}
