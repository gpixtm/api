<?php
declare(strict_types=1);
namespace App\Domain;
use App\Domain\Catalogue\CatalogueText;

/**
 * A place on a shared trail: added by a walker where they stand, or imported from a GPX
 * file's waypoints (a hostel in the village, a spring off the path).
 */
final readonly class TrailPlace
{
    public const ON_SITE = 'onSite';
    public const IMPORTED = 'imported';

    /** A place added on site belongs to the trail it is added on: the walker must be this close to it. */
    public const MAXIMUM_DISTANCE = 100.0;

    /** An imported place may lie off the path, such as a hostel in the village, but not beyond this. */
    public const IMPORTED_DISTANCE = 5000.0;

    /** An imported place this close to a place of the same name on the same trail is that place. */
    public const DUPLICATE_DISTANCE = 30.0;
    public const NAME_LENGTH = 200;
    public const COMMENT_LENGTH = 2000;

    /** $origin is null when the phone did not send one (older apps): an edit then keeps the stored origin. */
    public function __construct(public float $lat, public float $lon, public ?float $elevation, public string $name, public string $comment, public ?string $origin = null) {}

    public static function fromArray(array $body): self
    {
        $point = [$body['lat'] ?? null, $body['lon'] ?? null, $body['elevation'] ?? null];
        foreach ([0, 1] as $i) if (!is_int($point[$i]) && !is_float($point[$i])) throw new \InvalidArgumentException('Invalid coordinate');
        Mutation::validatePoint($point);
        $name = $body['name'] ?? null;
        $comment = $body['comment'] ?? '';
        if (!is_string($name) || !is_string($comment) || !mb_check_encoding($name, 'UTF-8') || !mb_check_encoding($comment, 'UTF-8')) throw new \InvalidArgumentException('Invalid place');
        $name = trim($name); $comment = trim($comment);
        if ($name === '' || mb_strlen($name) > self::NAME_LENGTH || mb_strlen($comment) > self::COMMENT_LENGTH) throw new \InvalidArgumentException('Invalid place');
        Mutation::validateText($name); Mutation::validateText($comment);
        $origin = $body['origin'] ?? null;
        if ($origin !== null && !in_array($origin, [self::ON_SITE, self::IMPORTED], true)) throw new \InvalidArgumentException('Invalid place');
        return new self((float)$point[0], (float)$point[1], $point[2] === null ? null : (float)$point[2], $name, $comment, $origin);
    }

    /** The same place with its origin settled. */
    public function withOrigin(string $origin): self
    {
        return new self($this->lat, $this->lon, $this->elevation, $this->name, $this->comment, $origin);
    }

    /** How far from the trail's line this place may lie. */
    public function maximumDistance(): float
    {
        return $this->origin === self::IMPORTED ? self::IMPORTED_DISTANCE : self::MAXIMUM_DISTANCE;
    }

    /** Whether another place, at ($lat, $lon) and named $name, is this one: close by, with an equivalent name. */
    public function sameAs(float $lat, float $lon, string $name): bool
    {
        return self::metres($this->lat, $this->lon, $lat, $lon) <= self::DUPLICATE_DISTANCE && self::nameKey($name) === self::nameKey($this->name);
    }

    /** Case, accents and punctuation ignored; a name without letters or digits (emoji) compares as written. */
    public static function nameKey(string $name): string
    {
        $folded = CatalogueText::fold($name);
        return $folded === '' ? mb_strtolower(trim($name), 'UTF-8') : $folded;
    }

    private static function metres(float $lat, float $lon, float $otherLat, float $otherLon): float
    {
        return hypot(($otherLon - $lon) * cos(deg2rad($lat)) * 111320, ($otherLat - $lat) * 110574);
    }

    /** Metres from this place to the nearest point of the trail's line, between vertices included. */
    public function distanceTo(array $segments): float
    {
        $scale = cos(deg2rad($this->lat)) * 111320;
        $best = INF;
        foreach ($segments as $segment) {
            $previous = null;
            foreach ($segment as $point) {
                $current = [((float)$point[1] - $this->lon) * $scale, ((float)$point[0] - $this->lat) * 110574];
                if ($previous === null) { $best = min($best, hypot($current[0], $current[1])); }
                else {
                    [$ax, $ay] = $previous; [$bx, $by] = $current;
                    $dx = $bx - $ax; $dy = $by - $ay; $length = $dx * $dx + $dy * $dy;
                    $t = $length > 0 ? max(0, min(1, -($ax * $dx + $ay * $dy) / $length)) : 0;
                    $best = min($best, hypot($ax + $t * $dx, $ay + $t * $dy));
                }
                $previous = $current;
            }
        }
        return $best;
    }
}
