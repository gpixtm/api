<?php
declare(strict_types=1);
namespace App\Domain;

/**
 * What one finished walk adds to the statistics of the GPX it followed.
 * Mirrors the mobile rule: recorded distance on the shared axis (segment gaps add nothing),
 * active seconds, and a minimum size so an accidental start does not skew averages.
 */
final readonly class WalkContribution
{
    public const MINIMUM_METRES = 500.0;
    public const MINIMUM_SECONDS = 60;
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    public function __construct(public string $trailId, public float $metres, public int $seconds) {}

    public static function fromPayload(array $payload, bool $deleted): ?self
    {
        $walk = $payload['walk'] ?? null;
        if ($deleted || !is_array($walk) || !is_string($walk['ended'] ?? null)) return null;
        // A free walk counts for the route it created or matched, a guided walk for its GPX.
        $source = $walk['routeId'] ?? $walk['sourceTrailId'] ?? null;
        $seconds = $walk['seconds'] ?? null;
        if (!is_string($source) || !preg_match(self::UUID, $source) || !is_int($seconds)) return null;
        $metres = self::length($payload['segments'] ?? []);
        if ($metres < self::MINIMUM_METRES || $seconds < self::MINIMUM_SECONDS) return null;
        return new self($source, $metres, $seconds);
    }

    public static function length(array $segments): float
    {
        $length = 0.0;
        foreach ($segments as $segment) {
            for ($i = 1; $i < count($segment); ++$i) {
                $a = $segment[$i - 1]; $b = $segment[$i];
                $h = sin(deg2rad($b[0] - $a[0]) / 2) ** 2 + cos(deg2rad($a[0])) * cos(deg2rad($b[0])) * sin(deg2rad($b[1] - $a[1]) / 2) ** 2;
                $length += 6371008.8 * 2 * asin(sqrt(min(1, max(0, $h))));
            }
        }
        return $length;
    }
}
