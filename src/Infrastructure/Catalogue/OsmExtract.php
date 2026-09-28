<?php
declare(strict_types=1);
namespace App\Infrastructure\Catalogue;

use App\Domain\Catalogue\RouteAssembler;
use App\Domain\TrailOutline;
use App\Domain\WalkContribution;

/**
 * The route relations of one extract with the ways they use, held compactly in memory: each
 * way is a binary string of its end node identifiers and coordinates in 1e-7 degrees, so a
 * whole country fits in a few hundred megabytes. Geometry is built one trail at a time.
 */
final class OsmExtract
{
    /** Simplification tolerance in metres; far below GPS accuracy and the fingerprint grid. */
    public const TOLERANCE = 2.0;
    /** More pieces than this is a broken relation rather than a trail with a few gaps. */
    public const MAXIMUM_SEGMENTS = 60;
    /** Pieces shorter than this are dropped when the trail has others. */
    public const MINIMUM_PIECE_METRES = 20.0;

    /** @var array<int,string> */
    private array $ways = [];
    /** @var array<int, array{tags:array<string,string>, members:list<array{string,int,string}>}> */
    public array $relations = [];

    /** @param iterable<array> $objects from OplReader */
    public static function read(iterable $objects): self
    {
        $extract = new self();
        foreach ($objects as $object) {
            if ($object['type'] === 'w') {
                if (count($object['points']) < 2) continue;
                $coordinates = [];
                foreach ($object['points'] as [$lat, $lon]) { $coordinates[] = (int)round($lat * 1e7); $coordinates[] = (int)round($lon * 1e7); }
                $extract->ways[$object['id']] = pack('q2', $object['first'], $object['last']).pack('l*', ...$coordinates);
            } else {
                $extract->relations[$object['id']] = ['tags' => $object['tags'], 'members' => $object['members']];
            }
        }
        return $extract;
    }

    public function wayCount(): int { return count($this->ways); }

    /**
     * A trail's lines as `[lat, lon, null]` points, simplified, without tiny stray pieces.
     *
     * @param list<array{int,string}> $members way identifiers and roles in member order
     * @return array{segments: list<list<array{float,float,null}>>, metres: float}|null null when unusable
     */
    public function trail(array $members): ?array
    {
        $ways = [];
        foreach ($members as [$id]) {
            $packed = $this->ways[$id] ?? null;
            if ($packed === null) continue;
            $ends = unpack('q2', substr($packed, 0, 16));
            $coordinates = array_values(unpack('l*', substr($packed, 16)));
            $points = [];
            for ($i = 0, $n = count($coordinates); $i + 1 < $n; $i += 2) $points[] = [$coordinates[$i] / 1e7, $coordinates[$i + 1] / 1e7];
            $ways[] = ['first' => $ends[1], 'last' => $ends[2], 'points' => $points];
        }
        $lines = RouteAssembler::segments($ways);
        if (count($lines) > 1) {
            $lines = array_values(array_filter($lines, fn(array $line) => WalkContribution::length([$line]) >= self::MINIMUM_PIECE_METRES));
        }
        if ($lines === [] || count($lines) > self::MAXIMUM_SEGMENTS) return null;
        $segments = array_map(fn(array $line) => array_map(
            fn(array $p) => [round($p[0], 6), round($p[1], 6), null],
            TrailOutline::simplify($line, self::TOLERANCE),
        ), $lines);
        return ['segments' => $segments, 'metres' => WalkContribution::length($segments)];
    }
}
