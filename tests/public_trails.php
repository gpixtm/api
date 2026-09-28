<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use App\Domain\Mutation;
use App\Domain\TrailFingerprint;
use App\Domain\TrailOutline;
use App\Domain\WalkCoverage;
use App\Infrastructure\Database;
use App\Infrastructure\OwnerContext;
use App\Infrastructure\PostgresPublicTrails;
use App\Infrastructure\PostgresTrailStatistics;
use App\Infrastructure\PostgresTrailStore;
use Symfony\Component\HttpFoundation\Request;

/** Shared trail catalogue: deduplication, per-owner identities, offline index and reviews. */
function check(bool $condition, string $label): void { if (!$condition) throw new RuntimeException($label); echo "PASS $label\n"; }
function uuid(): string { $h=bin2hex(random_bytes(16)); return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20); }
/** A line heading north from ($lat, $lon), one point every ~100 m. */
function line(float $lat, float $lon, int $points, float $east = 0.0): array
{
    return array_map(fn(int $i) => [$lat + $i * 0.0009, $lon + $east, 100 + $i], range(0, $points - 1));
}

// Fingerprint vector shared with the mobile test (test/trail_identity_test.dart).
$vector = [[[45.123456, 6.5, 1200], [45.1235, 6.500051, null], [45.1235, 6.500054, null], [-0.000005, -179.999995, null]], [[1.0, 1.0, null]]];
$fingerprint = TrailFingerprint::of($vector);
check($fingerprint === 'b351d3db9f7d047ab15da5fb8853642b0f9353144f33f425f3b1094cd0f01e64', 'fingerprint vector matches the mobile implementation');
check(TrailFingerprint::publicId($fingerprint) === '37d7df1a-08a1-57cd-a63d-50cefc8eb890', 'name-based identifier vector matches the mobile implementation');
$renamed = array_map(fn(array $s) => array_map(fn(array $p) => [$p[0], $p[1], null], $s), $vector);
check(TrailFingerprint::of($renamed) === $fingerprint, 'elevations do not change a trail identity');
check(TrailFingerprint::of([[[45.1234561, 6.5000001, 3], [45.1235, 6.500051, 1]]]) === TrailFingerprint::of([[[45.123456, 6.5, 1], [45.1235, 6.500051, 1]]]), 'sub-metre re-export noise keeps the identity');
check(TrailFingerprint::of([array_reverse($vector[0])]) !== TrailFingerprint::of([$vector[0]]), 'the reverse direction is another trail');
check(TrailFingerprint::of([[[45.0, 6.0, null]]]) === null && TrailFingerprint::of([]) === null, 'no line, no shared trail');
$outline = TrailOutline::of([line(45.0, 6.0, 2000)]);
check(array_sum(array_map('count', $outline->lines)) <= TrailOutline::MAXIMUM_POINTS && abs($outline->metres - 1999 * 100.08) < 200, 'catalogue outline stays small and keeps the full length');

// Coverage of one walk, in either direction, with GPS noise and gaps.
$trail = [line(45.0, 6.0, 11)];
check(WalkCoverage::of($trail, [line(45.0, 6.0, 11, 0.00013)]) > 0.99, 'a walk 10 m beside the whole trail covers it');
check(WalkCoverage::of($trail, [array_reverse(line(45.0, 6.0, 11))]) > 0.99, 'walking the trail backwards also covers it');
check(WalkCoverage::of($trail, [[[45.0, 6.0, null], [45.009, 6.0, null]]]) > 0.99, 'sparse fixes along the trail leave no false hole');
$partial = WalkCoverage::of($trail, [line(45.0, 6.0, 8)]);
check($partial > 0.7 && $partial < WalkCoverage::COMPLETE, 'three quarters of the trail is not a complete walk');
check(WalkCoverage::of($trail, [line(45.0, 6.0, 11, 0.0013)]) === 0.0, 'a parallel walk 100 m away covers nothing');
check(WalkCoverage::of($trail, [line(45.0, 6.0, 5), line(45.0063, 6.0, 5)]) < WalkCoverage::COMPLETE, 'a recording gap adds no imaginary line');

$db = Database::connect();
$accounts = new App\Infrastructure\Accounts($db);
$sessions = []; $owners = [];
foreach (['a', 'b'] as $key) {
    $sessions[$key] = $accounts->register(['username' => 'share_'.$key.bin2hex(random_bytes(5)), 'email' => bin2hex(random_bytes(6)).'@example.test', 'password' => 'Testing123']);
    $owners[$key] = new OwnerContext(); $owners[$key]->id = $sessions[$key]['user']['id'];
}
$store = fn(string $key) => new PostgresTrailStore($db, $owners[$key], new PostgresTrailStatistics($db, $owners[$key]), new PostgresPublicTrails($db, $owners[$key]));
$catalogue = fn(string $key) => new PostgresPublicTrails($db, $owners[$key]);
$kernel = new App\Kernel('test', false);
$http = function (string $key, string $method, string $path, ?array $body = null) use ($kernel, $sessions): array {
    $request = Request::create($path, $method, [], [], [], ['CONTENT_TYPE' => 'application/json'], $body === null ? null : json_encode($body));
    $request->headers->set('Authorization', 'Bearer '.$sessions[$key]['access_token']);
    $response = $kernel->handle($request);
    return [$response->getStatusCode(), json_decode($response->getContent(), true)];
};
$push = function (string $key, array $payload, int $base = 0, bool $deleted = false) use ($store): array {
    return $store($key)->apply(Mutation::fromArray(['id' => $payload['id'], 'mutation' => uuid(), 'baseRevision' => $base, 'deleted' => $deleted, 'payload' => $payload]));
};
$walk = fn(string $source, array $segments, string $key = 'sourceTrailId') => ['id' => uuid(), 'name' => 'Walk', 'segments' => $segments, 'pois' => [],
    'walk' => ['started' => '2026-09-20T08:00:00.000Z', 'ended' => '2026-09-20T10:00:00.000Z', 'seconds' => 3600, 'sourceTrailId' => $key === 'sourceTrailId' ? $source : null, 'samples' => []]
        + ($key === 'routeId' ? ['routeId' => $source] : [])];

try {
    $since = (int)$db->query('SELECT COALESCE(max(change), 0) FROM public_trails')->fetchColumn();
    $geometry = [line(45.2, 6.2 + random_int(0, 100000) / 1e6, 11)];
    $mine = ['id' => uuid(), 'name' => 'Crête des Aiguilles 🥾', 'description' => 'Départ du refuge', 'segments' => $geometry,
        'pois' => [['point' => [45.2, 6.2, null], 'name' => 'Source', 'description' => '']], 'days' => [[0, 500]]];
    $first = $push('a', $mine);
    check($first['conflict'] === false && $first['publicId'] === $mine['id'], 'a new line is shared under its own identifier');
    $theirs = ['id' => uuid(), 'name' => 'Same line, other name', 'segments' => array_map(fn($s) => array_map(fn($p) => [$p[0], $p[1], null], $s), $geometry), 'pois' => []];
    $reused = $push('b', $theirs);
    check($reused['publicId'] === $mine['id'], 'the same line imported by someone else reuses the shared trail');
    check((int)$db->query("SELECT count(*) FROM public_trails WHERE id = '{$mine['id']}'")->fetchColumn() === 1
        && $catalogue('b')->find($mine['id'])['name'] === $mine['name'], 'no duplicate: the first name and description are kept');
    $sameId = $push('b', ['id' => $mine['id']] + $theirs);
    check($sameId['conflict'] === false && $sameId['publicId'] === $mine['id'], 'two owners may hold the same trail identifier');
    check(count(array_filter($store('a')->all(), fn($r) => $r['id'] === $mine['id'] && $r['payload']['name'] === $mine['name'] && $r['publicId'] === $mine['id'])) === 1,
        'the other owner never overwrites a private copy');
    $shared = $catalogue('b')->find($mine['id']);
    check(!isset($shared['days']) && !isset($shared['walk']) && $shared['segments'] === $geometry && $shared['pois'] == $mine['pois'], 'the shared trail carries the line and places, never planned days');
    $walkA = $walk($mine['id'], $geometry, 'routeId');
    check(!isset($push('a', $walkA)['publicId']) && (int)$db->query("SELECT count(*) FROM public_trails WHERE id = '{$walkA['id']}'")->fetchColumn() === 0, 'recorded walks are never shared');

    $page = $catalogue('b')->index($since);
    $entry = array_values(array_filter($page['trails'], fn($t) => $t['id'] === $mine['id']))[0] ?? null;
    check($entry !== null && $entry['author'] === $sessions['a']['user']['username'] && $entry['fingerprint'] === TrailFingerprint::of($geometry)
        && $entry['reviews'] === 0 && $entry['average'] === null && abs($entry['metres'] - 1000.8) < 5, 'offline index lists the shared trail with its author');
    check($catalogue('b')->index($page['next'])['trails'] === [], 'nothing new after the last change');
    $small = $catalogue('b')->index($since, 1);
    check(count($small['trails']) === 1 && ($small['more'] || $page['trails'] === $small['trails']), 'the index pages by change');

    // Reviews: only after one walk covered at least 90 % of the trail.
    [$status, $error] = $http('b', 'PUT', "/api/public-trails/{$mine['id']}/review", ['rating' => 5, 'comment' => 'Superbe']);
    check($status === 403 && $error['code'] === 'completionRequired', 'no review before walking the trail');
    $push('b', $walk($theirs['id'], [line(45.2, $geometry[0][0][1], 8)]));
    [$status, $reviews] = $http('b', 'GET', "/api/public-trails/{$mine['id']}/reviews");
    check($status === 200 && $reviews['canReview'] === false && $reviews['completion'] > 0.7, 'a partial walk shows its progress but cannot review');
    check($http('b', 'PUT', "/api/public-trails/{$mine['id']}/review", ['rating' => 4])[0] === 403, 'a partial walk is refused');
    $push('b', $walk($theirs['id'], [array_reverse($geometry[0])]));
    [$status, $reviews] = $http('b', 'PUT', "/api/public-trails/{$mine['id']}/review", ['rating' => 4, 'comment' => "  Très beau, départ à 7 h 🌄  "]);
    check($status === 200 && $reviews['canReview'] && $reviews['count'] === 1 && $reviews['average'] == 4.0
        && $reviews['reviews'][0]['comment'] === 'Très beau, départ à 7 h 🌄' && $reviews['reviews'][0]['mine'] === true, 'a walker who completed the trail reviews it, Unicode preserved');
    [$status, $reviews] = $http('b', 'PUT', "/api/public-trails/{$mine['id']}/review", ['rating' => 2, 'comment' => '']);
    check($status === 200 && $reviews['count'] === 1 && $reviews['average'] == 2.0, 'one review per walker, updated in place');
    [$status, $reviews] = $http('a', 'PUT', "/api/public-trails/{$mine['id']}/review", ['rating' => 5]);
    check($status === 200 && $reviews['count'] === 2 && $reviews['average'] == 3.5 && $reviews['reviews'][0]['mine'], 'the author qualifies through the walk that created the route');
    $after = $catalogue('b')->index($page['next']);
    check(count($after['trails']) === 1 && $after['trails'][0]['reviews'] === 2 && $after['trails'][0]['average'] === 3.5, 'a new review reaches cached catalogues');
    foreach ([['rating' => 6], ['rating' => '5'], ['rating' => 3, 'comment' => str_repeat('é', 2001)], ['rating' => 3, 'comment' => "Cr\u{FFFD}te"]] as $invalid) {
        check($http('a', 'PUT', "/api/public-trails/{$mine['id']}/review", $invalid)[0] === 422, 'invalid review rejected');
    }
    [$status, $reviews] = $http('b', 'DELETE', "/api/public-trails/{$mine['id']}/review");
    check($status === 200 && $reviews['count'] === 1 && $reviews['average'] == 5.0 && !$reviews['reviews'][0]['mine'], 'a walker removes their own review only');
    check($http('b', 'GET', '/api/public-trails/'.uuid())[0] === 404 && $http('b', 'GET', '/api/public-trails/not-a-trail/reviews')[0] === 404, 'unknown trails are not found');
    [$status, $downloaded] = $http('b', 'GET', "/api/public-trails/{$mine['id']}");
    check($status === 200 && $downloaded['segments'] === $geometry && $downloaded['author'] === $sessions['a']['user']['username'], 'phones download the full shared trail');

    // Places added where the walker stands, shared with everyone.
    $placeSince = (int)$db->query('SELECT COALESCE(max(change), 0) FROM trail_places')->fetchColumn();
    $lon = $geometry[0][0][1];
    $placeId = uuid();
    $spot = ['lat' => 45.2045, 'lon' => $lon + 0.0004, 'elevation' => 1650.5, 'name' => '  Belle vue sur le Mont-Blanc 🏔️ ', 'comment' => 'Au lever du soleil'];
    [$status, $place] = $http('a', 'PUT', "/api/public-trails/{$mine['id']}/places/$placeId", $spot);
    check($status === 200 && $place['name'] === 'Belle vue sur le Mont-Blanc 🏔️' && $place['mine'] === true && $place['deleted'] === false
        && $place['author'] === $sessions['a']['user']['username'], 'a walker adds a named place on the trail where they stand');
    check($http('a', 'PUT', "/api/public-trails/{$mine['id']}/places/$placeId", $spot)[0] === 200
        && (int)$db->query("SELECT count(*) FROM trail_places WHERE id = '$placeId'")->fetchColumn() === 1, 'retrying the same place changes nothing');
    [$status, $error] = $http('b', 'PUT', "/api/public-trails/{$mine['id']}/places/$placeId", ['name' => 'Mine now'] + $spot);
    check($status === 403 && $error['code'] === 'placeNotYours', 'only its author edits a place');
    [$status, $error] = $http('a', 'PUT', "/api/public-trails/{$mine['id']}/places/".uuid(), ['lon' => $lon + 0.01] + $spot);
    check($status === 422 && $error['code'] === 'placeTooFar', 'a place far from the trail is refused');
    [$status, $error] = $http('a', 'PUT', "/api/public-trails/{$mine['id']}/places/".uuid(), ['name' => '  '] + $spot);
    check($status === 422 && $error['code'] === 'invalidPlace', 'a place needs a name');
    check($http('a', 'PUT', '/api/public-trails/'.uuid().'/places/'.uuid(), $spot)[0] === 404, 'a place needs a shared trail');
    $feed = $catalogue('b')->places($placeSince);
    check(count($feed['places']) === 1 && $feed['places'][0]['id'] === $placeId && $feed['places'][0]['mine'] === false
        && $feed['places'][0]['trailId'] === $mine['id'] && $feed['places'][0]['comment'] === 'Au lever du soleil', 'other walkers receive the place offline');
    check($http('b', 'DELETE', "/api/public-trails/{$mine['id']}/places/$placeId")[0] === 403, 'only its author deletes a place');
    [$status, $place] = $http('a', 'DELETE', "/api/public-trails/{$mine['id']}/places/$placeId");
    check($status === 200 && $place['deleted'] === true && $http('a', 'DELETE', "/api/public-trails/{$mine['id']}/places/$placeId")[0] === 200, 'deleting a place is idempotent');
    $tombstone = $catalogue('b')->places($feed['next']);
    check(count($tombstone['places']) === 1 && $tombstone['places'][0]['deleted'] === true, 'cached phones receive the deletion');

    $push('a', $mine, 1, true);
    check($catalogue('b')->find($mine['id'])['name'] === $mine['name'], 'deleting a private copy never unpublishes the shared trail');
    $kernelAnonymous = $kernel->handle(Request::create('/api/public-trails'));
    check($kernelAnonymous->getStatusCode() === 401, 'the catalogue requires an account');
} finally {
    $ids = array_map(fn($o) => $o->id, $owners);
    foreach ($ids as $id) {
        foreach (['DELETE FROM trail_reviews WHERE user_id=?', 'DELETE FROM trail_statistic_walks WHERE owner_id=?', 'DELETE FROM trail_statistics WHERE owner_id=?',
            'DELETE FROM receipts WHERE owner_id=?', 'DELETE FROM trails WHERE owner_id=?', 'DELETE FROM sessions WHERE user_id=?'] as $sql) $db->prepare($sql)->execute([$id]);
    }
    foreach ($ids as $id) $db->prepare('DELETE FROM public_trails WHERE author_id=?')->execute([$id]);
    foreach ($ids as $id) $db->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
}
