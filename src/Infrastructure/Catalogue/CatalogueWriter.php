<?php
declare(strict_types=1);
namespace App\Infrastructure\Catalogue;

use App\Domain\Catalogue\CatalogueText;
use App\Domain\Catalogue\OsmCataloguePlan;
use App\Domain\TrailFingerprint;
use App\Domain\TrailOutline;
use App\Infrastructure\PostgresPublicTrails;

/**
 * Writes one country's acquisition into the shared catalogue.
 *
 * - Trails and groups are keyed by their source reference, so a new run updates them in place
 *   and their identifiers (deterministic, name-based) stay the same on every server.
 * - A line already shared by a walker or by another relation is reused, never duplicated.
 * - A trail crossing a border keeps the most complete geometry of the countries' extracts.
 * - What disappeared from the source for this country is hidden, never deleted: walks,
 *   reviews and private copies keep referring to it.
 *
 * Writes run in short batches under the library lock so phones' change cursors stay ordered.
 */
final class CatalogueWriter
{
    /** Namespace of catalogue identifiers derived from source references. */
    public const NAMESPACE = 'c3a1f0d2-6b4e-4f7a-9d2c-5e8b1a7f3c90';
    public const SOURCE = 'osm';
    public const LICENCE = 'ODbL-1.0';
    public const ATTRIBUTION = '© OpenStreetMap contributors';
    private const BATCH = 200;
    /** A run finding less than this share of the country's previous trails hides nothing. */
    private const WITHDRAWAL_SAFETY = 0.5;

    public function __construct(private \PDO $db) {}

    public static function id(string $kind, string $sourceRef): string
    {
        $hash = sha1(hex2bin(str_replace('-', '', self::NAMESPACE)).self::SOURCE.':'.$kind.':'.$sourceRef);
        $hash[12] = '5';
        $hash[16] = dechex((hexdec($hash[16]) & 0x3) | 0x8);
        return substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-'.substr($hash, 12, 4).'-'.substr($hash, 16, 4).'-'.substr($hash, 20, 12);
    }

    /** @return array<string,int> what the run wrote */
    public function write(int $run, string $country, OsmCataloguePlan $plan, OsmExtract $extract, ?callable $log = null): array
    {
        $log ??= fn(string $line) => null;
        $stats = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'reused' => 0, 'broken' => 0, 'groups' => 0, 'hidden' => 0];
        /** @var array<int, array{id:string, metres:float, bounds:array{float,float,float,float}}> $trails */
        $trails = [];
        $pending = array_keys($plan->trails);
        $done = 0;
        foreach (array_chunk($pending, self::BATCH) as $chunk) {
            $this->transaction(function () use ($chunk, $plan, $extract, $run, $country, &$trails, &$stats): void {
                foreach ($chunk as $relation) {
                    $entry = $plan->trails[$relation];
                    $line = $extract->trail($entry['ways']);
                    if ($line === null) { ++$stats['broken']; continue; }
                    if ($entry['group'] && $line['metres'] < OsmCataloguePlan::OWN_LINE_METRES) continue;
                    $written = $this->trail($run, $country, 'relation/'.$relation, $entry, $line['segments'], $stats);
                    if ($written !== null) $trails[$relation] = $written;
                }
            });
            $done += count($chunk);
            $log(sprintf('Trails: %d/%d', $done, count($pending)));
        }
        $groups = $this->groups($run, $country, $plan, $trails, $stats);
        $this->hideMissing($run, $country, $stats);
        $this->stJames($country, $plan, $trails, $groups);
        $log(sprintf('Groups: %d, hidden: %d', $stats['groups'], $stats['hidden']));
        return $stats;
    }

    /** @return array{id:string, metres:float, bounds:array{float,float,float,float}}|null */
    private function trail(int $run, string $country, string $sourceRef, array $entry, array $segments, array &$stats): ?array
    {
        $fingerprint = TrailFingerprint::of($segments);
        if ($fingerprint === null) { ++$stats['broken']; return null; }
        $outline = TrailOutline::of($segments);
        $summary = ['metres' => $outline->metres, 'bounds' => [$outline->south, $outline->west, $outline->north, $outline->east]];
        $details = json_encode($entry['details'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $refKey = CatalogueText::refKey($entry['details']['ref'] ?? null);
        $existing = $this->db->prepare('SELECT id, fingerprint, metres, country, name, description, details, ref_key, search_text, hidden, south, west, north, east
            FROM public_trails WHERE source = ? AND source_ref = ?');
        $existing->execute([self::SOURCE, $sourceRef]);
        $row = $existing->fetch(\PDO::FETCH_ASSOC);
        $owner = $this->idFor($fingerprint);
        if (!$row) {
            if ($owner !== null) { ++$stats['reused']; return ['id' => $owner] + $summary; }
            $id = self::id('trail', $sourceRef);
            $this->db->prepare("INSERT INTO public_trails (id, fingerprint, name, description, segments, pois, estimated, outline, metres, south, west, north, east,
                    source, source_ref, licence, attribution, details, ref_key, search_text, country, run_id, created_run_id)
                VALUES (?, ?, ?, ?, ?::jsonb, '[]'::jsonb, false, ?::jsonb, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?, ?)")
                ->execute([$id, $fingerprint, $entry['name'], $entry['description'], json_encode($segments, JSON_THROW_ON_ERROR), json_encode($outline->lines, JSON_THROW_ON_ERROR),
                    $outline->metres, $outline->south, $outline->west, $outline->north, $outline->east,
                    self::SOURCE, $sourceRef, self::LICENCE, self::ATTRIBUTION, $details, $refKey, $entry['search'], $country, $run, $run]);
            ++$stats['created'];
            return ['id' => $id] + $summary;
        }
        $id = $row['id'];
        // Another country's extract may hold only the part of a cross-border trail on its side.
        $complete = $row['country'] === $country || $outline->metres >= (float)$row['metres'] * 0.98;
        $geometry = $row['fingerprint'] !== $fingerprint && $complete && ($owner === null || $owner === $id);
        if ($geometry) {
            $this->db->prepare("UPDATE public_trails SET fingerprint = ?, segments = ?::jsonb, outline = ?::jsonb, metres = ?, south = ?, west = ?, north = ?, east = ?,
                    country = ?, change = nextval('public_trail_changes') WHERE id = ?")
                ->execute([$fingerprint, json_encode($segments, JSON_THROW_ON_ERROR), json_encode($outline->lines, JSON_THROW_ON_ERROR),
                    $outline->metres, $outline->south, $outline->west, $outline->north, $outline->east, $country, $id]);
        } elseif ($row['fingerprint'] !== $fingerprint) {
            // The stored line stays; report the summary it actually has.
            $summary = ['metres' => (float)$row['metres'], 'bounds' => [(float)$row['south'], (float)$row['west'], (float)$row['north'], (float)$row['east']]];
        }
        $same = $row['name'] === $entry['name'] && $row['description'] === $entry['description'] && $row['ref_key'] === $refKey
            && $row['search_text'] === $entry['search'] && !$row['hidden'] && json_decode($row['details'], true, 512, JSON_THROW_ON_ERROR) == $entry['details'];
        if ($same) {
            $this->db->prepare('UPDATE public_trails SET run_id = ? WHERE id = ?')->execute([$run, $id]);
        } else {
            $this->db->prepare("UPDATE public_trails SET name = ?, description = ?, details = ?::jsonb, ref_key = ?, search_text = ?, run_id = ?, hidden = false,
                    change = nextval('public_trail_changes') WHERE id = ?")
                ->execute([$entry['name'], $entry['description'], $details, $refKey, $entry['search'], $run, $id]);
        }
        ++$stats[$geometry || !$same ? 'updated' : 'unchanged'];
        return ['id' => $id] + $summary;
    }

    /**
     * @param array<int, array{id:string, metres:float, bounds:array}> $trails
     * @return array<int, array{id:string, metres:float, trails:int, bounds:?array}>
     */
    private function groups(int $run, string $country, OsmCataloguePlan $plan, array $trails, array &$stats): array
    {
        $ids = [];
        foreach ($plan->groups as $relation => $group) $ids[$relation] = self::id('group', 'relation/'.$relation);
        // Aggregates bottom-up: a group's length is its main line (main and stage members).
        $summaries = [];
        $summary = function (int $relation, array $path = []) use (&$summary, &$summaries, $plan, $trails, $ids): ?array {
            if (isset($summaries[$relation])) return $summaries[$relation];
            if (isset($path[$relation])) return null;
            $path[$relation] = true;
            $metres = 0.0; $count = []; $bounds = null; $members = [];
            foreach ($plan->groups[$relation]['members'] as [$kind, $member, $role]) {
                $item = $kind === 'trail' ? ($trails[$member] ?? null) : (isset($plan->groups[$member]) ? $summary($member, $path) : null);
                if ($item === null || ($kind === 'group' && $item['trails'] === 0)) continue;
                $members[] = [$kind, $member, $role, $kind === 'trail' ? $item['id'] : $ids[$member]];
                if ($role === 'main' || $role === 'stage') $metres += $item['metres'];
                if ($kind === 'trail') $count[$item['id']] = true; else $count += $item['ids'];
                if ($item['bounds'] !== null) $bounds = self::union($bounds, $item['bounds']);
            }
            return $summaries[$relation] = ['id' => $ids[$relation], 'metres' => $metres, 'trails' => count($count), 'ids' => $count, 'bounds' => $bounds, 'members' => $members];
        };
        foreach (array_keys($plan->groups) as $relation) $summary($relation);
        $written = [];
        foreach (array_chunk(array_keys($plan->groups), self::BATCH) as $chunk) {
            $this->transaction(function () use ($chunk, $plan, $summaries, $run, $country, &$written, &$stats): void {
                foreach ($chunk as $relation) {
                    $s = $summaries[$relation] ?? null;
                    if ($s === null || $s['trails'] === 0) continue;
                    $group = $plan->groups[$relation];
                    $this->group($s['id'], 'itinerary', $group['name'], $group['description'], 'relation/'.$relation, $group['details'], $group['search'], $country, $run, $s);
                    $written[$relation] = $s;
                    ++$stats['groups'];
                }
            });
        }
        foreach (array_chunk($written, self::BATCH, true) as $chunk) {
            $this->transaction(function () use ($chunk, $written): void {
                foreach ($chunk as $s) {
                    $members = array_values(array_filter($s['members'], fn(array $m) => $m[0] === 'trail' || isset($written[$m[1]])));
                    $this->members($s['id'], array_map(fn(array $m) => [$m[0], $m[3], $m[2]], $members));
                }
            });
        }
        return $written;
    }

    private function group(string $id, string $kind, string $name, string $description, string $sourceRef, array $details, string $search,
        ?string $country, ?int $run, array $summary, string $source = self::SOURCE): void
    {
        $json = json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        [$south, $west, $north, $east] = $summary['bounds'] ?? [null, null, null, null];
        $open = $source === self::SOURCE;
        $this->db->prepare("INSERT INTO trail_groups (id, kind, name, description, source, source_ref, licence, attribution, details, ref_key, search_text, country, run_id, created_run_id,
                metres, trail_count, south, west, north, east)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (id) DO UPDATE SET kind = excluded.kind, name = excluded.name, description = excluded.description, licence = excluded.licence,
                attribution = excluded.attribution, details = excluded.details, ref_key = excluded.ref_key, search_text = excluded.search_text,
                country = COALESCE(excluded.country, trail_groups.country), run_id = COALESCE(excluded.run_id, trail_groups.run_id), hidden = false,
                metres = excluded.metres, trail_count = excluded.trail_count,
                south = excluded.south, west = excluded.west, north = excluded.north, east = excluded.east, updated_at = now(),
                change = nextval('trail_group_changes')")
            ->execute([$id, $kind, $name, $description, $source, $sourceRef, $open ? self::LICENCE : null, $open ? self::ATTRIBUTION : null,
                $json, CatalogueText::refKey($details['ref'] ?? null), $search, $country, $run, $run,
                $summary['metres'], $summary['trails'], $south, $west, $north, $east]);
    }

    /** @param list<array{string,string,string}> $members kind, identifier, role in order */
    private function members(string $group, array $members): void
    {
        $this->db->prepare('DELETE FROM trail_group_members WHERE group_id = ?')->execute([$group]);
        $insert = $this->db->prepare('INSERT INTO trail_group_members (group_id, position, trail_id, member_group_id, role) VALUES (?, ?, ?, ?, ?)');
        $seen = [];
        foreach ($members as [$kind, $id, $role]) {
            if (isset($seen[$kind.$id]) || $id === $group) continue;
            $seen[$kind.$id] = true;
            $insert->execute([$group, count($seen) - 1, $kind === 'trail' ? $id : null, $kind === 'group' ? $id : null, $role]);
        }
    }

    private function hideMissing(int $run, string $country, array &$stats): void
    {
        $this->transaction(function () use ($run, $country, &$stats): void {
            $counts = $this->db->prepare('SELECT count(*) FILTER (WHERE run_id = ?), count(*) FILTER (WHERE NOT hidden) FROM public_trails WHERE source = ? AND country = ?');
            $counts->execute([$run, self::SOURCE, $country]);
            [$seen, $visible] = array_map('intval', $counts->fetch(\PDO::FETCH_NUM));
            if ($seen < $visible * self::WITHDRAWAL_SAFETY) return;
            $hide = $this->db->prepare("UPDATE public_trails SET hidden = true, change = nextval('public_trail_changes')
                WHERE source = ? AND country = ? AND run_id IS DISTINCT FROM ? AND NOT hidden");
            $hide->execute([self::SOURCE, $country, $run]);
            $stats['hidden'] += $hide->rowCount();
            $groups = $this->db->prepare("UPDATE trail_groups SET hidden = true, change = nextval('trail_group_changes')
                WHERE source = ? AND country = ? AND run_id IS DISTINCT FROM ? AND NOT hidden");
            $groups->execute([self::SOURCE, $country, $run]);
            $stats['hidden'] += $groups->rowCount();
        });
    }

    /**
     * The editorial collection of the Ways of St James, across countries: this country's
     * routes found now plus those other countries' runs found before. Groups come first,
     * then trails, each by name.
     *
     * @param array<int, array{id:string}> $trails
     * @param array<int, array{id:string}> $groups
     */
    private function stJames(string $country, OsmCataloguePlan $plan, array $trails, array $groups): void
    {
        $this->transaction(function () use ($country, $plan, $trails, $groups): void {
            $collection = EditorialCollections::ST_JAMES;
            $id = self::id('group', $collection['ref']);
            $kept = $this->db->prepare('SELECT m.trail_id, m.member_group_id FROM trail_group_members m
                LEFT JOIN public_trails t ON t.id = m.trail_id LEFT JOIN trail_groups g ON g.id = m.member_group_id
                WHERE m.group_id = ? AND NOT COALESCE(t.hidden, g.hidden) AND COALESCE(t.country, g.country) IS DISTINCT FROM ?');
            $kept->execute([$id, $country]);
            $members = [];
            foreach ($kept->fetchAll(\PDO::FETCH_NUM) as [$trail, $group]) $members[$trail ?? $group] = $trail !== null ? 'trail' : 'group';
            foreach ($plan->stJames as [$kind, $relation]) {
                $item = $kind === 'trail' ? ($trails[$relation] ?? null) : ($groups[$relation] ?? null);
                if ($item !== null) $members[$item['id']] = $kind;
            }
            $rows = [];
            if ($members !== []) {
                $ids = array_map('strval', array_keys($members));
                $marks = implode(',', array_fill(0, count($ids), '?'));
                $q = $this->db->prepare("SELECT 'group' AS kind, id, name, metres, trail_count AS trails, south, west, north, east FROM trail_groups WHERE id IN ($marks)
                    UNION ALL SELECT 'trail', id, name, metres, 1, south, west, north, east FROM public_trails WHERE id IN ($marks)
                    ORDER BY 1, 3, 2");
                $q->execute([...$ids, ...$ids]);
                $rows = array_values(array_filter($q->fetchAll(\PDO::FETCH_ASSOC), fn(array $r) => $members[$r['id']] === $r['kind']));
            }
            $bounds = null; $metres = 0.0; $count = 0;
            foreach ($rows as $r) {
                $metres += (float)$r['metres'];
                $count += (int)$r['trails'];
                if ($r['south'] !== null) $bounds = self::union($bounds, [(float)$r['south'], (float)$r['west'], (float)$r['north'], (float)$r['east']]);
            }
            $this->group($id, 'collection', $collection['name'], $collection['description'], $collection['ref'], $collection['details'], EditorialCollections::searchText($collection),
                null, null, ['metres' => $metres, 'trails' => $count, 'bounds' => $bounds], EditorialCollections::SOURCE);
            $this->members($id, array_map(fn(array $r) => [$r['kind'], $r['id'], 'main'], $rows));
        });
    }

    /** @param array{float,float,float,float}|null $a */
    private static function union(?array $a, array $b): array
    {
        return $a === null ? $b : [min($a[0], $b[0]), min($a[1], $b[1]), max($a[2], $b[2]), max($a[3], $b[3])];
    }

    private function idFor(string $fingerprint): ?string
    {
        $q = $this->db->prepare('SELECT id FROM public_trails WHERE fingerprint = ?');
        $q->execute([$fingerprint]);
        return $q->fetchColumn() ?: null;
    }

    private function transaction(callable $work): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->query('SELECT pg_advisory_xact_lock('.PostgresPublicTrails::LOCK.')');
            $work();
            $this->db->commit();
        } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
}
