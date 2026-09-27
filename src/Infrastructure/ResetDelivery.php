<?php
declare(strict_types=1);
namespace App\Infrastructure;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
final readonly class ResetDelivery {
 public function __construct(private \PDO $db){}
 public static function configured(): bool {
  $dsn=getenv('MAILER_DSN')?:'';
  return (str_starts_with($dsn,'smtp://')||str_starts_with($dsn,'smtps://')) && filter_var(getenv('MAIL_FROM')?:'',FILTER_VALIDATE_EMAIL)!==false && strlen(getenv('APP_SECRET')?:'')>=32;
 }
 private static function key(): string {return hash('sha256',getenv('APP_SECRET'),true);}
 public function enqueue(string $userId,string $email,string $token): void {
  $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
  $payload=base64_encode($nonce.sodium_crypto_secretbox(json_encode([$email,$token],JSON_THROW_ON_ERROR),$nonce,self::key()));
  $this->db->prepare("INSERT INTO mail_outbox(user_id,encrypted_payload,expires_at) VALUES(?,?,now()+interval '30 minutes')")->execute([$userId,$payload]);
 }
 public function deliver(): int {
  if(!self::configured())return 0;
  $this->db->beginTransaction();try {
   $q=$this->db->query('SELECT * FROM mail_outbox WHERE delivered_at IS NULL AND expires_at>now() AND next_attempt<=now() ORDER BY id FOR UPDATE SKIP LOCKED LIMIT 1');$r=$q->fetch(\PDO::FETCH_ASSOC);
   if(!$r){$this->db->commit();return 0;}
   try {
    $raw=base64_decode($r['encrypted_payload']);$plain=sodium_crypto_secretbox_open(substr($raw,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),substr($raw,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),self::key());
    if($plain===false)throw new \RuntimeException('Invalid encrypted mail');
    [$email,$token]=json_decode($plain,true,512,JSON_THROW_ON_ERROR);
    $transport=Transport::fromDsn(getenv('MAILER_DSN'));
    (new Mailer($transport))->send((new Email())->from(getenv('MAIL_FROM'))->to($email)->subject('Gpix — réinitialiser votre mot de passe')->text("Dans Gpix, ouvrez Mot de passe oublié puis J’ai un code. Code (valable 30 minutes après demande) :\n$token\n\nLien : gpix://reset-password?token=$token\nIgnorez ce message si vous n’avez rien demandé."));
    $this->db->prepare("UPDATE mail_outbox SET delivered_at=now(),encrypted_payload='' WHERE id=?")->execute([$r['id']]);
   }catch(\Throwable $e){$this->db->prepare("UPDATE mail_outbox SET attempts=attempts+1,next_attempt=now()+interval '30 seconds' WHERE id=?")->execute([$r['id']]);fwrite(STDERR,"Reset mail remains queued: delivery unavailable.\n");}
   $this->db->commit();return 1;
  }catch(\Throwable $e){$this->db->rollBack();throw $e;}
 }
}
