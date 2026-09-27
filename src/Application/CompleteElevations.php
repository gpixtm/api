<?php
declare(strict_types=1);
namespace App\Application;
use App\Domain\Elevations;
use App\Domain\Mutation;
final readonly class CompleteElevations
{
    public function __construct(private Elevations $source) {}
    public function run(array $request): array
    {
        $points = $request['points'] ?? [];
        if (!is_array($points) || count($points) < 1 || count($points) > 100) throw new \InvalidArgumentException('Expected 1–100 points');
        foreach ($points as $p) {
            if (!is_array($p) || count($p) !== 2) throw new \InvalidArgumentException('Invalid point');
            Mutation::validatePoint([$p[0], $p[1], null]);
        }
        return ['elevations' => $this->source->sample($points), 'estimated' => true];
    }
}
