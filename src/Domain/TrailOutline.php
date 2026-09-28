<?php
declare(strict_types=1);
namespace App\Domain;

/**
 * Light summary of a shared trail for the offline catalogue: bounds, length and a
 * simplified line, enough for phones to pin every visible trail before downloading it.
 */
final readonly class TrailOutline
{
    public const MAXIMUM_POINTS = 120;

    /** @param list<list<array{float,float}>> $lines */
    public function __construct(public array $lines, public float $metres, public float $south, public float $west, public float $north, public float $east) {}

    public static function of(array $segments): self
    {
        $lines = [];
        $south = $west = INF; $north = $east = -INF;
        foreach ($segments as $segment) {
            if (count($segment) < 2) continue;
            $line = [];
            foreach ($segment as $point) {
                $lat = (float)$point[0]; $lon = (float)$point[1];
                $south = min($south, $lat); $north = max($north, $lat); $west = min($west, $lon); $east = max($east, $lon);
                $line[] = [$lat, $lon];
            }
            $lines[] = $line;
        }
        if ($lines === []) throw new \InvalidArgumentException('Invalid trail payload');
        $simplified = $lines;
        // Douglas-Peucker with a growing tolerance until the outline is small enough.
        for ($tolerance = 10.0, $round = 0; $round < 16; $tolerance *= 2, ++$round) {
            $simplified = array_map(fn(array $line) => self::simplify($line, $tolerance), $lines);
            if (array_sum(array_map('count', $simplified)) <= self::MAXIMUM_POINTS) break;
        }
        $rounded = array_map(fn(array $line) => array_map(fn(array $p) => [round($p[0], 5), round($p[1], 5)], $line), $simplified);
        return new self($rounded, WalkContribution::length($segments), $south, $west, $north, $east);
    }

    /** Iterative Douglas-Peucker in a local metric projection; keeps both ends. */
    private static function simplify(array $line, float $tolerance): array
    {
        $count = count($line);
        if ($count < 3) return $line;
        $scale = cos(deg2rad($line[0][0])) * 111320;
        $xy = array_map(fn(array $p) => [$p[1] * $scale, $p[0] * 110574], $line);
        $keep = array_fill(0, $count, false);
        $keep[0] = $keep[$count - 1] = true;
        $stack = [[0, $count - 1]];
        while ($stack) {
            [$first, $last] = array_pop($stack);
            [$ax, $ay] = $xy[$first]; [$bx, $by] = $xy[$last];
            $dx = $bx - $ax; $dy = $by - $ay; $length = $dx * $dx + $dy * $dy;
            $index = -1; $furthest = $tolerance;
            for ($i = $first + 1; $i < $last; ++$i) {
                [$px, $py] = $xy[$i];
                $t = $length > 0 ? max(0, min(1, (($px - $ax) * $dx + ($py - $ay) * $dy) / $length)) : 0;
                $d = hypot($px - ($ax + $t * $dx), $py - ($ay + $t * $dy));
                if ($d > $furthest) { $furthest = $d; $index = $i; }
            }
            if ($index < 0) continue;
            $keep[$index] = true;
            $stack[] = [$first, $index];
            $stack[] = [$index, $last];
        }
        return array_values(array_filter($line, fn(int $i) => $keep[$i], ARRAY_FILTER_USE_KEY));
    }
}
