<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use App\Domain\Mutation;
use App\Infrastructure\Database;
use App\Infrastructure\PostgresTrailStore;

function check(bool $condition, string $label): void { if (!$condition) throw new RuntimeException($label); echo "PASS $label\n"; }
function uuid(): string { $h=bin2hex(random_bytes(16)); return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20); }
$db=Database::connect(); $accounts=new App\Infrastructure\Accounts($db); $session=$accounts->register(['username'=>'test_'.bin2hex(random_bytes(6)),'email'=>bin2hex(random_bytes(6)).'@example.test','password'=>'Testing123']);$owner=new App\Infrastructure\OwnerContext();$owner->id=$session['user']['id'];$store=new PostgresTrailStore($db,$owner); $id=uuid(); $op=uuid();
$payload=['id'=>$id,'name'=>'Été — Hébergements '. $id,'segments'=>[[[43.0,-1.0,100],[43.01,-1.01,null]]],'pois'=>[['point'=>[43.0,-1.0,null],'name'=>'Église où dormir ? 🥾','description'=>'À côté : cœur, forêt, São, 東京.']], 'days'=>[[0, 500], [500, 1000]]];
$request=['id'=>$id,'mutation'=>$op,'baseRevision'=>0,'deleted'=>false,'payload'=>$payload];
try {
    $m=Mutation::fromArray($request);
    check($store->apply($m)===['conflict'=>false,'revision'=>1],'first mutation');
    $restored=array_values(array_filter((new PostgresTrailStore(Database::connect(),$owner))->all(),fn($r)=>$r['id']===$id));
    check($restored[0]['payload']['days']===$payload['days'],'walking days restored from API storage on a new connection');
    check($restored[0]['payload']['name']===$payload['name'] && $restored[0]['payload']['pois'][0]['name']===$payload['pois'][0]['name'] && $restored[0]['payload']['pois'][0]['description']===$payload['pois'][0]['description'],'Unicode names and descriptions preserved by PostgreSQL');
    foreach (['name','poi','mojibake'] as $field) {
        $invalid=$request;
        if($field==='name')$invalid['payload']['name']="H\u{FFFD}bergements";
        elseif($field==='mojibake')$invalid['payload']['name']="H\u{00C3}\u{00A9}bergements";
        else $invalid['payload']['pois'][0]['description']="For\u{FFFD}t";
        try { Mutation::fromArray($invalid); throw new RuntimeException('accepted damaged text'); } catch (InvalidArgumentException) { echo "PASS damaged text rejected before persistence\n"; }
    }
    foreach ([[[0, 9999999]], [[0, 0]], [['0', 50]], [[-1, 50]]] as $days) {
        $invalid=$request; $invalid['payload']['days']=$days;
        try { Mutation::fromArray($invalid); throw new RuntimeException('accepted invalid walking day'); } catch (InvalidArgumentException) { echo "PASS walking day validation\n"; }
    }
    check($store->apply($m)===['conflict'=>false,'revision'=>1],'lost acknowledgement replay is idempotent');
    $request['mutation']=uuid();
    check($store->apply(Mutation::fromArray($request))['conflict']===true,'stale revision rejected');
    $request['baseRevision']=1; $request['deleted']=true;
    check($store->apply(Mutation::fromArray($request))['revision']===2,'tombstone persisted');
    $persisted=array_values(array_filter((new PostgresTrailStore(Database::connect(),$owner))->all(),fn($r)=>$r['id']===$id));
    check(count($persisted)===1 && $persisted[0]['deleted']===true,'new connection sees durable deletion');
    $invalid=$request; $invalid['payload']['segments'][0][0][0]=95;
    try { Mutation::fromArray($invalid); throw new RuntimeException('accepted invalid coordinate'); } catch (InvalidArgumentException) { echo "PASS coordinate validation\n"; }
    $invalid=$request; $invalid['mutation']=$op; $invalid['payload']['name']='changed';
    try { $store->apply(Mutation::fromArray($invalid)); throw new RuntimeException('accepted operation reuse'); } catch (InvalidArgumentException) { echo "PASS operation identity immutability\n"; }
    $kernel=new App\Kernel('test',false);
    $response=$kernel->handle(Symfony\Component\HttpFoundation\Request::create('/api/trails'));
    check($response->getStatusCode()===401,'API authentication');
    $req=Symfony\Component\HttpFoundation\Request::create('/api/trails'); $req->headers->set('Authorization','Bearer '.$session['access_token']);
    check($kernel->handle($req)->getStatusCode()===200,'authenticated API and container wiring');
    $walkId=uuid();
    $walkPayload=['id'=>$walkId,'name'=>'Recorded walk','segments'=>[[[43.0,-1.0,100],[43.0001,-1.0,103]]],'pois'=>[], 'walk'=>[
        'started'=>'2026-09-01T10:00:00.000Z','ended'=>'2026-09-01T10:20:00.000Z','seconds'=>1100,'sourceTrailId'=>null,
        'samples'=>[['2026-09-01T10:00:00.000Z',43.0,-1.0,100,5,0],['2026-09-01T10:00:10.000Z',43.0001,-1.0,103,5,0]],
        'health'=>['readAt'=>'2026-09-01T11:00:00.000Z','sources'=>['com.huami.watch.hmwatchmanager'],'steps'=>2100,'activeCalories'=>120.5,'averageHeartRate'=>105,'maxHeartRate'=>135],
    ]];
    $walkRequest=['id'=>$walkId,'mutation'=>uuid(),'baseRevision'=>0,'deleted'=>false,'payload'=>$walkPayload];
    $post=Symfony\Component\HttpFoundation\Request::create('/api/sync','POST',[],[],[],['CONTENT_TYPE'=>'application/json'],json_encode($walkRequest));
    $post->headers->set('Authorization','Bearer '.$session['access_token']);
    check($kernel->handle($post)->getStatusCode()===200,'recording and health summary accepted by authenticated API');
    $response=$kernel->handle($req);
    $newPhone=json_decode($response->getContent(),true,512,JSON_THROW_ON_ERROR);
    $restoredWalk=array_values(array_filter($newPhone,fn($r)=>$r['id']===$walkId));
    check($restoredWalk[0]['payload']['walk']['health']['steps']===2100 && count($restoredWalk[0]['payload']['walk']['samples'])===2,'new phone API read restores recorded samples and watch metrics');
    foreach (['seconds'=>99999999, 'samples'=>[['invalid',43,-1,100,5,0]]] as $key=>$value) {
        $invalid=$walkRequest; $invalid['payload']['walk'][$key]=$value;
        try { Mutation::fromArray($invalid); throw new RuntimeException('accepted invalid recording'); } catch (InvalidArgumentException) { echo "PASS recording validation\n"; }
    }
} finally {
    $db->prepare('DELETE FROM sessions WHERE user_id=?')->execute([$owner->id]);
    $db->prepare('DELETE FROM trails WHERE id=?')->execute([$id]);
    $db->prepare('DELETE FROM receipts WHERE operation_id IN (?,?)')->execute([$op,$request['mutation']]);
    if (isset($walkId)) $db->prepare('DELETE FROM trails WHERE id=?')->execute([$walkId]);
    if (isset($walkRequest)) $db->prepare('DELETE FROM receipts WHERE operation_id=?')->execute([$walkRequest['mutation']]);
    $db->prepare('DELETE FROM users WHERE id=?')->execute([$owner->id]);
}
