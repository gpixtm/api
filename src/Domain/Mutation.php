<?php
declare(strict_types=1);
namespace App\Domain;
final readonly class Mutation
{
    public function __construct(public string $id, public string $operationId, public int $baseRevision, public bool $deleted, public array $payload) {}
    public static function fromArray(array $data): self
    {
        foreach (['id', 'mutation'] as $key) {
            if (!is_string($data[$key] ?? null) || !preg_match('/^[a-f0-9-]{36}$/D', $data[$key])) {
                throw new \InvalidArgumentException('Invalid identity');
            }
        }
        if (!is_int($data['baseRevision'] ?? null) || $data['baseRevision'] < 0 || !is_bool($data['deleted'] ?? null)) {
            throw new \InvalidArgumentException('Invalid revision or deletion flag');
        }
        $p = $data['payload'] ?? null;
        if (!is_array($p) || ($p['id'] ?? '') !== $data['id'] || !is_string($p['name'] ?? null) || strlen($p['name']) > 1000 ||
            !is_array($p['segments'] ?? null) || !is_array($p['pois'] ?? null) ||
            !is_string($p['description'] ?? '') || strlen($p['description'] ?? '') > 100000 || !is_bool($p['estimated'] ?? false)) {
            throw new \InvalidArgumentException('Invalid trail payload');
        }
        $count = 0;
        foreach ($p['segments'] as $segment) {
            if (!is_array($segment)) throw new \InvalidArgumentException('Invalid segment');
            foreach ($segment as $point) { self::validatePoint($point); ++$count; }
        }
        foreach ($p['pois'] as $poi) {
            self::validatePoint($poi['point'] ?? null);
            if (!is_string($poi['name'] ?? null) || !is_string($poi['description'] ?? null) || strlen($poi['description']) > 100000) {
                throw new \InvalidArgumentException('Invalid point of interest');
            }
            ++$count;
        }
        if ($count > 500000) throw new \InvalidArgumentException('Too many points');
        if (isset($p['walk'])) self::validateWalk($p['walk'], count($p['segments']));
        if (isset($p['days'])) {
            if (!is_array($p['days']) || !array_is_list($p['days']) || count($p['days']) > 1000) {
                throw new \InvalidArgumentException('Invalid walking days');
            }
            $length = 0.0;
            foreach ($p['segments'] as $segment) {
                for ($i = 1; $i < count($segment); ++$i) {
                    $a = $segment[$i - 1]; $b = $segment[$i];
                    $h = sin(deg2rad($b[0] - $a[0]) / 2) ** 2 + cos(deg2rad($a[0])) * cos(deg2rad($b[0])) * sin(deg2rad($b[1] - $a[1]) / 2) ** 2;
                    $length += 6371008.8 * 2 * asin(sqrt(min(1, max(0, $h))));
                }
            }
            foreach ($p['days'] as $day) {
                if (!is_array($day) || !array_is_list($day) || count($day) !== 2) throw new \InvalidArgumentException('Invalid walking day');
                foreach ($day as $boundary) {
                    if ((!is_int($boundary) && !is_float($boundary)) || !is_finite((float)$boundary) || $boundary < 0 || $boundary > $length + 0.01) throw new \InvalidArgumentException('Walking day outside trail');
                }
                if (abs($day[1] - $day[0]) < 10) throw new \InvalidArgumentException('Walking day too short');
            }
        }
        return new self($data['id'], $data['mutation'], $data['baseRevision'], $data['deleted'], $p);
    }
    public static function validatePoint(mixed $point): void
    {
        if (!is_array($point) || count($point) !== 3 || !is_numeric($point[0]) || !is_numeric($point[1]) ||
            !is_finite((float)$point[0]) || !is_finite((float)$point[1]) || abs((float)$point[0]) > 90 || abs((float)$point[1]) > 180 ||
            ($point[2] !== null && (!is_numeric($point[2]) || !is_finite((float)$point[2])))) {
            throw new \InvalidArgumentException('Invalid coordinate');
        }
    }

    private static function instant(mixed $value): float
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/D', $value)) throw new \InvalidArgumentException('Invalid recording time');
        try { return (float)(new \DateTimeImmutable($value))->format('U.u'); }
        catch (\Exception) { throw new \InvalidArgumentException('Invalid recording time'); }
    }

    private static function validateWalk(mixed $walk, int $segments): void
    {
        if (!is_array($walk)) throw new \InvalidArgumentException('Invalid recording');
        $start = self::instant($walk['started'] ?? null);
        $end = self::instant($walk['ended'] ?? null);
        if ($end < $start || !is_int($walk['seconds'] ?? null) || $walk['seconds'] < 0 || $walk['seconds'] > $end - $start + 1) throw new \InvalidArgumentException('Invalid recording duration');
        $source = $walk['sourceTrailId'] ?? null;
        if ($source !== null && (!is_string($source) || !preg_match('/^[a-f0-9-]{36}$/D', $source))) throw new \InvalidArgumentException('Invalid recording source');
        $samples = $walk['samples'] ?? [];
        if (!is_array($samples) || !array_is_list($samples) || count($samples) > 500000) throw new \InvalidArgumentException('Invalid recording samples');
        $previous = $start - 15;
        foreach ($samples as $sample) {
            if (!is_array($sample) || !array_is_list($sample) || count($sample) !== 6) throw new \InvalidArgumentException('Invalid recording sample');
            $time = self::instant($sample[0]);
            self::validatePoint(array_slice($sample, 1, 3));
            if ($time < $previous || $time > $end + 5 || !is_numeric($sample[4]) || !is_finite((float)$sample[4]) || $sample[4] < 0 || !is_int($sample[5]) || $sample[5] < 0 || $sample[5] >= $segments) throw new \InvalidArgumentException('Invalid recording sample values');
            $previous = $time;
        }
        if (isset($walk['health'])) {
            $health = $walk['health'];
            if (!is_array($health)) throw new \InvalidArgumentException('Invalid health summary');
            self::instant($health['readAt'] ?? null);
            if (!is_array($health['sources'] ?? null) || count($health['sources']) > 100) throw new \InvalidArgumentException('Invalid health sources');
            foreach ($health['sources'] as $source) if (!is_string($source) || strlen($source) > 255) throw new \InvalidArgumentException('Invalid health source');
            foreach (['steps', 'activeCalories', 'averageHeartRate', 'maxHeartRate'] as $key) {
                $value = $health[$key] ?? null;
                if ($value !== null && ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value < 0)) throw new \InvalidArgumentException('Invalid health metric');
            }
            if (isset($health['steps']) && !is_int($health['steps'])) throw new \InvalidArgumentException('Invalid step count');
        }
    }
}
