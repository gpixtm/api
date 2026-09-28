<?php
declare(strict_types=1);
namespace App\Infrastructure\Catalogue;

use App\Domain\Catalogue\OsmCataloguePlan;

/**
 * One country's acquisition from OpenStreetMap: download (or reuse) the Geofabrik extract,
 * keep the walking routes with osmium, read them into memory and write the catalogue.
 * Working files live in the catalogue directory, outside the database volume.
 */
final readonly class OsmAcquisition
{
    public function __construct(private GeofabrikExtracts $extracts, private Osmium $osmium, private CatalogueWriter $writer, private string $directory) {}

    /**
     * @param ?string $file a local extract to use instead of downloading the country's
     * @return array<string,int|float|string> statistics of the run
     */
    public function run(int $run, string $country, ?string $file = null, ?callable $log = null): array
    {
        $log ??= fn(string $line) => null;
        $started = microtime(true);
        $work = $this->directory.'/work-'.$run;
        if (!is_dir($work) && !mkdir($work, 0775, true)) throw new \RuntimeException("Cannot create $work.");
        try {
            $extract = $file ?? $this->extracts->download($country, $log);
            $log('Filtering walking routes with '.$this->osmium->version());
            $routes = $this->osmium->routes($extract, $work);
            $data = OsmExtract::read($this->osmium->objects($routes));
            $log(sprintf('Read %d relations and %d ways (%.0f MB)', count($data->relations), $data->wayCount(), memory_get_peak_usage(true) / 1e6));
            $plan = OsmCataloguePlan::of($data->relations);
            $log(sprintf('Planned %d trails and %d groups; %d Ways of St James', count($plan->trails), count($plan->groups), count($plan->stJames)));
            $stats = $this->writer->write($run, $country, $plan, $data, $log);
            return $plan->stats + $stats + [
                'seconds' => (int)round(microtime(true) - $started),
                'peakMegabytes' => (int)round(memory_get_peak_usage(true) / 1e6),
                'extract' => basename($extract),
            ];
        } finally {
            foreach (glob($work.'/*') ?: [] as $path) @unlink($path);
            @rmdir($work);
        }
    }
}
