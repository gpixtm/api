<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use App\Domain\WalkContribution;

/**
 * Upgrade check for migration 003: a separate disposable database at schema 002 with
 * representative existing walks, then the SQL backfill compared with the PHP rule.
 */
function check(bool $condition, string $label): void { if (!$condition) throw new RuntimeException($label); echo "PASS $label\n"; }
function uuid(): string { $h=bin2hex(random_bytes(16)); return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20); }
$connect=fn(string $name)=>new PDO('pgsql:host='.getenv('DB_HOST').';port=5432;dbname='.$name, 'gpix', getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='gpix_upgrade_'.bin2hex(random_bytes(4));
$server=$connect('gpix');
$server->exec("CREATE DATABASE $name");
try {
    $db=$connect($name);
    foreach (['001_library.sql','002_accounts.sql'] as $file) $db->exec(file_get_contents(dirname(__DIR__).'/migrations/'.$file));
    $owner=uuid(); $other=uuid(); $trail=uuid(); $second=uuid();
    $db->prepare("INSERT INTO users(id,username,email,password_hash) VALUES (?,?,?,'x'),(?,?,?,'x')")->execute([$owner,'upgrade_a','a@example.test',$other,'upgrade_b','b@example.test']);
    $walk=fn(array $segments, int $seconds, mixed $source, ?string $ended='2026-09-01T10:00:00.000Z')=>['id'=>uuid(),'name'=>'Été 🥾','segments'=>$segments,'pois'=>[],
        'walk'=>['started'=>'2026-09-01T08:00:00.000Z','ended'=>$ended,'seconds'=>$seconds,'sourceTrailId'=>$source,'samples'=>[]]];
    $rows=[
        [$owner, $walk([[[43.0,-1.0,null],[43.004,-1.0,null]],[[44.0,2.0,120],[44.003,2.002,130],[44.006,2.0,null]]],1500,$trail), false],
        [$owner, $walk([[[43.0,-1.0,null],[43.009,-1.0,null]]],700,$trail), false],
        [$owner, $walk([[[43.0,-1.0,null],[43.009,-1.0,null]]],800,$second), false],
        [$other, $walk([[[43.0,-1.0,null],[43.009,-1.0,null]]],900,$trail), false],
        [$owner, $walk([[[43.0,-1.0,null],[43.002,-1.0,null]]],900,$trail), false],   // too short
        [$owner, $walk([[[43.0,-1.0,null],[43.009,-1.0,null]]],30,$trail), false],    // too brief
        [$owner, $walk([[[43.0,-1.0,null],[43.009,-1.0,null]]],900,null), false],     // free walk
        [$owner, $walk([[[43.0,-1.0,null],[43.009,-1.0,null]]],900,$trail), true],    // deleted
        [$owner, $walk([[[43.0,-1.0,null],[43.009,-1.0,null]]],900,str_repeat('-',36)), false], // malformed legacy source
        [$owner, ['id'=>$trail,'name'=>'GPX','segments'=>[[[43.0,-1.0,null],[43.01,-1.0,null]]],'pois'=>[]], false],
        [null, $walk([[[43.0,-1.0,null],[43.009,-1.0,null]]],900,$trail), false],     // unowned legacy data
        [$owner, array_replace_recursive($walk([[[43.0,-1.0,null],[43.009,-1.0,null]]],650,null), ['walk'=>['routeId'=>$second]]), false], // free walk linked by a newer app
    ];
    $insert=$db->prepare('INSERT INTO trails(id,payload,revision,deleted,mutation,owner_id) VALUES (?,?::jsonb,1,?::boolean,?,?)');
    $expected=[];
    foreach ($rows as [$user,$payload,$deleted]) {
        $insert->execute([$payload['id'],json_encode($payload,JSON_THROW_ON_ERROR),$deleted?'true':'false',uuid(),$user]);
        $c=$user===null?null:WalkContribution::fromPayload(json_decode(json_encode($payload),true),$deleted);
        if ($c) {
            $key=$user.'/'.$c->trailId;
            $expected[$key]??=['walks'=>0,'metres'=>0.0,'seconds'=>0];
            $expected[$key]['walks']++; $expected[$key]['metres']+=$c->metres; $expected[$key]['seconds']+=$c->seconds;
        }
    }
    $db->beginTransaction();
    $db->exec(file_get_contents(dirname(__DIR__).'/migrations/003_trail_statistics.sql'));
    $db->commit();
    $actual=[];
    foreach ($db->query('SELECT owner_id,trail_id,walks,metres,seconds FROM trail_statistics') as $r) {
        $actual[$r['owner_id'].'/'.$r['trail_id']]=['walks'=>(int)$r['walks'],'metres'=>(float)$r['metres'],'seconds'=>(int)$r['seconds']];
    }
    ksort($expected); ksort($actual);
    check(array_keys($actual)===array_keys($expected) && count($actual)===3,'backfill keeps only eligible owned walks, per owner and GPX');
    foreach ($expected as $key=>$value) {
        check($actual[$key]['walks']===$value['walks'] && $actual[$key]['seconds']===$value['seconds'] && abs($actual[$key]['metres']-$value['metres'])<0.01,"backfill equals the PHP running rule for $key");
    }
    check((int)$db->query('SELECT count(*) FROM trail_statistic_walks')->fetchColumn()===5,'backfill records each counted walk once');
    check((int)$db->query('SELECT count(*) FROM trails')->fetchColumn()===count($rows),'existing trails and walks are untouched');
} finally {
    unset($db);
    $server->exec("DROP DATABASE IF EXISTS $name WITH (FORCE)");
}
