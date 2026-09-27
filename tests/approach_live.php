<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
// Explicit live probe with public Reims coordinates and a disposable account.
if (getenv('RUN_ROUTING_LIVE') !== '1') { echo "SKIP live routing probe\n"; exit; }
$db=App\Infrastructure\Database::connect();
$accounts=new App\Infrastructure\Accounts($db);
$session=$accounts->register(['username'=>'route_'.bin2hex(random_bytes(5)), 'email'=>bin2hex(random_bytes(6)).'@example.test', 'password'=>'Testing123']);
try {
    $kernel=new App\Kernel('test',false);
    $request=Symfony\Component\HttpFoundation\Request::create('/api/approach','POST',[],[],[],['CONTENT_TYPE'=>'application/json'],json_encode(['origin'=>[49.254,4.03],'destination'=>[49.256,4.034]]));
    if ($kernel->handle($request)->getStatusCode()!==401) throw new RuntimeException('Unprotected route');
    echo "PASS routing requires authentication\n";
    $request->headers->set('Authorization','Bearer '.$session['access_token']);
    $response=$kernel->handle($request);
    $data=json_decode($response->getContent(),true);
    if ($response->getStatusCode()!==200 || count($data['steps']??[])<2) throw new RuntimeException('Live routing failed HTTP '.$response->getStatusCode());
    echo 'PASS authenticated API to live Valhalla: '.count($data['steps'])." maneuvers\n";
} finally {
    $db->prepare('DELETE FROM sessions WHERE user_id=?')->execute([$session['user']['id']]);
    $db->prepare('DELETE FROM users WHERE id=?')->execute([$session['user']['id']]);
}
