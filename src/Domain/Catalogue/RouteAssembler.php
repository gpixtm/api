<?php
declare(strict_types=1);
namespace App\Domain\Catalogue;

/**
 * Turns the ways of a route relation into ordered lines. Members are usually listed in walking
 * order: consecutive ways sharing an end node join (reversed when drawn the other way), and a
 * break starts a new segment rather than an imaginary straight line. Segments whose ends meet
 * are then merged, which repairs relations whose members are listed out of order.
 */
final class RouteAssembler
{
    /** Above this many pieces a relation is too broken to repair and is kept as it is. */
    private const MERGE_LIMIT = 120;

    /**
     * @param list<array{first:int,last:int,points:list<array{float,float}>}> $ways in member order
     * @return list<list<array{float,float}>>
     */
    public static function segments(array $ways): array
    {
        $chains = [];
        $current = null;
        foreach ($ways as $way) {
            if (count($way['points']) < 2) continue;
            if ($current === null) { $current = $way + ['single' => true]; continue; }
            if ($current['last'] === $way['first']) {
                $current = self::append($current, $way);
            } elseif ($current['last'] === $way['last']) {
                $current = self::append($current, self::reverse($way));
            } elseif ($current['single'] && $current['first'] === $way['first']) {
                $current = self::append(self::reverse($current), $way);
            } elseif ($current['single'] && $current['first'] === $way['last']) {
                $current = self::append(self::reverse($current), self::reverse($way));
            } else {
                $chains[] = $current;
                $current = $way + ['single' => true];
            }
        }
        if ($current !== null) $chains[] = $current;
        if (count($chains) <= self::MERGE_LIMIT) $chains = self::merge($chains);
        $segments = [];
        foreach ($chains as $chain) {
            $points = [];
            foreach ($chain['points'] as $point) {
                if ($points !== [] && $points[count($points) - 1] === $point) continue;
                $points[] = $point;
            }
            if (count($points) >= 2) $segments[] = $points;
        }
        return $segments;
    }

    private static function merge(array $chains): array
    {
        do {
            $merged = false;
            $count = count($chains);
            for ($i = 0; $i < $count && !$merged; ++$i) {
                for ($j = 0; $j < $count && !$merged; ++$j) {
                    if ($i === $j) continue;
                    $a = $chains[$i]; $b = $chains[$j];
                    $joined = match (true) {
                        $a['last'] === $b['first'] => self::append($a, $b),
                        $a['last'] === $b['last'] => self::append($a, self::reverse($b)),
                        $a['first'] === $b['first'] => self::append(self::reverse($b), $a),
                        default => null,
                    };
                    if ($joined === null) continue;
                    $chains[$i] = $joined;
                    array_splice($chains, $j, 1);
                    $merged = true;
                }
            }
        } while ($merged);
        return array_values($chains);
    }

    private static function append(array $chain, array $way): array
    {
        array_shift($way['points']);
        array_push($chain['points'], ...$way['points']);
        $chain['last'] = $way['last'];
        $chain['single'] = false;
        return $chain;
    }

    private static function reverse(array $way): array
    {
        return ['first' => $way['last'], 'last' => $way['first'], 'points' => array_reverse($way['points'])] + $way;
    }
}
