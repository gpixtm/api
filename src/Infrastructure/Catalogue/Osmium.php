<?php
declare(strict_types=1);
namespace App\Infrastructure\Catalogue;

/**
 * Runs osmium-tool, which reads PBF extracts far faster and in far less memory than PHP could:
 * it keeps the walking route relations with the ways they use, adds node locations to those
 * ways and streams them as OPL text.
 */
final readonly class Osmium
{
    public function __construct(private string $binary = 'osmium') {}

    /** Filters an extract into `<directory>/routes.pbf` and returns that path. */
    public function routes(string $extract, string $directory): string
    {
        $filtered = $directory.'/routes-filtered.pbf';
        $located = $directory.'/routes.pbf';
        $this->run([$this->binary, 'tags-filter', '--overwrite', '--no-progress', '-o', $filtered, $extract, 'r/route=hiking,foot,walking']);
        $this->run([$this->binary, 'add-locations-to-ways', '--overwrite', '--no-progress', '--ignore-missing-nodes', '-o', $located, $filtered]);
        @unlink($filtered);
        return $located;
    }

    /** @return \Generator<array> ways and relations of a filtered file, see OplReader */
    public function objects(string $routes): \Generator
    {
        $errors = tmpfile();
        // OPL only writes the node locations of ways when asked to.
        $process = proc_open([$this->binary, 'cat', '--no-progress', '-t', 'way', '-t', 'relation', '-f', 'opl,locations_on_ways=true', '-o', '-', $routes],
            [1 => ['pipe', 'w'], 2 => $errors], $pipes);
        if (!is_resource($process)) throw new \RuntimeException('Unable to start osmium.');
        try {
            yield from OplReader::read($pipes[1]);
        } finally {
            fclose($pipes[1]);
            $status = proc_close($process);
            if ($status !== 0) throw new \RuntimeException('osmium cat failed: '.self::tail($errors));
        }
    }

    public function version(): string
    {
        return strtok($this->run([$this->binary, '--version']), "\n") ?: '';
    }

    private function run(array $command): string
    {
        $errors = tmpfile();
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => $errors], $pipes);
        if (!is_resource($process)) throw new \RuntimeException('Unable to start osmium.');
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (proc_close($process) !== 0) throw new \RuntimeException('osmium '.$command[1].' failed: '.self::tail($errors));
        return (string)$output;
    }

    /** @param resource $stream */
    private static function tail($stream): string
    {
        rewind($stream);
        return trim(substr((string)stream_get_contents($stream), -2000));
    }
}
