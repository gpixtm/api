<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use App\Domain\TrailFingerprint;

/**
 * Upgrade check for migrations 004 and 005: a separate disposable database at schema 003 with
 * representative private libraries, then every existing trail is shared once and nothing
 * private is changed or published.
 */
function check(bool $condition, string $label): void { if (!$condition) throw new RuntimeException($label); echo "PASS $label\n"; }
function uuid(): string { $h=bin2hex(random_bytes(16)); return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20); }
$connect=fn(string $name)=>new PDO('pgsql:host='.getenv('DB_HOST').';port=5432;dbname='.$name, 'gpix', getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='gpix_upgrade_'.bin2hex(random_bytes(4));
$server=$connect('gpix');
$server->exec("CREATE DATABASE $name");
try {
    $db=$connect($name);
    $migrations=dirname(__DIR__).'/migrations/';
    foreach (['001_library.sql','002_accounts.sql','003_trail_statistics.sql'] as $file) $db->exec(file_get_contents($migrations.$file));
    [$legacy,$newer,$later]=[uuid(),uuid(),uuid()];
    $db->prepare("INSERT INTO users(id,username,email,password_hash,legacy_owner,created_at) VALUES
        (?,'legacy','legacy@example.test','x',true,'2026-01-01'),(?,'newer','newer@example.test','x',false,'2026-02-01'),(?,'later','later@example.test','x',false,'2026-03-01')")
        ->execute([$legacy,$newer,$later]);
    $line=[[[45.0,6.0,1000],[45.001,6.001,1010],[45.002,6.0,null]]];
    $trail=fn(string $name, array $segments, array $extra=[])=>['id'=>uuid(),'name'=>$name,'segments'=>$segments,'pois'=>[]]+$extra;
    $original=$trail('Tour du lac — été 🏔️',$line,['description'=>'Départ gare','days'=>[[0,100]]]);
    $copy=$trail('Lake loop',array_map(fn($s)=>array_map(fn($p)=>[$p[0],$p[1],null],$s),$line));
    $other=$trail('Другой маршрут',[[[46.0,7.0,null],[46.01,7.0,null]]]);
    $places=$trail('Places',[],['pois'=>[['point'=>[45.0,6.0,null],'name'=>'Refuge','description'=>'']]]);
    $gone=$trail('Deleted',[[[47.0,8.0,null],[47.01,8.0,null]]]);
    $orphan=$trail('Unowned',[[[48.0,9.0,null],[48.01,9.0,null]]]);
    $walk=$trail('Walk',$line,['walk'=>['started'=>'2026-09-01T08:00:00.000Z','ended'=>'2026-09-01T09:00:00.000Z','seconds'=>3000,'sourceTrailId'=>$original['id'],'samples'=>[]]]);
    $rows=[[$later,$copy,false],[$legacy,$original,false],[$newer,$other,false],[$newer,$places,false],[$newer,$gone,true],[null,$orphan,false],[$later,$walk,false]];
    $insert=$db->prepare('INSERT INTO trails(id,payload,revision,deleted,mutation,owner_id) VALUES (?,?::jsonb,3,?::boolean,?,?)');
    foreach ($rows as [$owner,$payload,$deleted]) $insert->execute([$payload['id'],json_encode($payload,JSON_THROW_ON_ERROR),$deleted?'true':'false',uuid(),$owner]);
    $before=$db->query('SELECT id,payload,revision,deleted,mutation,owner_id FROM trails ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

    $db->beginTransaction();
    $db->exec(file_get_contents($migrations.'004_public_trails.sql'));
    (require $migrations.'005_publish_existing.php')($db);
    $db->commit();

    $public=$db->query('SELECT id,fingerprint,name,description,author_id FROM public_trails ORDER BY change')->fetchAll(PDO::FETCH_ASSOC);
    check(count($public)===2,'each existing owned line is shared exactly once');
    check($public[0]['id']===$original['id'] && $public[0]['name']===$original['name'] && $public[0]['description']==='Départ gare' && $public[0]['author_id']===$legacy,
        'the legacy owner\'s copy comes first and keeps its name, identifier and Unicode text');
    check($public[0]['fingerprint']===TrailFingerprint::of($line),'shared trails carry the geometry fingerprint');
    check($public[1]['id']===$other['id'] && $public[1]['name']==='Другой маршрут','other walkers\' trails are shared too');
    $links=$db->query('SELECT id,public_id FROM trails WHERE public_id IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR);
    check(count($links)===3 && $links[$original['id']]===$original['id'] && $links[$copy['id']]===$original['id'] && $links[$other['id']]===$other['id'],
        'a later duplicate is linked to the shared trail instead of creating another');
    foreach (['places only'=>$places,'deleted'=>$gone,'unowned'=>$orphan,'walk'=>$walk] as $label=>$row) {
        check(!isset($links[$row['id']]) && (int)$db->query("SELECT count(*) FROM public_trails WHERE id='{$row['id']}'")->fetchColumn()===0,"$label entries stay private");
    }
    check(!str_contains((string)$db->query("SELECT row_to_json(p)::text FROM public_trails p WHERE id='{$original['id']}'")->fetchColumn(),'"days"'),'planned days are not shared');
    $after=$db->query('SELECT id,payload,revision,deleted,mutation,owner_id FROM trails ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    check(array_map(fn($r)=>[$r['id'],json_decode($r['payload'],true),$r['revision'],$r['deleted'],$r['mutation'],$r['owner_id']],$after)
        ===array_map(fn($r)=>[$r['id'],json_decode($r['payload'],true),$r['revision'],$r['deleted'],$r['mutation'],$r['owner_id']],$before),'private libraries are untouched');
    $db->beginTransaction();
    (require $migrations.'005_publish_existing.php')($db);
    $db->commit();
    check((int)$db->query('SELECT count(*) FROM public_trails')->fetchColumn()===2,'running the publication again in isolation changes nothing');
    $db->prepare('INSERT INTO trails(id,payload,revision,deleted,mutation,owner_id) VALUES (?,?::jsonb,1,false,?,?)')->execute([$original['id'],json_encode(['id'=>$original['id']]+$copy),uuid(),$later]);
    check((int)$db->query("SELECT count(*) FROM trails WHERE id='{$original['id']}'")->fetchColumn()===2,'upgraded identities are per owner');
} finally {
    unset($db);
    $server->exec("DROP DATABASE IF EXISTS $name WITH (FORCE)");
}
