<?php
declare(strict_types=1);
namespace App\Infrastructure\Catalogue;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Country extracts of OpenStreetMap published daily by Geofabrik (ODbL). A country code is
 * resolved through Geofabrik's index; a file already downloaded is only fetched again when
 * Geofabrik published a newer one.
 */
final readonly class GeofabrikExtracts
{
    public const INDEX = 'https://download.geofabrik.de/index-v1-nogeom.json';
    public const USER_AGENT = 'Gpix-catalogue/1.0 (+https://api.example.org)';

    public function __construct(private HttpClientInterface $http, private string $directory) {}

    public function url(string $country): string
    {
        $index = $this->http->request('GET', self::INDEX, ['headers' => ['User-Agent' => self::USER_AGENT], 'timeout' => 60])->toArray();
        $best = null;
        foreach ($index['features'] ?? [] as $feature) {
            $properties = $feature['properties'] ?? [];
            $codes = $properties['iso3166-1:alpha2'] ?? [];
            $url = $properties['urls']['pbf'] ?? null;
            if (!in_array($country, $codes, true) || !is_string($url)) continue;
            // A region covering only this country beats one shared with neighbours.
            if ($best === null || count($codes) < $best[0]) $best = [count($codes), $url];
        }
        if ($best === null) throw new \InvalidArgumentException("No Geofabrik extract for country $country.");
        return $best[1];
    }

    /** Returns the local extract of a country, downloading it when missing or outdated. */
    public function download(string $country, ?callable $log = null): string
    {
        $log ??= fn(string $line) => null;
        $url = $this->url($country);
        $path = $this->directory.'/'.strtolower($country).'.osm.pbf';
        $headers = ['User-Agent' => self::USER_AGENT];
        if (is_file($path)) $headers['If-Modified-Since'] = gmdate('D, d M Y H:i:s', (int)filemtime($path)).' GMT';
        $response = $this->http->request('GET', $url, ['headers' => $headers, 'timeout' => 300, 'max_duration' => 0, 'buffer' => false]);
        if ($response->getStatusCode() === 304) { $log("Extract $url unchanged."); return $path; }
        if ($response->getStatusCode() !== 200) throw new \RuntimeException("Geofabrik answered {$response->getStatusCode()} for $url.");
        $log("Downloading $url");
        $part = $path.'.part';
        $file = fopen($part, 'wb') ?: throw new \RuntimeException("Cannot write $part.");
        try {
            $written = 0; $reported = 0;
            foreach ($this->http->stream($response) as $chunk) {
                $data = $chunk->getContent();
                if (fwrite($file, $data) !== strlen($data)) throw new \RuntimeException("Disk full while writing $part.");
                $written += strlen($data);
                if ($written - $reported >= 512 * 1024 * 1024) { $reported = $written; $log(sprintf('Downloaded %.1f GB', $written / 1e9)); }
            }
        } finally { fclose($file); }
        $modified = strtotime($response->getHeaders()['last-modified'][0] ?? '') ?: time();
        rename($part, $path);
        touch($path, $modified);
        return $path;
    }
}
