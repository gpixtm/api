<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use App\Domain\Catalogue\CatalogueText;
use App\Domain\Catalogue\OsmCataloguePlan;
use App\Domain\Catalogue\OsmRoute;
use App\Domain\Catalogue\RouteAssembler;
use App\Domain\Mutation;
use App\Infrastructure\Catalogue\CatalogueRuns;
use App\Infrastructure\Catalogue\CatalogueWriter;
use App\Infrastructure\Catalogue\EditorialCollections;
use App\Infrastructure\Catalogue\OplReader;
use App\Infrastructure\Catalogue\OsmExtract;
use App\Infrastructure\Database;
use App\Infrastructure\OwnerContext;
use App\Infrastructure\PostgresPublicTrails;
use App\Infrastructure\PostgresTrailStatistics;
use App\Infrastructure\PostgresTrailStore;
use Symfony\Component\HttpFoundation\Request;

/**
 * Open-data catalogue: OSM route relations to trails and groups, repeated runs, withdrawals,
 * the Ways of St James collection, area and search browsing, group pages and walkers' groups.
 * The extract is a small hand-written OPL file in an isolated test country (XA).
 */
function check(bool $condition, string $label): void { if (!$condition) throw new RuntimeException($label); echo "PASS $label\n"; }
function uuid(): string { $h=bin2hex(random_bytes(16)); return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20); }
function way(int $id, array $nodes): string
{
    return "w$id v1 dV c0 t2026-01-01T00:00:00Z i0 u T N".implode(',', array_map(fn(array $n) => "n{$n[0]}x{$n[2]}y{$n[1]}", $nodes));
}
function relation(int $id, string $tags, string $members): string { return "r$id v1 dV c0 t2026-01-01T00:00:00Z i0 u T$tags M$members"; }
function osmExtract(array $lines): OsmExtract
{
    $stream = fopen('php://memory', 'w+');
    fwrite($stream, implode("\n", $lines)."\n");
    rewind($stream);
    return OsmExtract::read(OplReader::read($stream));
}

// Text normalization shared by stored search text and queries.
check(CatalogueText::fold('Chemin de St-Jacques-de-Compostelle, Œuvre') === 'chemin de saint jacques de compostelle oeuvre', 'search text ignores case, accents, punctuation and expands "St"');
check(CatalogueText::refKey('GR® 20') === 'gr20' && CatalogueText::refKey('gr-20') === 'gr20' && CatalogueText::refKey(' ') === null, 'references compare without separators');
check(CatalogueText::fold('Путь Сантьяго') === 'путь сантьяго', 'non-Latin scripts are kept');
$terms = CatalogueText::terms('Chemins de Compostelle');
check(count($terms) === 2 && in_array('santiago', $terms[1], true) && !in_array('camino', $terms[1], true), 'Compostelle also means Santiago; stop words are ignored');
check(CatalogueText::terms('de la') === [['de'], ['la']], 'a query of stop words only keeps its words');

// OPL reading.
$tags = OplReader::tags('name=Sentier%20%de%20%l%2019%Ermite,note=a%2c%b,ref=GR%20%20');
check($tags === ['name' => 'Sentier de l’Ermite', 'note' => 'a,b', 'ref' => 'GR 20'], 'OPL tags are unescaped, Unicode included');

// Assembly: member order, reversed ways, gaps and relations listed out of order.
$w = fn(int $a, int $b, float $from, float $to) => ['first' => $a, 'last' => $b, 'points' => [[$from, 9.0], [($from + $to) / 2, 9.0], [$to, 9.0]]];
$segments = RouteAssembler::segments([$w(1, 2, 42.0, 42.001), $w(3, 2, 42.002, 42.001), $w(3, 4, 42.002, 42.003)]);
check(count($segments) === 1 && $segments[0][0] === [42.0, 9.0] && $segments[0][count($segments[0]) - 1] === [42.003, 9.0], 'consecutive ways join, reversed when drawn the other way');
$segments = RouteAssembler::segments([$w(1, 2, 42.0, 42.001), $w(8, 9, 42.1, 42.101)]);
check(count($segments) === 2, 'a gap starts a new segment instead of an imaginary line');
$segments = RouteAssembler::segments([$w(3, 4, 42.002, 42.003), $w(8, 9, 42.1, 42.101), $w(1, 2, 42.0, 42.001), $w(2, 3, 42.001, 42.002)]);
check(count($segments) === 2 && max(array_map('count', $segments)) === 7, 'pieces listed out of order are merged back');

// Naming routes that have no name.
check(OsmRoute::name(['osmc:name' => 'Lucelle Douane - Pleigne', 'network' => 'lwn']) === 'Lucelle Douane - Pleigne', 'waymark names name unnamed routes');
check(OsmRoute::name(['from' => 'Bief-du-Fourg', 'to' => 'Cerniébaud']) === 'Bief-du-Fourg – Cerniébaud', 'from and to name a route');
check(OsmRoute::name(['description' => 'Wissembourg - Weiler - St-Germain - Climbach']) === 'Wissembourg - Weiler - St-Germain - Climbach', 'a short description names a route');
check(OsmRoute::name(['description' => str_repeat('Une longue description du sentier. ', 5)]) === null, 'a long description does not');
check(OsmRoute::name(['network' => 'lwn', 'osmc:symbol' => 'yellow::yellow_bar']) === null, 'a route with only waymarks stays unnamed');

// Ways of St James detection.
check(OsmRoute::isWayOfStJames(['name' => 'GR 65 - Via Podiensis']), 'Via Podiensis is a Way of St James');
check(OsmRoute::isWayOfStJames(['name' => 'Chemin de Saint-Jacques : voie de Vézelay']), 'a "chemin de Saint-Jacques" is a Way of St James');
check(!OsmRoute::isWayOfStJames(['name' => 'Boucle de Saint-Jacques-des-Blats']), 'a village named Saint-Jacques is not');
check(OsmRoute::isWayOfStJames(['name' => 'Camino Santiago', 'pilgrimage' => 'yes']) && !OsmRoute::isWayOfStJames(['name' => 'Cerro Santiago']), 'ambiguous words need the pilgrimage tag');

$fixture = fn(string $mainName, bool $withLoop) => array_values(array_filter([
    way(1, [[11, 42.0, 9.0], [12, 42.001, 9.0], [13, 42.002, 9.0]]),
    way(2, [[13, 42.002, 9.0], [14, 42.003, 9.0], [15, 42.004, 9.0]]),
    way(3, [[16, 42.006, 9.0], [17, 42.005, 9.0], [15, 42.004, 9.0]]),
    way(4, [[18, 42.02, 9.0], [19, 42.021, 9.0], [20, 42.022, 9.0]]),
    way(5, [[21, 42.1, 9.1], [22, 42.101, 9.1], [23, 42.102, 9.1]]),
    way(7, [[31, 42.2, 9.2], [32, 42.201, 9.2], [33, 42.202, 9.2]]),
    way(8, [[41, 42.3, 9.3], [42, 42.31, 9.3]]),
    way(9, [[51, 42.4, 9.4], [52, 42.401, 9.4], [53, 42.402, 9.4]]),
    way(10, [[61, 42.5, 9.5], [62, 42.501, 9.5]]),
    relation(100, "name=$mainName,ref=GR%20%20,network=nwn,osmc:symbol=red:white:red_bar,description:fr=Travers%e9%e%20%de%20%la%20%Corse,route=hiking,type=route", 'w1@,w2@,w3@,w4@'),
    relation(101, 'name=GR%20%20%20%Variante,ref=GR%20%20,route=hiking,type=route', 'w5@'),
    relation(200, 'name=GR%20%20,ref=GR%20%20,alt_name=Fra%20%li%20%Monti,route=hiking,type=superroute', 'r100@main,r101@alternative'),
    relation(300, 'name=Via%20%Podiensis%20%1,route=hiking,type=route', 'w7@'),
    relation(301, 'name=Via%20%Podiensis%20%2,route=hiking,type=route', 'w8@'),
    relation(310, 'name=Via%20%Podiensis,ref=GR%20%65,route=hiking,type=superroute', 'r300@,r301@'),
    $withLoop ? relation(400, 'name=Boucle%20%de%20%Saint-Jacques-des-Blats,route=hiking,type=route', 'w9@') : null,
    relation(500, 'route=hiking,type=route', 'w10@'),
    relation(600, 'name=V%e9%e%20%route,route=bicycle,type=route', 'w10@'),
]));

$plan = OsmCataloguePlan::of(osmExtract($fixture('GR%20%20%20%Principale', true))->relations);
check(isset($plan->trails[100], $plan->trails[101], $plan->trails[300], $plan->trails[400]) && !isset($plan->trails[500]) && !isset($plan->trails[600]), 'named walking routes become trails; unnamed and cycling routes do not');
check($plan->groups[200]['members'] === [['trail', 100, 'main'], ['trail', 101, 'variant']], 'a superroute keeps its main route and its variant');
check(array_column($plan->groups[310]['members'], 2) === ['stage', 'stage'], 'several main routes of an itinerary are its stages');
check($plan->stJames === [['group', 310]], 'only the outermost Way of St James joins the collection');
check(str_contains($plan->trails[300]['search'], 'compostelle') && str_contains($plan->trails[101]['search'], 'fra li monti'), 'trails are found by the names and keywords of their groups');
check($plan->trails[100]['details']['descriptions'] === ['fr' => 'Traversée de la Corse'] && $plan->trails[100]['description'] === 'Traversée de la Corse', 'descriptions keep their language and accents');

// The real osmium of the image: an OSM file through the filter and OPL reading keeps
// walking routes with their ways' coordinates and drops the rest.
$work = sys_get_temp_dir().'/gpix-osmium-'.bin2hex(random_bytes(4));
mkdir($work);
try {
    file_put_contents("$work/extract.osm", <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <osm version="0.6" generator="test">
          <node id="1" version="1" lat="42.0" lon="9.0"/>
          <node id="2" version="1" lat="42.001" lon="9.0"/>
          <node id="3" version="1" lat="42.002" lon="9.001"/>
          <node id="4" version="1" lat="43.0" lon="8.0"/>
          <node id="5" version="1" lat="43.001" lon="8.0"/>
          <way id="10" version="1"><nd ref="1"/><nd ref="2"/><tag k="highway" v="path"/></way>
          <way id="11" version="1"><nd ref="2"/><nd ref="3"/><tag k="highway" v="path"/></way>
          <way id="12" version="1"><nd ref="4"/><nd ref="5"/><tag k="highway" v="cycleway"/></way>
          <relation id="20" version="1"><member type="way" ref="10" role=""/><member type="way" ref="11" role=""/>
            <tag k="type" v="route"/><tag k="route" v="hiking"/><tag k="name" v="Sentier de l’Ermite"/></relation>
          <relation id="21" version="1"><member type="way" ref="12" role=""/>
            <tag k="type" v="route"/><tag k="route" v="bicycle"/><tag k="name" v="Vélo"/></relation>
        </osm>
        XML);
    $osmium = new App\Infrastructure\Catalogue\Osmium();
    $routes = OsmExtract::read($osmium->objects($osmium->routes("$work/extract.osm", $work)));
    check(array_keys($routes->relations) === [20] && $routes->wayCount() === 2, 'osmium keeps walking routes and their ways only');
    $line = $routes->trail([[10, ''], [11, '']]);
    check($line !== null && count($line['segments']) === 1 && $line['segments'][0][2] === [42.002, 9.001, null], 'ways arrive with their coordinates');
    check($routes->relations[20]['tags']['name'] === 'Sentier de l’Ermite', 'names keep their Unicode through osmium');
} finally {
    foreach (glob("$work/*") ?: [] as $path) unlink($path);
    rmdir($work);
}

$db = Database::connect();
$country = 'XA';
$accounts = new App\Infrastructure\Accounts($db);
$sessions = []; $owners = [];
foreach (['a', 'b'] as $key) {
    $sessions[$key] = $accounts->register(['username' => 'cat_'.$key.bin2hex(random_bytes(5)), 'email' => bin2hex(random_bytes(6)).'@example.test', 'password' => 'Testing123']);
    $owners[$key] = new OwnerContext(); $owners[$key]->id = $sessions[$key]['user']['id'];
}
$kernel = new App\Kernel('test', false);
$http = function (string $key, string $method, string $path, ?array $body = null) use ($kernel, $sessions): array {
    $request = Request::create($path, $method, [], [], [], ['CONTENT_TYPE' => 'application/json'], $body === null ? null : json_encode($body));
    $request->headers->set('Authorization', 'Bearer '.$sessions[$key]['access_token']);
    $response = $kernel->handle($request);
    return [$response->getStatusCode(), json_decode((string)$response->getContent(), true)];
};
$stJamesId = CatalogueWriter::id('group', EditorialCollections::ST_JAMES['ref']);
$runs = new CatalogueRuns($db);
$created = [];
try {
    // A walker already shared the exact line of the second Via Podiensis stage.
    $walkerTrail = uuid();
    $store = new PostgresTrailStore($db, $owners['a'], new PostgresTrailStatistics($db, $owners['a']), new PostgresPublicTrails($db, $owners['a']));
    $store->apply(Mutation::fromArray(['id' => $walkerTrail, 'mutation' => uuid(), 'baseRevision' => 0, 'deleted' => false,
        'payload' => ['id' => $walkerTrail, 'name' => 'Mon étape du Puy', 'segments' => [[[42.3, 9.3, null], [42.31, 9.3, null]]], 'pois' => []]]));
    $created[] = $walkerTrail;

    $writer = new CatalogueWriter($db);
    $first = $runs->start($country);
    $stats = $writer->write($first, $country, $plan, osmExtract($fixture('GR%20%20%20%Principale', true)));
    $runs->succeed($first, $stats);
    check($stats['created'] === 4 && $stats['reused'] === 1 && $stats['groups'] === 2, 'the first run creates trails and groups and reuses the walker\'s identical line');
    $main = CatalogueWriter::id('trail', 'relation/100');
    $row = $db->query("SELECT name, source, licence, country, ref_key, segments, hidden FROM public_trails WHERE id = '$main'")->fetch(PDO::FETCH_ASSOC);
    $mainSegments = json_decode($row['segments'], true);
    check($row['source'] === 'osm' && $row['licence'] === 'ODbL-1.0' && $row['country'] === 'XA' && $row['ref_key'] === 'gr20', 'acquired trails keep source, licence, country and reference');
    check(count($mainSegments) === 2 && $mainSegments[0][count($mainSegments[0]) - 1][0] === 42.006 && $mainSegments[0][0][2] === null, 'the stored line joins reversed ways, keeps the gap and has no invented elevation');
    check(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $main) === 1, 'catalogue identifiers are name-based UUIDs');

    // Repeating the run changes nothing; changing the source updates and withdraws.
    $second = $runs->start($country);
    $again = $writer->write($second, $country, $plan, osmExtract($fixture('GR%20%20%20%Principale', true)));
    $runs->succeed($second, $again);
    check($again['created'] === 0 && $again['updated'] === 0 && $again['unchanged'] === 4 && $again['hidden'] === 0, 'an identical run changes nothing');
    $changeBefore = (int)$db->query("SELECT change FROM public_trails WHERE id = '$main'")->fetchColumn();
    $third = $runs->start($country);
    $renamed = OsmCataloguePlan::of(osmExtract($fixture('GR%20%20%20%Nord-Sud', false))->relations);
    $update = $writer->write($third, $country, $renamed, osmExtract($fixture('GR%20%20%20%Nord-Sud', false)));
    $runs->succeed($third, $update);
    $loop = CatalogueWriter::id('trail', 'relation/400');
    check($update['updated'] === 1 && $update['hidden'] === 1, 'a renamed route is updated and a vanished one withdrawn');
    check((int)$db->query("SELECT change FROM public_trails WHERE id = '$main'")->fetchColumn() > $changeBefore, 'an update moves the trail\'s change');
    check($db->query("SELECT hidden FROM public_trails WHERE id = '$loop'")->fetchColumn() === true, 'a withdrawn trail is hidden, not deleted');

    // Browsing by area: longest first, hidden trails excluded.
    [$status, $area] = $http('b', 'GET', '/api/public-trails/area?south=41.9&west=8.9&north=42.5&east=9.6');
    $ids = array_column($area['trails'], 'id');
    check($status === 200 && in_array($main, $ids, true) && !in_array($loop, $ids, true) && isset($area['trails'][0]['outline']), 'the area lists visible trails with outlines');
    check($area['trails'][0]['metres'] >= $area['trails'][count($area['trails']) - 1]['metres'], 'the area lists the longest trails first');
    [$status] = $http('b', 'GET', '/api/public-trails/area?south=43&west=9&north=42&east=10');
    check($status === 422, 'an inverted area is rejected');
    [, $index] = $http('b', 'GET', '/api/public-trails?since=0&limit=500');
    check(!in_array($main, array_column($index['trails'], 'id'), true), 'the legacy offline index keeps only walkers\' trails');

    // Search: groups first, references, Compostela words, typos and pagination.
    [$status, $found] = $http('b', 'GET', '/api/catalogue/search?q='.rawurlencode('gr 20'));
    $group200 = CatalogueWriter::id('group', 'relation/200');
    check($status === 200 && $found['items'][0]['type'] === 'group' && $found['items'][0]['group']['id'] === $group200, '"gr 20" finds the GR 20 itinerary first');
    check(in_array($main, array_map(fn($i) => $i['trail']['id'] ?? null, $found['items']), true), 'and its trails after it');
    [, $found] = $http('b', 'GET', '/api/catalogue/search?q=GR20');
    check($found['items'][0]['group']['id'] === $group200, '"GR20" finds the same itinerary');
    [, $found] = $http('b', 'GET', '/api/catalogue/search?q=Compostelle');
    check($found['items'][0]['type'] === 'group' && $found['items'][0]['group']['id'] === $stJamesId && $found['items'][0]['group']['editorial'] === 'st-james', '"Compostelle" finds the Ways of St James collection first');
    $foundIds = array_map(fn($i) => $i['trail']['id'] ?? $i['group']['id'], $found['items']);
    check(in_array(CatalogueWriter::id('trail', 'relation/300'), $foundIds, true) && in_array(CatalogueWriter::id('group', 'relation/310'), $foundIds, true), 'and every route of the Ways of St James');
    [, $found] = $http('b', 'GET', '/api/catalogue/search?q='.rawurlencode('saint jacques'));
    check(($found['items'][0]['group']['id'] ?? null) === $stJamesId, '"saint jacques" finds the collection too');
    [, $found] = $http('b', 'GET', '/api/catalogue/search?q=Podiensys');
    check(count($found['items']) > 0 && ($found['items'][0]['group']['id'] ?? null) === CatalogueWriter::id('group', 'relation/310'), 'a misspelling still finds the itinerary');
    [, $page] = $http('b', 'GET', '/api/catalogue/search?q=&limit=2');
    [, $next] = $http('b', 'GET', '/api/catalogue/search?q=&limit=2&cursor='.rawurlencode($page['next']));
    check(count($page['items']) === 2 && $page['next'] !== null && array_intersect(array_map('json_encode', $page['items']), array_map('json_encode', $next['items'])) === [], 'pages follow each other without repeating');

    // A trail page tells where the trail belongs; a group page lists its members in order.
    [$status, $trail] = $http('b', 'GET', '/api/public-trails/'.CatalogueWriter::id('trail', 'relation/300'));
    check($status === 200 && $trail['source'] === 'osm' && $trail['attribution'] === '© OpenStreetMap contributors' && $trail['details']['osm']['id'] === 300 && $trail['author'] === null, 'the trail page has its source and attribution');
    check($trail['groups'][0]['stage'] === 1 && $trail['groups'][0]['role'] === 'stage', 'the trail page tells its stage number');
    [, $walkerPage] = $http('b', 'GET', '/api/public-trails/'.$walkerTrail);
    $path = array_column($walkerPage['groups'][0]['groups'], 'id');
    check($path === [$stJamesId, CatalogueWriter::id('group', 'relation/310')] && $walkerPage['groups'][0]['stage'] === 2, 'the reused walker trail is stage 2 of Via Podiensis in the Ways of St James');
    [$status, $group] = $http('b', 'GET', '/api/trail-groups/'.$group200);
    check($status === 200 && $group['kind'] === 'itinerary' && array_column($group['members'], 'role') === ['main', 'variant'] && isset($group['members'][0]['trail']['outline']), 'the group page lists its main route then its variant with outlines');
    check($group['trailCount'] === 2 && abs($group['metres'] - $area['trails'][array_search($main, $ids, true)]['metres']) < 1, 'a group\'s length is its main line');

    // Stage numbers agree between a trail page and its group page once a stage is withdrawn.
    $stages = uuid();
    $rel300 = CatalogueWriter::id('trail', 'relation/300');
    $http('a', 'PUT', "/api/trail-groups/$stages", ['kind' => 'itinerary', 'name' => 'Étapes', 'members' => [['trailId' => $main], ['trailId' => $walkerTrail], ['trailId' => $rel300]]]);
    $db->exec("UPDATE public_trails SET hidden = true WHERE id = '$main'");
    [, $numbered] = $http('b', 'GET', "/api/trail-groups/$stages");
    [, $third] = $http('b', 'GET', "/api/public-trails/$rel300");
    $inGroup = array_values(array_filter($third['groups'], fn($p) => end($p['groups'])['id'] === $stages))[0];
    check(array_column($numbered['members'], 'stage') === [1, 2] && $inGroup['stage'] === 2, 'a withdrawn stage shifts neither the group page nor the trail page');
    $db->exec("UPDATE public_trails SET hidden = false WHERE id = '$main'");
    $http('a', 'DELETE', "/api/trail-groups/$stages");

    // Walkers' groups: public, author-only, no cycles.
    $mine = uuid();
    [$status, $saved] = $http('a', 'PUT', "/api/trail-groups/$mine", ['kind' => 'collection', 'name' => 'Mes projets d\'été ☀️', 'description' => 'À faire',
        'members' => [['trailId' => $walkerTrail], ['groupId' => $group200]]]);
    check($status === 200 && $saved['mine'] === true && count($saved['members']) === 2 && $saved['trailCount'] === 3, 'a walker groups trails and itineraries, Unicode kept');
    [, $again] = $http('a', 'PUT', "/api/trail-groups/$mine", ['kind' => 'collection', 'name' => 'Mes projets d\'été ☀️', 'description' => 'À faire',
        'members' => [['trailId' => $walkerTrail], ['groupId' => $group200]]]);
    check($again['members'] == $saved['members'], 'saving the same group again changes nothing');
    [$status, $other] = $http('b', 'GET', "/api/trail-groups/$mine");
    check($status === 200 && $other['mine'] === false && $other['author'] !== null, 'other walkers see it');
    [$status, $refused] = $http('b', 'PUT', "/api/trail-groups/$mine", ['kind' => 'collection', 'name' => 'Pris', 'members' => []]);
    check($status === 403 && $refused['code'] === 'groupNotYours', 'only its author changes it');
    [$status] = $http('a', 'PUT', "/api/trail-groups/$group200", ['kind' => 'collection', 'name' => 'Pris', 'members' => []]);
    check($status === 403, 'nobody changes an acquired group');
    $outer = uuid();
    $http('a', 'PUT', "/api/trail-groups/$outer", ['kind' => 'collection', 'name' => 'Tout', 'members' => [['groupId' => $mine]]]);
    [$status, $cycle] = $http('a', 'PUT', "/api/trail-groups/$mine", ['kind' => 'collection', 'name' => 'Boucle', 'members' => [['groupId' => $outer]]]);
    check($status === 422 && $cycle['code'] === 'groupContainsItself', 'a group cannot contain itself');
    [$status, $unknown] = $http('a', 'PUT', '/api/trail-groups/'.uuid(), ['kind' => 'itinerary', 'name' => 'X', 'members' => [['trailId' => uuid()]]]);
    check($status === 422 && $unknown['code'] === 'unknownGroupMember', 'members must exist');
    [, $list] = $http('a', 'GET', '/api/my-trail-groups');
    check(array_column($list['groups'], 'id') === [$outer, $mine], 'a walker lists their own groups');
    [, $found] = $http('b', 'GET', '/api/catalogue/search?q=projets');
    check(($found['items'][0]['group']['id'] ?? null) === $mine, 'walkers\' groups are searchable');
    [$status] = $http('a', 'DELETE', "/api/trail-groups/$outer");
    [$gone] = $http('b', 'GET', "/api/trail-groups/$outer");
    check($status === 200 && $gone === 404, 'deleting a group hides it for everyone');

    // Recording references travel with walks.
    $walk = uuid();
    $store->apply(Mutation::fromArray(['id' => $walk, 'mutation' => uuid(), 'baseRevision' => 0, 'deleted' => false, 'payload' => ['id' => $walk, 'name' => 'Marche', 'pois' => [],
        'segments' => [[[42.0, 9.0, null], [42.001, 9.0, null]]],
        'walk' => ['started' => '2026-09-01T08:00:00.000Z', 'ended' => '2026-09-01T09:00:00.000Z', 'seconds' => 3000, 'sourceTrailId' => $main, 'samples' => [],
            'reference' => ['name' => 'GR 20 Nord-Sud', 'segments' => $mainSegments]]]]));
    $created[] = $walk;
    $bad = false;
    try { Mutation::fromArray(['id' => $walk, 'mutation' => uuid(), 'baseRevision' => 1, 'deleted' => false, 'payload' => ['id' => $walk, 'name' => 'Marche', 'pois' => [],
        'segments' => [], 'walk' => ['started' => '2026-09-01T08:00:00.000Z', 'ended' => '2026-09-01T09:00:00.000Z', 'seconds' => 3000, 'samples' => [], 'reference' => ['name' => 'X', 'segments' => [[[91, 0, null]]]]]]]); }
    catch (InvalidArgumentException) { $bad = true; }
    check($bad, 'a walk keeps a valid reference line only');

    // Queue: one waiting run per country; rollback hides what a run created.
    $queued = $runs->queue('xb');
    check($runs->queue('XB') === $queued, 'queueing a waiting country again reuses its run');
    $claimed = $runs->claim();
    check($claimed !== null && $claimed['id'] === $queued && $claimed['country'] === 'XB', 'the worker claims the oldest queued run');
    $runs->fail($queued, 'test');
    $hidden = $runs->rollback($first);
    check($hidden['trails'] === 3 && $db->query("SELECT hidden FROM public_trails WHERE id = '$main'")->fetchColumn() === true, 'a rollback hides the trails the run created');
} finally {
    $ids = array_map(fn($o) => $o->id, $owners);
    $marks = "'".implode("','", $ids)."'";
    $db->exec("DELETE FROM trail_group_members WHERE group_id IN (SELECT id FROM trail_groups WHERE country = 'XA' OR author_id IN ($marks) OR id = '$stJamesId')");
    $db->exec("DELETE FROM trail_groups WHERE country = 'XA' OR author_id IN ($marks) OR id = '$stJamesId'");
    foreach ($ids as $id) {
        foreach (['DELETE FROM trail_reviews WHERE user_id=?', 'DELETE FROM trail_statistic_walks WHERE owner_id=?', 'DELETE FROM trail_statistics WHERE owner_id=?',
            'DELETE FROM receipts WHERE owner_id=?', 'DELETE FROM trails WHERE owner_id=?', 'DELETE FROM sessions WHERE user_id=?'] as $sql) $db->prepare($sql)->execute([$id]);
    }
    $db->exec("DELETE FROM public_trails WHERE country = 'XA' OR author_id IN ($marks)");
    $db->exec("DELETE FROM catalogue_runs WHERE country IN ('XA', 'XB')");
    foreach ($ids as $id) $db->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
}
