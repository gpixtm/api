<?php
declare(strict_types=1);
namespace App\Infrastructure;
use App\Domain\Elevations;
use Symfony\Contracts\HttpClient\HttpClientInterface;
final readonly class OpenTopoElevations implements Elevations
{
    public function __construct(private HttpClientInterface $http) {}
    public function sample(array $points): array
    {
        $url = getenv('ELEVATION_URL');
        if (!$url) throw new \RuntimeException('Elevation dataset is not configured');
        $data = $this->http->request('POST', $url, ['json' => ['locations' => implode('|', array_map(fn($p) => $p[0].','.$p[1], $points))], 'timeout' => 30, 'max_redirects' => 0])->toArray();
        if (($data['status'] ?? '') !== 'OK' || count($data['results'] ?? []) !== count($points)) throw new \RuntimeException('Incomplete elevation response');
        return array_map(static fn($p) => isset($p['elevation']) ? (float)$p['elevation'] : null, $data['results']);
    }
}
