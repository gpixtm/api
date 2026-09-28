<?php
declare(strict_types=1);
namespace App\Infrastructure;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final readonly class WalkingDirections
{
    public function __construct(private HttpClientInterface $http) {}

    public function route(array $request): array
    {
        $locations = [];
        foreach (['origin', 'destination'] as $key) {
            $p = $request[$key] ?? null;
            if (!is_array($p) || !array_is_list($p) || count($p) !== 2 ||
                !is_numeric($p[0]) || !is_numeric($p[1]) ||
                !is_finite((float)$p[0]) || !is_finite((float)$p[1]) ||
                abs((float)$p[0]) > 90 || abs((float)$p[1]) > 180) {
                throw new \InvalidArgumentException('Invalid origin or destination coordinates.');
            }
            $locations[] = ['lat' => (float)$p[0], 'lon' => (float)$p[1], 'search_cutoff' => 100];
        }
        // Shared across PHP workers: respect the public provider's 1 request/s limit.
        $lock = fopen(sys_get_temp_dir().'/gpix-routing-limit', 'c+');
        if (!$lock || !flock($lock, LOCK_EX)) throw new \RuntimeException('Routing limit unavailable');
        try {
            $last = (float)stream_get_contents($lock);
            if (microtime(true) - $last < 1.05) throw new TooManyRequestsHttpException(2, 'Wait two seconds before recalculating.');
            ftruncate($lock, 0); rewind($lock); fwrite($lock, (string)microtime(true)); fflush($lock);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
        $url = getenv('ROUTING_URL') ?: 'https://valhalla1.openstreetmap.de/route';
        $data = $this->http->request('POST', $url, [
            'headers' => ['User-Agent' => 'Gpix/1.0 (walking route planner)'],
            'json' => ['locations' => $locations, 'costing' => 'pedestrian', 'language' => ($request['language'] ?? 'en') === 'fr' ? 'fr-FR' : 'en-US', 'units' => 'kilometers'],
            'timeout' => 20, 'max_duration' => 25, 'max_redirects' => 0,
        ])->toArray();
        $trip = $data['trip'] ?? [];
        $leg = $trip['legs'][0] ?? [];
        if (($trip['status'] ?? -1) !== 0 || count($trip['legs'] ?? []) !== 1 ||
            !is_string($leg['shape'] ?? null) || !is_array($leg['maneuvers'] ?? null)) {
            throw new \RuntimeException('No walking route');
        }
        return ['shape' => $leg['shape'], 'seconds' => $trip['summary']['time'],
            'steps' => array_map(static fn($step) => [
                'index' => $step['begin_shape_index'], 'instruction' => $step['instruction'],
            ], $leg['maneuvers'])];
    }
}
