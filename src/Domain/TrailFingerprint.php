<?php
declare(strict_types=1);
namespace App\Domain;

/**
 * Content identity of a shared trail: its line geometry only. Names, descriptions,
 * elevations, timestamps and points of interest are ignored, so the same GPX renamed or
 * re-exported by another application is the same trail.
 *
 * Coordinates are quantized to 1e-5 degree (about one metre) with IEEE-754 arithmetic that
 * the mobile app reproduces exactly (`lib/domain/trail_identity.dart`). Consecutive equal
 * quantized points collapse, and segments left with fewer than two points add no line.
 */
final class TrailFingerprint
{
    /** Fixed namespace of name-based (version 5) public trail identifiers. */
    public const NAMESPACE = '8f5c2a61-3b7e-4d9a-a1c4-6e0b9d2f7a35';

    /** @return ?string lowercase SHA-256 hex, or null when the trail has no line to share. */
    public static function of(array $segments): ?string
    {
        $lines = [];
        foreach ($segments as $segment) {
            $points = [];
            foreach ($segment as $point) {
                $key = self::quantize((float)$point[0]).','.self::quantize((float)$point[1]);
                if ($points === [] || $points[count($points) - 1] !== $key) $points[] = $key;
            }
            if (count($points) > 1) $lines[] = implode(';', $points);
        }
        return $lines === [] ? null : hash('sha256', "gpix-trail-v1\n".implode("\n", $lines));
    }

    /** Identifier every phone derives offline for a new trail with this fingerprint. */
    public static function publicId(string $fingerprint): string
    {
        $hash = sha1(hex2bin(str_replace('-', '', self::NAMESPACE)).$fingerprint);
        $hash[12] = '5';
        $hash[16] = dechex((hexdec($hash[16]) & 0x3) | 0x8);
        return substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-'.substr($hash, 12, 4).'-'.substr($hash, 16, 4).'-'.substr($hash, 20, 12);
    }

    private static function quantize(float $degrees): int { return (int)floor($degrees * 100000 + 0.5); }
}
