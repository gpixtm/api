<?php
declare(strict_types=1);
namespace App\Domain\Catalogue;

/**
 * What an extract's route relations become in the catalogue, before any geometry is built:
 *
 * - a relation with its own ways is a trail;
 * - a relation containing other routes (a superroute such as GR 20 or Via Podiensis) is an
 *   itinerary group whose members keep the relation's order and roles; several main routes
 *   are its stages. When it also has a line of at least 1 km, that line is its first member.
 *
 * Every trail and group is searchable by its own names and by those of the groups containing
 * it, so a Camino stage is found by the name of its Camino. Routes that are Ways of St James
 * (and are not already inside one) are listed for the editorial collection.
 */
final readonly class OsmCataloguePlan
{
    public const ROUTES = ['hiking', 'foot', 'walking'];
    public const OWN_LINE_METRES = 1000.0;
    /** Words that find the Ways of St James collection and everything in it. */
    public const ST_JAMES_KEYWORDS = 'chemins de saint jacques de compostelle compostela camino de santiago caminho de santiago jakobsweg way of saint james pilgrimage pelerinage';

    /**
     * @param array<int, array{name:string, description:string, details:array, search:string, ways:list<array{int,string}>, group:bool}> $trails
     * @param array<int, array{name:string, description:string, details:array, search:string, members:list<array{string,int,string}>}> $groups
     * @param list<array{string,int}> $stJames top-level [kind, relation id] of the Ways of St James
     */
    public function __construct(public array $trails, public array $groups, public array $stJames, public array $stats) {}

    /** @param array<int, array{tags:array<string,string>, members:list<array{string,int,string}>}> $relations */
    public static function of(array $relations): self
    {
        $routes = array_filter($relations, fn(array $r) => in_array($r['tags']['route'] ?? null, self::ROUTES, true));
        $trails = []; $groups = []; $parents = []; $own = []; $flagged = [];
        $stats = ['relations' => count($routes), 'unnamed' => 0];
        foreach ($routes as $id => $relation) {
            $tags = $relation['tags'];
            $name = OsmRoute::name($tags);
            if ($name === null) { ++$stats['unnamed']; continue; }
            $children = []; $ways = [];
            foreach ($relation['members'] as [$type, $member, $role]) {
                if ($type === 'r' && isset($routes[$member]) && $member !== $id) $children[] = [$member, OsmRoute::memberRole($role)];
                elseif ($type === 'w' && OsmRoute::isLineRole($role)) $ways[] = [$member, $role];
            }
            $details = OsmRoute::details('relation', $id, $tags);
            $entry = ['name' => $name, 'description' => OsmRoute::description($details), 'details' => $details];
            $own[$id] = OsmRoute::searchText($tags);
            if (OsmRoute::isWayOfStJames($tags)) $flagged[$id] = true;
            if ($ways !== []) $trails[$id] = $entry + ['ways' => $ways, 'group' => $children !== [], 'search' => ''];
            if ($children !== []) {
                $mains = count(array_filter($children, fn(array $c) => $c[1] === 'main'));
                $members = [];
                foreach ($children as [$child, $role]) {
                    $members[] = [$child, $mains > 1 && $role === 'main' ? 'stage' : $role];
                    $parents[$child][] = $id;
                }
                $groups[$id] = $entry + ['children' => $members, 'search' => ''];
            }
        }
        // Resolve members now that every named relation is known: a child is a group when it
        // has children, else a trail. A superroute's own line only counts as a trail when long
        // enough; the writer drops members whose geometry turns out unusable.
        foreach ($groups as $id => &$group) {
            $members = isset($trails[$id]) ? [['trail', $id, 'main']] : [];
            foreach ($group['children'] as [$child, $role]) {
                if (isset($groups[$child])) $members[] = ['group', $child, $role];
                elseif (isset($trails[$child])) $members[] = ['trail', $child, $role];
            }
            $group['members'] = $members;
            unset($group['children']);
        }
        unset($group);
        $ancestors = function (int $id) use ($parents): array {
            $seen = []; $queue = $parents[$id] ?? [];
            while ($queue !== [] && count($seen) < 32) {
                $parent = array_shift($queue);
                if (isset($seen[$parent])) continue;
                $seen[$parent] = true;
                foreach ($parents[$parent] ?? [] as $next) $queue[] = $next;
            }
            return array_keys($seen);
        };
        $stJames = [];
        foreach ([['trail', &$trails], ['group', &$groups]] as [$kind, &$entries]) {
            foreach ($entries as $id => &$entry) {
                $up = $ancestors($id);
                $words = [$own[$id]];
                foreach ($up as $parent) $words[] = $own[$parent] ?? '';
                $insideStJames = array_filter($up, fn(int $p) => isset($flagged[$p])) !== [];
                if (isset($flagged[$id]) || $insideStJames) $words[] = self::ST_JAMES_KEYWORDS;
                $entry['search'] = CatalogueText::fold(implode(' ', array_unique(array_filter($words))));
                // A superroute's own line is listed through its group, not twice.
                $listed = $kind === 'group' || !isset($groups[$id]);
                if (isset($flagged[$id]) && !$insideStJames && $listed) $stJames[] = [$kind, $id];
            }
            unset($entry);
        }
        unset($entries);
        $stats['trails'] = count($trails);
        $stats['groups'] = count($groups);
        $stats['stJames'] = count($stJames);
        return new self($trails, $groups, $stJames, $stats);
    }
}
