<?php
declare(strict_types=1);
namespace App\Domain;

/** A place a walker adds on a shared trail, where they stand. */
final readonly class TrailPlace
{
    /** A place belongs to the trail it is added on: the walker must be this close to it. */
    public const MAXIMUM_DISTANCE = 100.0;
    public const NAME_LENGTH = 200;
    public const COMMENT_LENGTH = 2000;

    public function __construct(public float $lat, public float $lon, public ?float $elevation, public string $name, public string $comment) {}

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
        return new self((float)$point[0], (float)$point[1], $point[2] === null ? null : (float)$point[2], $name, $comment);
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
