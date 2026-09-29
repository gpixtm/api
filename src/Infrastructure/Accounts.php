<?php
declare(strict_types=1);
namespace App\Infrastructure;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\HttpKernel\Exception\HttpException;
final readonly class Accounts implements \App\Domain\Authentication {
 public function __construct(private \PDO $db) {}
 public static function uuid(): string {$h=bin2hex(random_bytes(16));return substr($h,0,8).'-'.substr($h,8,4).'-4'.substr($h,13,3).'-a'.substr($h,17,3).'-'.substr($h,20);}
 public static function hasher(): \Symfony\Component\PasswordHasher\PasswordHasherInterface {return (new PasswordHasherFactory(['user'=>['algorithm'=>'auto']]))->getPasswordHasher('user');}
 public static function password(string $v): void {\App\Domain\AccountRules::password($v);}
 public static function user(array $r): array {return ['id'=>$r['id'],'username'=>$r['username'],'email'=>$r['email'],'legacy_owner'=>(bool)$r['legacy_owner']];}
 public function throttle(string $kind,string $key,int $limit): void {
  $this->db->beginTransaction();try {
   $this->db->prepare('SELECT pg_advisory_xact_lock(hashtext(?))')->execute(['rate:'.$kind.':'.$key]);
   $f=new RateLimiterFactory(['id'=>$kind,'policy'=>'fixed_window','limit'=>$limit,'interval'=>'15 minutes'],new AuthLimitStorage($this->db));
   $ok=$f->create(hash('sha256',$key))->consume()->isAccepted();$this->db->commit();
   if(!$ok)throw new HttpException(429,'Too many attempts. Try again in 15 minutes.');
  }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
 public function register(array $v): array {
  $name=\App\Domain\AccountRules::username((string)($v['username']??''));$email=\App\Domain\AccountRules::email((string)($v['email']??''));$password=(string)($v['password']??'');
  self::password($password);
  $id=self::uuid();try{$this->db->prepare('INSERT INTO users(id,username,email,password_hash) VALUES(?,?,?,?)')->execute([$id,$name,$email,self::hasher()->hash($password)]);}catch(\PDOException $e){if($e->getCode()==='23505')throw new \InvalidArgumentException('Username or email already in use.');throw $e;}
  return $this->issue($this->find($id));
 }
 private function find(string $id): array {$q=$this->db->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch(\PDO::FETCH_ASSOC)?:throw new HttpException(401,'Invalid session. Please sign in again.');}
 public function login(array $v): array {
  $identifier=strtolower(trim((string)($v['identifier']??'')));$password=(string)($v['password']??'');$this->throttle('identifier',$identifier,10);
  $this->db->beginTransaction();try {
   $q=$this->db->prepare('SELECT * FROM users WHERE lower(username)=? OR lower(email)=? FOR UPDATE');$q->execute([$identifier,$identifier]);$u=$q->fetch(\PDO::FETCH_ASSOC);
   // Unknown users are checked against a throwaway hash so timing does not reveal which accounts exist.
   $hash=$u['password_hash']??'$2y$13$0JrIJC1WKi4AT8ZIhXCZ0uDUiREfyASlFBOA8sbKsUaSJIA9pHOeS';
   if(!self::hasher()->verify($hash,$password)||!$u)throw new HttpException(401,'Incorrect username or password.');
   $r=$this->issue($u);$this->db->commit();return $r;
  }catch(\Throwable $e){$this->db->rollBack();throw $e;}
 }
 private function issue(array $u,?string $old=null): array {
  $access=bin2hex(random_bytes(32));$refresh=bin2hex(random_bytes(32));$expiry=gmdate('c',time()+900);
  if($old)$this->db->prepare('DELETE FROM sessions WHERE id=?')->execute([$old]);
  $this->db->prepare("INSERT INTO sessions(id,user_id,access_hash,refresh_hash,access_expires,refresh_expires) VALUES(?,?,?,?,?,now()+interval '30 days')")->execute([self::uuid(),$u['id'],hash('sha256',$access),hash('sha256',$refresh),$expiry]);
  return ['user'=>self::user($u),'access_token'=>$access,'refresh_token'=>$refresh,'expires_at'=>$expiry];
 }
 public function authenticate(string $token): array {$q=$this->db->prepare('SELECT u.* FROM users u JOIN sessions s ON s.user_id=u.id WHERE s.access_hash=? AND s.access_expires>now() AND s.refresh_expires>now()');$q->execute([hash('sha256',$token)]);return self::user($q->fetch(\PDO::FETCH_ASSOC)?:throw new HttpException(401,'Session expired. Please sign in again.'));}
 public function refresh(string $token): array {
  $this->db->beginTransaction();try {
   $q=$this->db->prepare('SELECT u.* FROM users u JOIN sessions s ON u.id=s.user_id WHERE s.refresh_hash=? FOR UPDATE OF u');$q->execute([hash('sha256',$token)]);$u=$q->fetch(\PDO::FETCH_ASSOC);
   $q=$this->db->prepare('SELECT id FROM sessions WHERE refresh_hash=? AND refresh_expires>now() FOR UPDATE');$q->execute([hash('sha256',$token)]);$id=$q->fetchColumn();
   if(!$u||!$id)throw new HttpException(401,'Session expired. Please sign in again.');
   $access=bin2hex(random_bytes(32));$expiry=gmdate('c',time()+900);
   $this->db->prepare('UPDATE sessions SET access_hash=?,access_expires=? WHERE id=?')->execute([hash('sha256',$access),$expiry,$id]);
   $r=['user'=>self::user($u),'access_token'=>$access,'refresh_token'=>$token,'expires_at'=>$expiry];$this->db->commit();return $r;
  }catch(\Throwable $e){$this->db->rollBack();throw $e;}
 }
 public function logout(string $refresh,string $access): void {
  $this->db->beginTransaction();try {
   $q=$this->db->prepare('SELECT u.id FROM users u JOIN sessions s ON s.user_id=u.id WHERE s.refresh_hash=? OR s.access_hash=? FOR UPDATE OF u');$q->execute([hash('sha256',$refresh),hash('sha256',$access)]);$q->fetchAll();
   $this->db->prepare('DELETE FROM sessions WHERE refresh_hash=? OR access_hash=?')->execute([hash('sha256',$refresh),hash('sha256',$access)]);$this->db->commit();
  }catch(\Throwable $e){$this->db->rollBack();throw $e;}
 }
 public function forgot(string $email, string $language = 'en'): void {
  $email=\App\Domain\AccountRules::email($email);$this->throttle('reset-address',$email,4);
  if(!ResetDelivery::configured())throw new HttpException(503,'Email delivery unavailable. Try again later.');
  $this->db->beginTransaction();try {
   $q=$this->db->prepare('SELECT id FROM users WHERE lower(email)=? FOR UPDATE');$q->execute([$email]);$id=$q->fetchColumn();
   if($id){$token=bin2hex(random_bytes(32));$this->db->prepare("INSERT INTO password_resets(token_hash,user_id,expires_at) VALUES(?,?,now()+interval '30 minutes')")->execute([hash('sha256',$token),$id]);(new ResetDelivery($this->db))->enqueue($id,$email,$token,$language);}
   $this->db->commit();
  }catch(\Throwable $e){$this->db->rollBack();throw $e;}
 }
 public function reset(string $token,string $password): void {
  self::password($password);$this->db->beginTransaction();try {
   $q=$this->db->prepare('SELECT u.id FROM users u JOIN password_resets r ON r.user_id=u.id WHERE r.token_hash=? FOR UPDATE OF u');$q->execute([hash('sha256',$token)]);$id=$q->fetchColumn();
   $q=$this->db->prepare('SELECT user_id FROM password_resets WHERE token_hash=? AND expires_at>now() FOR UPDATE');$q->execute([hash('sha256',$token)]);
   if(!$id||!$q->fetchColumn())throw new \InvalidArgumentException('Invalid or expired code. Request a new code.');
   $this->replacePassword($id,$password);$this->db->commit();
  }catch(\Throwable $e){$this->db->rollBack();throw $e;}
 }
 private function replacePassword(string $id,string $password): void {$this->db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([self::hasher()->hash($password),$id]);$this->db->prepare('DELETE FROM sessions WHERE user_id=?')->execute([$id]);$this->db->prepare('DELETE FROM password_resets WHERE user_id=?')->execute([$id]);$this->db->prepare('DELETE FROM mail_outbox WHERE user_id=?')->execute([$id]);}
 public function change(string $id,string $current,string $password): void {self::password($password);$this->db->beginTransaction();try{$q=$this->db->prepare('SELECT password_hash FROM users WHERE id=? FOR UPDATE');$q->execute([$id]);if(!self::hasher()->verify($q->fetchColumn(),$current))throw new \InvalidArgumentException('Incorrect current password.');$this->replacePassword($id,$password);$this->db->commit();}catch(\Throwable $e){$this->db->rollBack();throw $e;}}
}
