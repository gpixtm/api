<?php
declare(strict_types=1);
namespace App\Infrastructure;
use App\Domain\Mutation;
use App\Domain\TrailFingerprint;
use App\Domain\TrailOutline;
use App\Domain\TrailPlace;
use App\Domain\WalkCoverage;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The shared trail catalogue and its reviews. Personal walks, speeds, planned days and
 * statistics never leave the private library; only a trail's line, places, name and
 * description are shared, with the author's username.
 *
 * Every write runs under the library mutation lock, so `change` values commit in order and a
 * phone that resumes after the last change it saw cannot miss a trail committed later.
 */
final readonly class PostgresPublicTrails
{
    public const LOCK = 7140931;
    public const PAGE = 500;
    public const COMMENT_LENGTH = 2000;
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    public function __construct(private \PDO $db, private OwnerContext $owner) {}

    /**
     * Shares a library trail's line, reusing the existing trail when the same line was already
     * shared. Must run inside the caller's locked transaction. Returns null without a line.
     */
    public function publish(string $author, string $privateId, array $payload): ?string
    {
        $fingerprint = TrailFingerprint::of($payload['segments'] ?? []);
        if ($fingerprint === null) return null;
        if (($existing = $this->idFor($fingerprint)) !== null) return $existing;
        $outline = TrailOutline::of($payload['segments']);
        $id = $privateId;
        foreach ([$privateId, TrailFingerprint::publicId($fingerprint), Accounts::uuid()] as $candidate) {
            $taken = $this->db->prepare('SELECT 1 FROM public_trails WHERE id = ?');
            $taken->execute([$candidate]);
            if (!$taken->fetchColumn()) { $id = $candidate; break; }
        }
        $this->db->prepare('INSERT INTO public_trails (id, fingerprint, name, description, segments, pois, estimated, outline, metres, south, west, north, east, author_id, search_text)
            VALUES (?, ?, ?, ?, ?::jsonb, ?::jsonb, ?::boolean, ?::jsonb, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT (fingerprint) DO NOTHING')
            ->execute([$id, $fingerprint, $payload['name'], $payload['description'] ?? '', json_encode($payload['segments'], JSON_THROW_ON_ERROR),
                json_encode($payload['pois'] ?? [], JSON_THROW_ON_ERROR), ($payload['estimated'] ?? false) ? 'true' : 'false',
                json_encode($outline->lines, JSON_THROW_ON_ERROR), $outline->metres, $outline->south, $outline->west, $outline->north, $outline->east, $author,
                self::searchText($payload['name'])]);
        return $this->idFor($fingerprint);
    }

    /**
     * Walkers' shared trails changed after the phone's cursor, oldest change first. Kept for
     * apps that still cache the catalogue; trails acquired from open data are browsed by area
     * and search instead.
     */
    public function index(int $since, int $limit = self::PAGE): array
    {
        $limit = max(1, min(self::PAGE, $limit));
        $q = $this->db->prepare("SELECT p.id, p.fingerprint, p.name, p.metres, p.south, p.west, p.north, p.east, p.outline, p.reviews, p.rating_total, p.change, u.username
            FROM public_trails p LEFT JOIN users u ON u.id = p.author_id WHERE p.change > ? AND p.source = 'gpix' ORDER BY p.change LIMIT ?");
        $q->execute([max(0, $since), $limit + 1]);
        $rows = $q->fetchAll(\PDO::FETCH_ASSOC);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        return [
            'trails' => array_map(fn(array $r) => [
                'id' => $r['id'], 'fingerprint' => $r['fingerprint'], 'name' => $r['name'], 'author' => $r['username'],
                'metres' => round((float)$r['metres'], 1),
                'bounds' => [(float)$r['south'], (float)$r['west'], (float)$r['north'], (float)$r['east']],
                'outline' => json_decode($r['outline'], true, 512, JSON_THROW_ON_ERROR),
                'reviews' => (int)$r['reviews'], 'average' => self::average((int)$r['reviews'], (int)$r['rating_total']),
                'change' => (int)$r['change'],
            ], $rows),
            'next' => $rows === [] ? max(0, $since) : (int)$rows[count($rows) - 1]['change'],
            'more' => $more,
        ];
    }

    /**
     * Full trail in the mobile payload shape, with what the catalogue knows about it: source,
     * licence and attribution, details from the source, length and rating. A trail withdrawn
     * from its source (`hidden`) stays readable for the walks and copies referring to it.
     */
    public function find(string $id): array
    {
        $trail = $this->trail($id);
        $reviews = (int)$trail['reviews'];
        return [
            'id' => $trail['id'], 'name' => $trail['name'], 'description' => $trail['description'],
            'segments' => json_decode($trail['segments'], true, 512, JSON_THROW_ON_ERROR),
            'pois' => json_decode($trail['pois'], true, 512, JSON_THROW_ON_ERROR),
            'estimated' => (bool)$trail['estimated'], 'author' => $trail['username'], 'createdAt' => $trail['created'],
            'source' => $trail['source'], 'licence' => $trail['licence'], 'attribution' => $trail['attribution'],
            'details' => json_decode($trail['details'], true, 512, JSON_THROW_ON_ERROR) ?: new \stdClass(),
            'metres' => round((float)$trail['metres'], 1), 'reviews' => $reviews, 'average' => self::average($reviews, (int)$trail['rating_total']),
            'hidden' => (bool)$trail['hidden'], 'change' => (int)$trail['change'],
        ];
    }

    /** Folded words a walker's trail is found by in the catalogue search. */
    public static function searchText(string $name): string { return \App\Domain\Catalogue\CatalogueText::fold($name); }

    public function reviews(string $id): array
    {
        $trail = $this->trail($id);
        $owner = $this->owner->requireId();
        $completion = $this->completion($owner, $id, json_decode($trail['segments'], true, 512, JSON_THROW_ON_ERROR));
        $q = $this->db->prepare("SELECT r.rating, r.comment, r.user_id, u.username,
                to_char(r.created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS created,
                to_char(r.updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS updated
            FROM trail_reviews r JOIN users u ON u.id = r.user_id WHERE r.trail_id = ? ORDER BY (r.user_id = ?) DESC, r.updated_at DESC LIMIT 200");
        $q->execute([$id, $owner]);
        return [
            'trailId' => $id,
            'count' => (int)$trail['reviews'],
            'average' => self::average((int)$trail['reviews'], (int)$trail['rating_total']),
            'completion' => $completion === null ? null : round($completion, 3),
            'canReview' => $completion !== null && $completion >= WalkCoverage::COMPLETE,
            'reviews' => array_map(fn(array $r) => [
                'author' => $r['username'], 'rating' => (int)$r['rating'], 'comment' => $r['comment'],
                'createdAt' => $r['created'], 'updatedAt' => $r['updated'], 'mine' => $r['user_id'] === $owner,
            ], $q->fetchAll(\PDO::FETCH_ASSOC)),
        ];
    }

    /** One review per walker and trail, only after one recorded walk covered the whole trail. */
    public function review(string $id, array $body): array
    {
        $rating = $body['rating'] ?? null;
        $comment = $body['comment'] ?? '';
        if (!is_int($rating) || $rating < 1 || $rating > 5) throw new \InvalidArgumentException('Invalid rating');
        if (!is_string($comment) || !mb_check_encoding($comment, 'UTF-8')) throw new \InvalidArgumentException('Invalid review comment');
        $comment = trim($comment);
        if (mb_strlen($comment) > self::COMMENT_LENGTH) throw new \InvalidArgumentException('Review comment too long');
        Mutation::validateText($comment);
        $owner = $this->owner->requireId();
        $this->write(function () use ($id, $owner, $rating, $comment): void {
            $trail = $this->trail($id);
            $completion = $this->completion($owner, $id, json_decode($trail['segments'], true, 512, JSON_THROW_ON_ERROR));
            if ($completion === null || $completion < WalkCoverage::COMPLETE) throw new HttpException(403, 'Walk the whole trail before reviewing it.');
            $this->db->prepare('INSERT INTO trail_reviews (trail_id, user_id, rating, comment) VALUES (?, ?, ?, ?)
                ON CONFLICT (trail_id, user_id) DO UPDATE SET rating = excluded.rating, comment = excluded.comment, updated_at = now()')
                ->execute([$id, $owner, $rating, $comment]);
        }, $id);
        return $this->reviews($id);
    }

    public function removeReview(string $id): array
    {
        $owner = $this->owner->requireId();
        $this->write(function () use ($id, $owner): void {
            $this->trail($id);
            $this->db->prepare('DELETE FROM trail_reviews WHERE trail_id = ? AND user_id = ?')->execute([$id, $owner]);
        }, $id);
        return $this->reviews($id);
    }

    /** Places added on shared trails, tombstones included, oldest change first. */
    public function places(int $since, int $limit = self::PAGE): array
    {
        $limit = max(1, min(self::PAGE, $limit));
        $owner = $this->owner->requireId();
        $q = $this->db->prepare("SELECT p.id, p.trail_id, p.lat, p.lon, p.elevation, p.name, p.comment, p.origin, p.deleted, p.change, p.author_id, u.username,
                to_char(p.updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS updated
            FROM trail_places p LEFT JOIN users u ON u.id = p.author_id WHERE p.change > ? ORDER BY p.change LIMIT ?");
        $q->execute([max(0, $since), $limit + 1]);
        $rows = $q->fetchAll(\PDO::FETCH_ASSOC);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        return [
            'places' => array_map(fn(array $r) => self::place($r, $owner), $rows),
            'next' => $rows === [] ? max(0, $since) : (int)$rows[count($rows) - 1]['change'],
            'more' => $more,
        ];
    }

    /**
     * Adds or edits the caller's place. The phone chooses the identifier, so a retry after a
     * lost response changes nothing. Only the author edits a place, which must stay within its
     * origin's distance of the trail. A new imported place that a walker already shared on this
     * trail, close by with an equivalent name, is not added again: the existing place is
     * returned instead, under its own identifier.
     */
    public function savePlace(string $trailId, string $id, array $body): array
    {
        if (!preg_match(self::UUID, $id)) throw new HttpException(404, 'Trail not found.');
        $place = TrailPlace::fromArray($body);
        $owner = $this->owner->requireId();
        $this->placeWrite(function () use ($trailId, &$id, $place, $owner): void {
            $trail = $this->trail($trailId);
            $stored = $this->authorOf($trailId, $id, $owner);
            // An older app sends no origin: its edit keeps the stored one.
            $place = $place->withOrigin($place->origin ?? $stored ?? TrailPlace::ON_SITE);
            if ($place->distanceTo(json_decode($trail['segments'], true, 512, JSON_THROW_ON_ERROR)) > $place->maximumDistance()) {
                throw new \InvalidArgumentException('Place too far from the trail');
            }
            if ($stored === null && $place->origin === TrailPlace::IMPORTED && ($existing = $this->duplicateOf($trailId, $place)) !== null) {
                $id = $existing;
                return;
            }
            $this->db->prepare("INSERT INTO trail_places (id, trail_id, author_id, lat, lon, elevation, name, comment, origin) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT (id) DO UPDATE SET lat = excluded.lat, lon = excluded.lon, elevation = excluded.elevation, name = excluded.name,
                comment = excluded.comment, origin = excluded.origin, deleted = false, updated_at = now(), change = nextval('trail_place_changes')")
                ->execute([$id, $trailId, $owner, $place->lat, $place->lon, $place->elevation, $place->name, $place->comment, $place->origin]);
        });
        return $this->placeRow($id, $owner);
    }

    /** A visible place of the trail that $place duplicates, or null. */
    private function duplicateOf(string $trailId, TrailPlace $place): ?string
    {
        $margin = TrailPlace::DUPLICATE_DISTANCE * 2 / 110574;
        $lonMargin = $margin / max(0.01, cos(deg2rad($place->lat)));
        $q = $this->db->prepare('SELECT id, lat, lon, name FROM trail_places WHERE trail_id = ? AND NOT deleted
            AND lat BETWEEN ? AND ? AND lon BETWEEN ? AND ? ORDER BY change');
        $q->execute([$trailId, $place->lat - $margin, $place->lat + $margin, $place->lon - $lonMargin, $place->lon + $lonMargin]);
        foreach ($q->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ($place->sameAs((float)$row['lat'], (float)$row['lon'], $row['name'])) return $row['id'];
        }
        return null;
    }

    /** Deleting is idempotent and leaves a tombstone for cached phones. */
    public function removePlace(string $trailId, string $id): array
    {
        if (!preg_match(self::UUID, $id)) throw new HttpException(404, 'Trail not found.');
        $owner = $this->owner->requireId();
        $this->placeWrite(function () use ($trailId, $id, $owner): void {
            $this->trail($trailId);
            if ($this->authorOf($trailId, $id, $owner) === null) throw new HttpException(404, 'Trail not found.');
            $this->db->prepare("UPDATE trail_places SET deleted = true, updated_at = now(), change = nextval('trail_place_changes') WHERE id = ? AND NOT deleted")
                ->execute([$id]);
        });
        return $this->placeRow($id, $owner);
    }

    /** The origin of the existing place, or null when it is new; throws when it belongs to someone else or another trail. */
    private function authorOf(string $trailId, string $id, string $owner): ?string
    {
        $q = $this->db->prepare('SELECT trail_id, author_id, origin FROM trail_places WHERE id = ? FOR UPDATE');
        $q->execute([$id]);
        $row = $q->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;
        if ($row['trail_id'] !== $trailId || $row['author_id'] !== $owner) throw new HttpException(403, 'Only its author can change this place.');
        return $row['origin'];
    }

    private function placeRow(string $id, string $owner): array
    {
        $q = $this->db->prepare("SELECT p.*, u.username, to_char(p.updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS updated
            FROM trail_places p LEFT JOIN users u ON u.id = p.author_id WHERE p.id = ?");
        $q->execute([$id]);
        return self::place($q->fetch(\PDO::FETCH_ASSOC), $owner);
    }

    private static function place(array $r, string $owner): array
    {
        return [
            'id' => $r['id'], 'trailId' => $r['trail_id'], 'lat' => (float)$r['lat'], 'lon' => (float)$r['lon'],
            'elevation' => $r['elevation'] === null ? null : (float)$r['elevation'],
            'name' => $r['name'], 'comment' => $r['comment'], 'origin' => $r['origin'], 'author' => $r['username'], 'mine' => $r['author_id'] === $owner,
            'deleted' => (bool)$r['deleted'], 'updatedAt' => $r['updated'], 'change' => (int)$r['change'],
        ];
    }

    private function placeWrite(callable $change): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->query('SELECT pg_advisory_xact_lock('.self::LOCK.')');
            $change();
            $this->db->commit();
        } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }

    /**
     * Share of the trail the walker's finished walks covered together, or null without any:
     * a stage walked over two days counts as walked. A walk counts when it followed this trail
     * or a private copy of it: guided walks carry `sourceTrailId`, free walks the `routeId`
     * they created or matched. Each walk keeps its own segments, so no line joins two walks.
     */
    private function completion(string $owner, string $id, array $segments): ?float
    {
        $copies = $this->db->prepare('SELECT id FROM trails WHERE owner_id = ? AND public_id = ?');
        $copies->execute([$owner, $id]);
        $references = array_values(array_unique([$id, ...$copies->fetchAll(\PDO::FETCH_COLUMN)]));
        $marks = implode(',', array_fill(0, count($references), '?'));
        $walks = $this->db->prepare("SELECT payload->'segments' FROM trails WHERE owner_id = ? AND NOT deleted
            AND jsonb_typeof(payload->'walk') = 'object' AND jsonb_typeof(payload->'walk'->'ended') = 'string'
            AND (payload->'walk'->>'sourceTrailId' IN ($marks) OR payload->'walk'->>'routeId' IN ($marks))");
        $walks->execute([$owner, ...$references, ...$references]);
        $walked = null;
        while (($walk = $walks->fetchColumn()) !== false) {
            $walked = [...$walked ?? [], ...json_decode($walk, true, 512, JSON_THROW_ON_ERROR) ?? []];
        }
        return $walked === null ? null : WalkCoverage::of($segments, $walked);
    }

    private function write(callable $change, string $id): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->query('SELECT pg_advisory_xact_lock('.self::LOCK.')');
            $change();
            $this->db->prepare("UPDATE public_trails SET reviews = s.count, rating_total = s.total, change = nextval('public_trail_changes')
                FROM (SELECT count(*) AS count, COALESCE(sum(rating), 0) AS total FROM trail_reviews WHERE trail_id = ?) s WHERE id = ?")
                ->execute([$id, $id]);
            $this->db->commit();
        } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }

    private function trail(string $id): array
    {
        if (!preg_match(self::UUID, $id)) throw new HttpException(404, 'Trail not found.');
        $q = $this->db->prepare("SELECT p.*, u.username, to_char(p.created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS created
            FROM public_trails p LEFT JOIN users u ON u.id = p.author_id WHERE p.id = ?");
        $q->execute([$id]);
        return $q->fetch(\PDO::FETCH_ASSOC) ?: throw new HttpException(404, 'Trail not found.');
    }

    private function idFor(string $fingerprint): ?string
    {
        $q = $this->db->prepare('SELECT id FROM public_trails WHERE fingerprint = ?');
        $q->execute([$fingerprint]);
        return $q->fetchColumn() ?: null;
    }

    private static function average(int $reviews, int $total): ?float { return $reviews > 0 ? round($total / $reviews, 2) : null; }
}
