<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use App\Infrastructure\Accounts;
use App\Infrastructure\Database;
use Symfony\Component\HttpKernel\Exception\HttpException;
function ok(bool $v,string $label):void {if(!$v)throw new RuntimeException($label);echo "PASS $label\n";}
function rejected(callable $fn,int $status,string $label):void {try{$fn();throw new RuntimeException('Expected rejection: '.$label);}catch(HttpException $e){ok($e->getStatusCode()===$status,$label);}catch(InvalidArgumentException $e){ok($status===422,$label);}}
$db=Database::connect();$a=new Accounts($db);$suffix=bin2hex(random_bytes(6));$id=null;
try {
 $s=$a->register(['username'=>'test_'.$suffix,'email'=>$suffix.'@example.test','password'=>'Testing123']);$id=$s['user']['id'];
 ok($s['user']['legacy_owner']===false,'new account cannot claim legacy library');
 $q=$db->prepare('SELECT password_hash FROM users WHERE id=?');$q->execute([$id]);ok(Accounts::hasher()->verify($q->fetchColumn(),'Testing123'),'stored password is hashed');
 rejected(fn()=>$a->login(['identifier'=>'test_'.$suffix,'password'=>'wrong']),401,'wrong password rejected');
 $email=$a->login(['identifier'=>strtoupper($suffix.'@example.test'),'password'=>'Testing123']);ok($email['user']['id']===$id,'email login case-insensitive');
 $name=$a->login(['identifier'=>strtoupper('test_'.$suffix),'password'=>'Testing123']);ok($name['user']['id']===$id,'username login case-insensitive');
 $refresh=$a->refresh($s['refresh_token']);ok($a->authenticate($refresh['access_token'])['id']===$id,'refresh creates valid access');
 rejected(fn()=>$a->authenticate($s['access_token']),401,'old access invalidated on rotation');
 $retry=$a->refresh($s['refresh_token']);ok($retry['refresh_token']===$s['refresh_token'],'lost refresh response can be retried');$refresh=$retry;
 $db->prepare("UPDATE sessions SET access_expires=now()-interval '1 second' WHERE access_hash=?")->execute([hash('sha256',$refresh['access_token'])]);
 rejected(fn()=>$a->authenticate($refresh['access_token']),401,'access expiry');
 $next=$a->refresh($refresh['refresh_token']);ok($next['user']['id']===$id,'refresh survives access expiry');
 $a->logout($next['refresh_token'],'');rejected(fn()=>$a->refresh($next['refresh_token']),401,'logout revokes refresh');
 $token=bin2hex(random_bytes(32));$db->prepare("INSERT INTO password_resets VALUES(?,?,now()-interval '1 second')")->execute([hash('sha256',$token),$id]);
 rejected(fn()=>$a->reset($token,'Testing456'),422,'expired reset rejected');
 $db->prepare("UPDATE password_resets SET expires_at=now()+interval '30 minutes' WHERE user_id=?")->execute([$id]);$a->reset($token,'Testing456');
 rejected(fn()=>$a->reset($token,'Testing789'),422,'reset single-use');rejected(fn()=>$a->authenticate($email['access_token']),401,'reset invalidates other sessions');
 $s=$a->login(['identifier'=>'test_'.$suffix,'password'=>'Testing456']);$a->change($id,'Testing456','Testing789');rejected(fn()=>$a->authenticate($s['access_token']),401,'password change revokes sessions');
 $key='isolated-'.$suffix;for($i=0;$i<3;$i++)$a->throttle('test',$key,3);rejected(fn()=>$a->throttle('test',$key,3),429,'persistent rate limit');
 $dsn=getenv('MAILER_DSN');putenv('MAILER_DSN=');
 try{rejected(fn()=>$a->forgot($suffix.'@example.test'),503,'known account unavailable mail');rejected(fn()=>$a->forgot('absent-'.$suffix.'@example.test'),503,'unknown account identical unavailable mail');}finally{putenv('MAILER_DSN='.$dsn);}
}finally{if($id)$db->prepare('DELETE FROM users WHERE id=?')->execute([$id]);$db->prepare("DELETE FROM auth_limits WHERE id LIKE 'test-%'")->execute();}
