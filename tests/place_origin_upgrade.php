<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

/**
 * Upgrade check for place origins: a disposable database at the schema before 010, with places
 * walkers already shared, receives the migration. Existing places keep every value and become
 * places added on site; running the migration again in isolation changes nothing.
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
    $files=[];
    foreach (array_merge(glob($migrations.'*.sql'), glob($migrations.'*.php')) as $file) $files[basename($file)]=$file;
    ksort($files);
    $db->beginTransaction();
    foreach ($files as $version=>$file) {
        if (strcmp($version,'010')>=0) break;
        str_ends_with($file,'.php') ? (require $file)($db) : $db->exec(file_get_contents($file));
    }
    $db->commit();

    $author=uuid(); $trail=uuid();
    $db->prepare("INSERT INTO users(id,username,email,password_hash,created_at) VALUES (?,'walker','walker@example.test','x','2026-01-01')")->execute([$author]);
    $db->prepare("INSERT INTO public_trails(id,fingerprint,name,segments,pois,outline,metres,south,west,north,east,author_id)
        VALUES (?,?,'Chemin du Puy','[[[45.0,3.9,null],[45.01,3.9,null]]]','[]','[]',1110,45.0,3.9,45.01,3.9,?)")->execute([$trail,str_repeat('a',64),$author]);
    $insert=$db->prepare('INSERT INTO trail_places(id,trail_id,author_id,lat,lon,elevation,name,comment,deleted) VALUES (?,?,?,?,?,?,?,?,?)');
    $insert->execute([uuid(),$trail,$author,45.005,3.9004,880.5,'Fontaine de l’Église ⛲','Eau potable — São, 東京',0]);
    $insert->execute([uuid(),$trail,$author,45.006,3.9002,null,'Belvédère','',1]);
    $columns='id,trail_id,author_id,lat,lon,elevation,name,comment,deleted,created_at,updated_at,change';
    $before=$db->query("SELECT $columns FROM trail_places ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

    $db->beginTransaction();
    $db->exec(file_get_contents($migrations.'010_place_origin.sql'));
    $db->commit();
    check($db->query("SELECT $columns FROM trail_places ORDER BY id")->fetchAll(PDO::FETCH_ASSOC)===$before,'existing places keep every value, Unicode and tombstones included');
    check($db->query("SELECT array_agg(DISTINCT origin) FROM trail_places")->fetchColumn()==='{onSite}','existing places were added on site');
    $db->beginTransaction();
    $db->exec(file_get_contents($migrations.'010_place_origin.sql'));
    $db->commit();
    check((int)$db->query('SELECT count(*) FROM trail_places')->fetchColumn()===2,'running the migration again in isolation changes nothing');
    try {
        $db->prepare("UPDATE trail_places SET origin='elsewhere'")->execute();
        throw new RuntimeException('accepted an unknown origin');
    } catch (PDOException) { echo "PASS the database refuses an unknown origin\n"; }
} finally {
    unset($db);
    $server->exec("DROP DATABASE IF EXISTS $name WITH (FORCE)");
}
