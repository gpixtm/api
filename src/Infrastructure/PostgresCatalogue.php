<?php
declare(strict_types=1);
namespace App\Infrastructure;

use App\Domain\Catalogue\CatalogueText;
use App\Domain\Mutation;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Browsing the shared catalogue without copying it to the phone: trails in the visible map
 * area, a paginated search over trails and groups, group pages, and the groups walkers make.
 *
 * Groups rank before trails in search results, editorial collections first, then groups from
 * sources, then walkers' groups. Walkers' groups are public like their trails; only their
 * author changes them.
 */
final readonly class PostgresCatalogue
{
    public const AREA_LIMIT = 300;
    public const PAGE = 30;
    public const GROUP_MEMBERS = 500;
    public const NAME_LENGTH = 200;
    public const DESCRIPTION_LENGTH = 5000;
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';
    private const TRAIL = "p.id, p.name, p.metres, p.south, p.west, p.north, p.east, p.reviews, p.rating_total, p.source, p.details->>'ref' AS ref, p.country, p.change, u.username";
    private const GROUP = "g.id, g.kind, g.name, g.metres, g.trail_count, g.south, g.west, g.north, g.east, g.source, g.details->>'ref' AS ref, g.details->>'editorial' AS editorial, g.author_id, u.username";

    public function __construct(private \PDO $db, private OwnerContext $owner) {}

    /**
     * Trails whose bounds meet the area, longest first so a wide view pins the major routes.
     *
     * @return array{trails: list<array>, truncated: bool}
     */
    public function area(float $south, float $west, float $north, float $east, int $limit = self::AREA_LIMIT): array
    {
        if (!is_finite($south + $west + $north + $east) || $south > $north || $west > $east || $south < -90 || $north > 90 || $west < -180 || $east > 180) {
            throw new \InvalidArgumentException('Invalid map area');
        }
        $limit = max(1, min(self::AREA_LIMIT, $limit));
        $q = $this->db->prepare('SELECT '.self::TRAIL.', p.outline FROM public_trails p LEFT JOIN users u ON u.id = p.author_id
            WHERE NOT p.hidden AND box(point(p.west, p.south), point(p.east, p.north)) && box(point(?, ?), point(?, ?))
            ORDER BY p.metres DESC, p.id LIMIT ?');
        $q->execute([$west, $south, $east, $north, $limit + 1]);
        $rows = $q->fetchAll(\PDO::FETCH_ASSOC);
        return ['trails' => array_map(fn(array $r) => self::trail($r, true), array_slice($rows, 0, $limit)), 'truncated' => count($rows) > $limit];
    }

    /**
     * Groups then trails matching every word of the query (accents, case and separators
     * ignored; references such as "GR 20" matched as "gr20"). Without any match, similar
     * spellings are tried. An empty query browses everything. `cursor` is opaque.
     *
     * @return array{items: list<array>, next: ?string}
     */
    public function search(string $query, ?string $cursor, int $limit = self::PAGE): array
    {
        if (mb_strlen($query) > 200) throw new \InvalidArgumentException('Search too long');
        $limit = max(1, min(self::PAGE * 2, $limit));
        $fuzzy = is_string($cursor) && str_starts_with($cursor, 'f');
        $offset = max(0, (int)ltrim((string)$cursor, 'f'));
        $items = $this->matches($query, $fuzzy, $offset, $limit + 1);
        if ($items === [] && $offset === 0 && !$fuzzy && trim($query) !== '') {
            $fuzzy = true;
            $items = $this->matches($query, true, 0, $limit + 1);
        }
        $more = count($items) > $limit;
        return ['items' => array_slice($items, 0, $limit), 'next' => $more ? ($fuzzy ? 'f' : '').($offset + $limit) : null];
    }

    private function matches(string $query, bool $fuzzy, int $offset, int $limit): array
    {
        $folded = CatalogueText::fold($query);
        $refKey = CatalogueText::refKey($query) ?? '';
        $params = [];
        if ($folded === '') {
            $where = 'true';
        } elseif ($fuzzy) {
            // Close enough for a typo such as "Podiensys" for "Podiensis".
            $this->db->exec('SET pg_trgm.word_similarity_threshold = 0.5');
            $where = '? <% x.search_text';
            $params[] = $folded;
        } else {
            $all = [];
            foreach (CatalogueText::terms($query) as $stems) {
                $all[] = '('.implode(' OR ', array_fill(0, count($stems), 'x.search_text LIKE ?')).')';
                foreach ($stems as $stem) $params[] = '%'.addcslashes($stem, '%_\\').'%';
            }
            $where = '('.implode(' AND ', $all).')';
            if ($refKey !== '') { $where = "($where OR x.ref_key LIKE ?)"; $params[] = addcslashes($refKey, '%_\\').'%'; }
        }
        $sql = "SELECT * FROM (
                SELECT 'group' AS type, x.id, CASE WHEN x.details->>'editorial' IS NOT NULL THEN 0 WHEN x.source <> 'gpix' THEN 1 ELSE 2 END AS rank,
                    x.ref_key, x.search_text, x.name, x.trail_count AS weight
                FROM trail_groups x WHERE NOT x.hidden AND $where
                UNION ALL
                SELECT 'trail', x.id, 3, x.ref_key, x.search_text, x.name, x.reviews
                FROM public_trails x WHERE NOT x.hidden AND $where
            ) m
            ORDER BY m.rank, (CASE WHEN m.ref_key = ? THEN 3 WHEN ? <> '' AND m.ref_key LIKE ? THEN 2 ELSE 0 END) + word_similarity(?, m.search_text) DESC,
                m.weight DESC, m.name, m.id
            OFFSET ? LIMIT ?";
        $q = $this->db->prepare($sql);
        $q->execute([...$params, ...$params, $refKey, $refKey, addcslashes($refKey, '%_\\').'%', $folded, $offset, $limit]);
        $matches = $q->fetchAll(\PDO::FETCH_ASSOC);
        $groups = $this->groupsById(array_column(array_filter($matches, fn($m) => $m['type'] === 'group'), 'id'));
        $trails = $this->trailsById(array_column(array_filter($matches, fn($m) => $m['type'] === 'trail'), 'id'));
        $items = [];
        foreach ($matches as $m) {
            if ($m['type'] === 'group' && isset($groups[$m['id']])) $items[] = ['type' => 'group', 'group' => $groups[$m['id']]];
            if ($m['type'] === 'trail' && isset($trails[$m['id']])) $items[] = ['type' => 'trail', 'trail' => $trails[$m['id']]];
        }
        return $items;
    }

    /** The groups containing a trail, each as a path from the outermost group to the trail. */
    public function paths(string $trailId): array
    {
        $q = $this->db->prepare('WITH RECURSIVE up(group_id, role, position, path) AS (
                SELECT m.group_id, m.role, m.position, ARRAY[m.group_id] FROM trail_group_members m
                    JOIN trail_groups g ON g.id = m.group_id AND NOT g.hidden WHERE m.trail_id = ?
                UNION ALL
                SELECT m.group_id, up.role, up.position, up.path || m.group_id FROM up
                    JOIN trail_group_members m ON m.member_group_id = up.group_id
                    JOIN trail_groups g ON g.id = m.group_id AND NOT g.hidden
                WHERE cardinality(up.path) < 6 AND NOT m.group_id = ANY(up.path)
            )
            SELECT u.path, u.role, u.position, u.path[1] AS direct FROM up u WHERE NOT EXISTS (
                SELECT 1 FROM trail_group_members m JOIN trail_groups g ON g.id = m.group_id AND NOT g.hidden
                WHERE m.member_group_id = u.group_id AND NOT m.group_id = ANY(u.path))
            LIMIT 12');
        $q->execute([$trailId]);
        $rows = $q->fetchAll(\PDO::FETCH_ASSOC);
        $ids = [];
        foreach ($rows as &$row) { $row['path'] = self::uuidArray($row['path']); foreach ($row['path'] as $id) $ids[$id] = true; }
        unset($row);
        $groups = $this->groupsById(array_keys($ids));
        $stages = $this->stageNumbers(array_values(array_unique(array_column($rows, 'direct'))), $trailId);
        $paths = [];
        foreach ($rows as $row) {
            $path = array_values(array_filter(array_map(fn(string $id) => $groups[$id] ?? null, array_reverse($row['path']))));
            if ($path === []) continue;
            $paths[] = ['groups' => $path, 'role' => $row['role'], 'stage' => $stages[$row['direct']] ?? null];
        }
        usort($paths, fn(array $a, array $b) => [$a['groups'][0]['editorial'] === null, count($a['groups'])] <=> [$b['groups'][0]['editorial'] === null, count($b['groups'])]);
        return $paths;
    }

    public function group(string $id): array
    {
        $group = $this->groupRow($id);
        $members = $this->db->prepare('SELECT m.position, m.role, m.trail_id, m.member_group_id FROM trail_group_members m
            LEFT JOIN public_trails t ON t.id = m.trail_id LEFT JOIN trail_groups g ON g.id = m.member_group_id
            WHERE m.group_id = ? AND NOT COALESCE(t.hidden, g.hidden) ORDER BY m.position');
        $members->execute([$id]);
        $rows = $members->fetchAll(\PDO::FETCH_ASSOC);
        $trails = $this->trailsById(array_values(array_filter(array_column($rows, 'trail_id'))), true);
        $groups = $this->groupsById(array_values(array_filter(array_column($rows, 'member_group_id'))));
        $stage = 0;
        $list = [];
        foreach ($rows as $row) {
            $member = ['role' => $row['role'], 'stage' => $row['role'] === 'stage' ? ++$stage : null];
            if ($row['trail_id'] !== null && isset($trails[$row['trail_id']])) $list[] = $member + ['type' => 'trail', 'trail' => $trails[$row['trail_id']]];
            elseif ($row['member_group_id'] !== null && isset($groups[$row['member_group_id']])) $list[] = $member + ['type' => 'group', 'group' => $groups[$row['member_group_id']]];
        }
        $summary = $this->groupsById([$id])[$id];
        return $summary + [
            'description' => $group['description'],
            'details' => json_decode($group['details'], true, 512, JSON_THROW_ON_ERROR) ?: new \stdClass(),
            'licence' => $group['licence'], 'attribution' => $group['attribution'],
            'members' => $list,
            'parents' => $this->groupParents($id),
        ];
    }

    /** The caller's own groups, newest change first, to add a trail to one of them. */
    public function mine(): array
    {
        $owner = $this->owner->requireId();
        $q = $this->db->prepare('SELECT id FROM trail_groups WHERE author_id = ? AND NOT hidden ORDER BY updated_at DESC LIMIT 200');
        $q->execute([$owner]);
        $ids = $q->fetchAll(\PDO::FETCH_COLUMN);
        $groups = $this->groupsById($ids);
        return ['groups' => array_values(array_filter(array_map(fn(string $id) => $groups[$id] ?? null, $ids)))];
    }

    /**
     * Creates or replaces the caller's group. The phone chooses the identifier, so a retry
     * changes nothing. Members are shared trails or other groups, in order.
     */
    public function saveGroup(string $id, array $body): array
    {
        if (!preg_match(self::UUID, $id)) throw new HttpException(404, 'Group not found.');
        $kind = $body['kind'] ?? null;
        $name = $body['name'] ?? null;
        $description = $body['description'] ?? '';
        $members = $body['members'] ?? null;
        if (!in_array($kind, ['itinerary', 'collection'], true)) throw new \InvalidArgumentException('Invalid group');
        if (!is_string($name) || !is_string($description) || !mb_check_encoding($name, 'UTF-8') || !mb_check_encoding($description, 'UTF-8')) throw new \InvalidArgumentException('Invalid group');
        $name = trim($name); $description = trim($description);
        if ($name === '' || mb_strlen($name) > self::NAME_LENGTH || mb_strlen($description) > self::DESCRIPTION_LENGTH) throw new \InvalidArgumentException('Invalid group');
        Mutation::validateText($name);
        Mutation::validateText($description);
        if (!is_array($members) || !array_is_list($members) || count($members) > self::GROUP_MEMBERS) throw new \InvalidArgumentException('Invalid group');
        $list = [];
        foreach ($members as $member) {
            if (!is_array($member)) throw new \InvalidArgumentException('Invalid group');
            $trail = $member['trailId'] ?? null; $group = $member['groupId'] ?? null;
            $role = $member['role'] ?? ($kind === 'itinerary' ? 'stage' : 'main');
            if (!in_array($role, ['main', 'stage', 'variant', 'link', 'excursion', 'approach'], true)) throw new \InvalidArgumentException('Invalid group');
            if (is_string($trail) && $group === null && preg_match(self::UUID, $trail)) $list[] = ['trail', $trail, $role];
            elseif (is_string($group) && $trail === null && preg_match(self::UUID, $group) && $group !== $id) $list[] = ['group', $group, $role];
            else throw new \InvalidArgumentException('Invalid group');
        }
        $owner = $this->owner->requireId();
        $this->db->beginTransaction();
        try {
            $this->db->query('SELECT pg_advisory_xact_lock('.PostgresPublicTrails::LOCK.')');
            $existing = $this->db->prepare('SELECT author_id, source FROM trail_groups WHERE id = ? FOR UPDATE');
            $existing->execute([$id]);
            $row = $existing->fetch(\PDO::FETCH_ASSOC);
            if ($row && ($row['source'] !== 'gpix' || $row['author_id'] !== $owner)) throw new HttpException(403, 'Only its author can change this group.');
            $trails = array_values(array_unique(array_map(fn($m) => $m[1], array_filter($list, fn($m) => $m[0] === 'trail'))));
            $groups = array_values(array_unique(array_map(fn($m) => $m[1], array_filter($list, fn($m) => $m[0] === 'group'))));
            if (count($this->trailsById($trails)) !== count($trails) || count($this->groupsById($groups)) !== count($groups)) throw new \InvalidArgumentException('Unknown group member');
            if ($groups !== [] && $this->contains($groups, $id)) throw new \InvalidArgumentException('A group cannot contain itself');
            $summary = $this->aggregate($trails, $groups);
            $this->db->prepare("INSERT INTO trail_groups (id, kind, name, description, source, search_text, author_id, metres, trail_count, south, west, north, east)
                VALUES (?, ?, ?, ?, 'gpix', ?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT (id) DO UPDATE SET kind = excluded.kind, name = excluded.name, description = excluded.description, search_text = excluded.search_text,
                    hidden = false, metres = excluded.metres, trail_count = excluded.trail_count,
                    south = excluded.south, west = excluded.west, north = excluded.north, east = excluded.east, updated_at = now(), change = nextval('trail_group_changes')")
                ->execute([$id, $kind, $name, $description, CatalogueText::fold($name), $owner, $summary['metres'], $summary['trails'], ...$summary['bounds']]);
            $this->db->prepare('DELETE FROM trail_group_members WHERE group_id = ?')->execute([$id]);
            $insert = $this->db->prepare('INSERT INTO trail_group_members (group_id, position, trail_id, member_group_id, role) VALUES (?, ?, ?, ?, ?)');
            $seen = [];
            foreach ($list as [$type, $member, $role]) {
                if (isset($seen[$type.$member])) continue;
                $seen[$type.$member] = true;
                $insert->execute([$id, count($seen) - 1, $type === 'trail' ? $member : null, $type === 'group' ? $member : null, $role]);
            }
            $this->db->commit();
        } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
        return $this->group($id);
    }

    /** Deleting is idempotent and only for the author; the group disappears for everyone. */
    public function removeGroup(string $id): array
    {
        if (!preg_match(self::UUID, $id)) throw new HttpException(404, 'Group not found.');
        $owner = $this->owner->requireId();
        $this->db->beginTransaction();
        try {
            $this->db->query('SELECT pg_advisory_xact_lock('.PostgresPublicTrails::LOCK.')');
            $q = $this->db->prepare('SELECT author_id, source FROM trail_groups WHERE id = ? FOR UPDATE');
            $q->execute([$id]);
            $row = $q->fetch(\PDO::FETCH_ASSOC);
            if (!$row) throw new HttpException(404, 'Group not found.');
            if ($row['source'] !== 'gpix' || $row['author_id'] !== $owner) throw new HttpException(403, 'Only its author can change this group.');
            $this->db->prepare("UPDATE trail_groups SET hidden = true, updated_at = now(), change = nextval('trail_group_changes') WHERE id = ? AND NOT hidden")->execute([$id]);
            $this->db->commit();
        } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
        return ['id' => $id, 'deleted' => true];
    }

    /** Whether any of these groups contains the given group, at any depth. */
    private function contains(array $groups, string $target): bool
    {
        $marks = implode(',', array_fill(0, count($groups), '?'));
        $q = $this->db->prepare("WITH RECURSIVE down(id, depth) AS (
                SELECT id, 0 FROM trail_groups WHERE id IN ($marks)
                UNION SELECT m.member_group_id, down.depth + 1 FROM down JOIN trail_group_members m ON m.group_id = down.id
                WHERE m.member_group_id IS NOT NULL AND down.depth < 12)
            SELECT 1 FROM down WHERE id = ? LIMIT 1");
        $q->execute([...$groups, $target]);
        return (bool)$q->fetchColumn();
    }

    private function aggregate(array $trails, array $groups): array
    {
        $metres = 0.0; $count = 0; $bounds = null;
        $rows = [];
        if ($trails !== []) {
            $q = $this->db->prepare('SELECT metres, 1 AS trails, south, west, north, east FROM public_trails WHERE id IN ('.implode(',', array_fill(0, count($trails), '?')).')');
            $q->execute($trails);
            $rows = $q->fetchAll(\PDO::FETCH_ASSOC);
        }
        if ($groups !== []) {
            $q = $this->db->prepare('SELECT metres, trail_count AS trails, south, west, north, east FROM trail_groups WHERE id IN ('.implode(',', array_fill(0, count($groups), '?')).')');
            $q->execute($groups);
            $rows = [...$rows, ...$q->fetchAll(\PDO::FETCH_ASSOC)];
        }
        foreach ($rows as $r) {
            $metres += (float)$r['metres'];
            $count += (int)$r['trails'];
            if ($r['south'] === null) continue;
            $b = [(float)$r['south'], (float)$r['west'], (float)$r['north'], (float)$r['east']];
            $bounds = $bounds === null ? $b : [min($bounds[0], $b[0]), min($bounds[1], $b[1]), max($bounds[2], $b[2]), max($bounds[3], $b[3])];
        }
        return ['metres' => $metres, 'trails' => $count, 'bounds' => $bounds ?? [null, null, null, null]];
    }

    private function groupParents(string $id): array
    {
        $q = $this->db->prepare('SELECT m.group_id FROM trail_group_members m JOIN trail_groups g ON g.id = m.group_id AND NOT g.hidden WHERE m.member_group_id = ? LIMIT 20');
        $q->execute([$id]);
        $groups = $this->groupsById($q->fetchAll(\PDO::FETCH_COLUMN));
        return array_values($groups);
    }

    /** @return array<string,int> for each group, the trail's number among its stages */
    private function stageNumbers(array $groups, string $trailId): array
    {
        if ($groups === []) return [];
        $q = $this->db->prepare('SELECT group_id, n FROM (
                SELECT m.group_id, m.trail_id, row_number() OVER (PARTITION BY m.group_id ORDER BY m.position) AS n
                FROM trail_group_members m WHERE m.role = \'stage\' AND m.group_id IN ('.implode(',', array_fill(0, count($groups), '?')).')) s
            WHERE s.trail_id = ?');
        $q->execute([...$groups, $trailId]);
        return array_map('intval', $q->fetchAll(\PDO::FETCH_KEY_PAIR));
    }

    private function groupRow(string $id): array
    {
        if (!preg_match(self::UUID, $id)) throw new HttpException(404, 'Group not found.');
        $q = $this->db->prepare('SELECT * FROM trail_groups WHERE id = ? AND NOT hidden');
        $q->execute([$id]);
        return $q->fetch(\PDO::FETCH_ASSOC) ?: throw new HttpException(404, 'Group not found.');
    }

    /** @return array<string,array> */
    private function groupsById(array $ids): array
    {
        if ($ids === []) return [];
        $q = $this->db->prepare('SELECT '.self::GROUP.' FROM trail_groups g LEFT JOIN users u ON u.id = g.author_id
            WHERE NOT g.hidden AND g.id IN ('.implode(',', array_fill(0, count($ids), '?')).')');
        $q->execute(array_values($ids));
        $owner = $this->owner->id;
        $groups = [];
        foreach ($q->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $groups[$r['id']] = [
                'id' => $r['id'], 'kind' => $r['kind'], 'name' => $r['name'], 'editorial' => $r['editorial'], 'ref' => $r['ref'],
                'metres' => round((float)$r['metres'], 1), 'trailCount' => (int)$r['trail_count'],
                'bounds' => $r['south'] === null ? null : [(float)$r['south'], (float)$r['west'], (float)$r['north'], (float)$r['east']],
                'source' => $r['source'], 'author' => $r['username'], 'mine' => $owner !== null && $r['author_id'] === $owner,
            ];
        }
        return $groups;
    }

    /** @return array<string,array> */
    private function trailsById(array $ids, bool $outline = false): array
    {
        if ($ids === []) return [];
        $q = $this->db->prepare('SELECT '.self::TRAIL.($outline ? ', p.outline' : '').' FROM public_trails p LEFT JOIN users u ON u.id = p.author_id
            WHERE p.id IN ('.implode(',', array_fill(0, count($ids), '?')).')');
        $q->execute(array_values($ids));
        $trails = [];
        foreach ($q->fetchAll(\PDO::FETCH_ASSOC) as $r) $trails[$r['id']] = self::trail($r, $outline);
        return $trails;
    }

    /** A catalogue trail summary; the outline is included for map pins. */
    public static function trail(array $r, bool $outline): array
    {
        $reviews = (int)$r['reviews'];
        return [
            'id' => $r['id'], 'name' => $r['name'], 'author' => $r['username'], 'metres' => round((float)$r['metres'], 1),
            'bounds' => [(float)$r['south'], (float)$r['west'], (float)$r['north'], (float)$r['east']],
            'reviews' => $reviews, 'average' => $reviews > 0 ? round((int)$r['rating_total'] / $reviews, 2) : null,
            'source' => $r['source'], 'ref' => $r['ref'], 'change' => (int)$r['change'],
        ] + ($outline ? ['outline' => json_decode($r['outline'], true, 512, JSON_THROW_ON_ERROR)] : []);
    }

    /** @return list<string> */
    private static function uuidArray(string $array): array
    {
        return array_values(array_filter(explode(',', trim($array, '{}'))));
    }
}
