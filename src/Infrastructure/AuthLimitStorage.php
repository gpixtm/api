<?php
declare(strict_types=1);
namespace App\Infrastructure;
use Symfony\Component\RateLimiter\Storage\StorageInterface;
use Symfony\Component\RateLimiter\LimiterStateInterface;
final readonly class AuthLimitStorage implements StorageInterface {
 public function __construct(private \PDO $db) {}
 public function save(LimiterStateInterface $state): void {$this->db->prepare("INSERT INTO auth_limits(id,state,expires_at) VALUES(?,?,now()+interval '1 hour') ON CONFLICT(id) DO UPDATE SET state=excluded.state,expires_at=excluded.expires_at")->execute([$state->getId(),base64_encode(serialize($state))]);}
 public function fetch(string $id): ?LimiterStateInterface {$q=$this->db->prepare('SELECT state FROM auth_limits WHERE id=? AND expires_at>now()');$q->execute([$id]);$v=$q->fetchColumn();if(!$v)return null;$s=unserialize(base64_decode($v),['allowed_classes'=>[\Symfony\Component\RateLimiter\Policy\Window::class]]);return $s instanceof LimiterStateInterface?$s:null;}
 public function delete(string $id): void {$this->db->prepare('DELETE FROM auth_limits WHERE id=?')->execute([$id]);}
}
