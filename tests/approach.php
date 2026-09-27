<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use App\Infrastructure\WalkingDirections;
function verify(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
$fixture = ltrim(file_get_contents(__DIR__.'/approach-reims.json'), "\xEF\xBB\xBF");
$http = new MockHttpClient(function($method, $url, $options) use ($fixture) {
    $body=json_decode($options['body'],true);
    verify($method==='POST' && $body['costing']==='pedestrian' && $body['language']==='fr-FR','pedestrian French routing request');
    verify($body['locations'][1]['lat']===49.256 && $body['locations'][1]['search_cutoff']===100,'exact departure and bounded road snapping');
    return new MockResponse($fixture);
});
$service = new WalkingDirections($http);
try { $service->route(['origin'=>[91,0], 'destination'=>[49,4]]); throw new RuntimeException('accepted invalid'); }
catch (InvalidArgumentException) { echo "PASS invalid coordinates rejected before network\n"; }
$data=$service->route(['origin'=>[49.254,4.03],'destination'=>[49.256,4.034]]);
verify(count($data['steps'])===7 && str_contains($data['steps'][2]['instruction'],'gauche'),'real French maneuvers retained');
verify($data['seconds']>300 && strlen($data['shape'])>20,'route geometry and estimate returned');
try { $service->route(['origin'=>[49.254,4.03],'destination'=>[49.256,4.034]]); throw new RuntimeException('no limit'); }
catch (Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException) { echo "PASS provider rate limit shared between requests\n"; }
