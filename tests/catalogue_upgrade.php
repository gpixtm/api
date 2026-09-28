<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

/**
 * Upgrade check for the catalogue migrations: a disposable database at schema 003 (the
 * production schema before shared trails) with representative libraries receives every later
 * migration in one transaction, as a deployment does. Walkers' trails are shared, then made
 * searchable, and private libraries stay byte for byte identical.
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
    $owner=uuid();
    $db->prepare("INSERT INTO users(id,username,email,password_hash,legacy_owner,created_at) VALUES (?,'legacy','legacy@example.test','x',true,'2026-01-01')")->execute([$owner]);
    $trail=['id'=>uuid(),'name'=>'Tour du Lac d’Annecy — été 🏔️','description'=>'Départ gare','segments'=>[[[45.9,6.1,450],[45.91,6.12,null]]],'pois'=>[],'days'=>[[0,100]]];
    $walk=['id'=>uuid(),'name'=>'Walk','segments'=>$trail['segments'],'pois'=>[],
        'walk'=>['started'=>'2026-09-01T08:00:00.000Z','ended'=>'2026-09-01T09:00:00.000Z','seconds'=>3000,'sourceTrailId'=>$trail['id'],'samples'=>[]]];
    $insert=$db->prepare('INSERT INTO trails(id,payload,revision,deleted,mutation,owner_id) VALUES (?,?::jsonb,2,false,?,?)');
    foreach ([$trail,$walk] as $payload) $insert->execute([$payload['id'],json_encode($payload,JSON_THROW_ON_ERROR),uuid(),$owner]);
    $before=$db->query('SELECT id,payload,revision,deleted,mutation,owner_id FROM trails ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

    $db->exec('CREATE TABLE migrations(version TEXT PRIMARY KEY, applied_at TIMESTAMPTZ NOT NULL DEFAULT now())');
    $apply=function () use ($db, $migrations): array {
        $applied=[];
        $db->beginTransaction();
        foreach (array_merge(glob($migrations.'*.sql'), glob($migrations.'*.php')) as $file) $files[basename($file)]=$file;
        ksort($files);
        foreach ($files as $version=>$file) {
            if (in_array($version,['001_library.sql','002_accounts.sql','003_trail_statistics.sql'],true)) continue;
            $done=$db->prepare('SELECT 1 FROM migrations WHERE version=?'); $done->execute([$version]);
            if ($done->fetchColumn()) continue;
            str_ends_with($file,'.php') ? (require $file)($db) : $db->exec(file_get_contents($file));
            $db->prepare('INSERT INTO migrations(version) VALUES (?)')->execute([$version]);
            $applied[]=$version;
        }
        $db->commit();
        return $applied;
    };
    $applied=$apply();
    check(in_array('007_catalogue.sql',$applied,true) && in_array('008_catalogue_search.php',$applied,true) && in_array('005_publish_existing.php',$applied,true),
        'a schema 003 database takes every later migration in one transaction');
    check($apply()===[],'running the migrations again changes nothing');
    $shared=$db->query('SELECT id,name,source,hidden,search_text,details,change FROM public_trails')->fetchAll(PDO::FETCH_ASSOC);
    check(count($shared)===1 && $shared[0]['id']===$trail['id'] && $shared[0]['name']===$trail['name'],'the walker\'s trail is shared once with its Unicode name');
    check($shared[0]['source']==='gpix' && $shared[0]['hidden']===false && $shared[0]['details']==='{}','existing shared trails are walkers\' trails, visible, without source details');
    check($shared[0]['search_text']==='tour du lac d annecy ete','existing shared trails become searchable by name');
    $after=$db->query('SELECT id,payload,revision,deleted,mutation,owner_id FROM trails ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    check($before===$after,'private libraries are unchanged byte for byte');
    foreach (['catalogue_runs','trail_groups','trail_group_members'] as $table) {
        check((int)$db->query("SELECT count(*) FROM $table")->fetchColumn()===0,"$table starts empty");
    }
} finally {
    $db=null;
    $server->exec("DROP DATABASE $name WITH (FORCE)");
}
