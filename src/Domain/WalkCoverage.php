<?php
declare(strict_types=1);
namespace App\Domain;

/**
 * Share of a trail's length that one recorded walk passed along, in either direction.
 * A stretch counts when the walk came within TOLERANCE metres of it. The trail is sampled
 * every STEP metres and the walk is densified the same way inside each recorded segment,
 * so sparse GPS fixes leave no false holes while pauses and gaps still add no line.
 */
final class WalkCoverage
{
    /** Minimum share of the trail one walk must cover to count as having walked it whole. */
    public const COMPLETE = 0.9;
    public const TOLERANCE = 50.0;
    private const STEP = 20.0;

    public static function of(array $trailSegments, array $walkSegments): float
    {
        $origin = null;
        foreach ($trailSegments as $segment) if ($segment) { $origin = (float)$segment[0][0]; break; }
        if ($origin === null) return 0.0;
        $scale = cos(deg2rad($origin)) * 111320;
        $project = fn(array $p): array => [(float)$p[1] * $scale, (float)$p[0] * 110574];
        $cell = fn(float $v): int => (int)floor($v / self::TOLERANCE);
        $grid = [];
        foreach ($walkSegments as $segment) {
            $previous = null;
            foreach ($segment as $point) {
                $current = $project($point);
                foreach (self::densify($previous, $current) as [$x, $y]) $grid[$cell($x).':'.$cell($y)][] = [$x, $y];
                $previous = $current;
            }
        }
        $total = $covered = 0.0;
        foreach ($trailSegments as $segment) {
            for ($i = 1; $i < count($segment); ++$i) {
                $a = $project($segment[$i - 1]); $b = $project($segment[$i]);
                $length = hypot($b[0] - $a[0], $b[1] - $a[1]);
                if ($length <= 0) continue;
                $pieces = max(1, (int)ceil($length / self::STEP));
                $total += $length;
                for ($k = 0; $k < $pieces; ++$k) {
                    $t = ($k + 0.5) / $pieces;
                    $x = $a[0] + ($b[0] - $a[0]) * $t; $y = $a[1] + ($b[1] - $a[1]) * $t;
                    if (self::near($grid, $cell($x), $cell($y), $x, $y)) $covered += $length / $pieces;
                }
            }
        }
        return $total > 0 ? min(1.0, $covered / $total) : 0.0;
    }

    /** @return list<array{float,float}> points every STEP metres after $from, ending at $to. */
    private static function densify(?array $from, array $to): array
    {
        if ($from === null) return [$to];
        $pieces = max(1, (int)ceil(hypot($to[0] - $from[0], $to[1] - $from[1]) / self::STEP));
        $points = [];
        for ($k = 1; $k <= $pieces; ++$k) {
            $t = $k / $pieces;
            $points[] = [$from[0] + ($to[0] - $from[0]) * $t, $from[1] + ($to[1] - $from[1]) * $t];
        }
        return $points;
    }

    private static function near(array $grid, int $cx, int $cy, float $x, float $y): bool
    {
        for ($dx = -1; $dx <= 1; ++$dx) for ($dy = -1; $dy <= 1; ++$dy) {
            foreach ($grid[($cx + $dx).':'.($cy + $dy)] ?? [] as [$px, $py]) {
                if (hypot($px - $x, $py - $y) <= self::TOLERANCE) return true;
            }
        }
        return false;
    }
}
