<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Infrastructure\Catalogue\CatalogueRuns;
use App\Infrastructure\Catalogue\CatalogueWriter;
use App\Infrastructure\Catalogue\GeofabrikExtracts;
use App\Infrastructure\Catalogue\OsmAcquisition;
use App\Infrastructure\Catalogue\Osmium;
use App\Infrastructure\Database;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Open-data catalogue acquisition. `acquire FR` queues France for the worker; the worker
 * (`work`) runs queued countries one at a time and queues acquired countries every night.
 */
ini_set('memory_limit', getenv('CATALOGUE_MEMORY') ?: '3G');
$directory = rtrim(getenv('CATALOGUE_DIR') ?: '/data/catalogue', '/');
$acquisition = function (\PDO $db) use ($directory): OsmAcquisition {
    if (!is_dir($directory) && !mkdir($directory, 0775, true)) throw new RuntimeException("Cannot create $directory.");
    return new OsmAcquisition(new GeofabrikExtracts(HttpClient::create(), $directory), new Osmium(), new CatalogueWriter($db), $directory);
};
$execute = function (\PDO $db, int $run, string $country, ?string $file, OutputInterface $output) use ($acquisition): int {
    $runs = new CatalogueRuns($db);
    $log = fn(string $line) => $output->writeln(gmdate('H:i:s')." [run $run $country] $line");
    try {
        $stats = $acquisition($db)->run($run, $country, $file, $log);
        $runs->succeed($run, $stats);
        $log('Succeeded: '.json_encode($stats, JSON_UNESCAPED_UNICODE));
        return Command::SUCCESS;
    } catch (Throwable $e) {
        $runs->fail($run, $e->getMessage());
        $log('Failed: '.$e->getMessage());
        return Command::FAILURE;
    }
};

$app = new Application('Gpix catalogue');
$app->addCommand((new Command('acquire'))
    ->setDescription('Queue the acquisition of a whole country (ISO code, such as FR) from OpenStreetMap')
    ->addArgument('country', InputArgument::REQUIRED)
    ->addOption('now', null, InputOption::VALUE_NONE, 'Run in this process instead of queueing for the worker')
    ->addOption('file', null, InputOption::VALUE_REQUIRED, 'With --now: use this local .osm.pbf extract instead of downloading')
    ->setCode(function (InputInterface $input, OutputInterface $output) use ($execute): int {
        $db = Database::connect();
        $country = strtoupper((string)$input->getArgument('country'));
        $runs = new CatalogueRuns($db);
        if (!$input->getOption('now')) {
            $output->writeln(sprintf('Run %d queued for %s; the catalogue worker starts it within a minute.', $runs->queue($country), $country));
            return Command::SUCCESS;
        }
        if (!preg_match('/^[A-Z]{2}$/D', $country)) throw new InvalidArgumentException('Country must be an ISO 3166-1 alpha-2 code, such as FR.');
        return $execute($db, $runs->start($country), $country, $input->getOption('file'), $output);
    }));
$app->addCommand((new Command('work'))
    ->setDescription('Run queued acquisitions one at a time and queue acquired countries every night')
    ->addOption('once', null, InputOption::VALUE_NONE, 'Process the queue, then exit')
    ->setCode(function (InputInterface $input, OutputInterface $output) use ($execute): int {
        $once = (bool)$input->getOption('once');
        $recovered = false;
        do {
            try {
                $db = Database::connect();
                $runs = new CatalogueRuns($db);
                if (!$recovered) { $recovered = true; if ($runs->recover() > 0) $output->writeln('Interrupted runs marked failed.'); }
                if (!$once) foreach ($runs->nightly(new DateTimeImmutable()) as $id) $output->writeln("Nightly run $id queued.");
                while (($run = $runs->claim()) !== null) $execute($db, $run['id'], $run['country'], null, $output);
                $db = null;
            } catch (Throwable $e) {
                fwrite(STDERR, 'Catalogue worker unavailable: '.$e->getMessage()."\n");
            }
            if (!$once) sleep(30);
        } while (!$once);
        return Command::SUCCESS;
    }));
$app->addCommand((new Command('runs'))
    ->setDescription('List the latest acquisition runs')
    ->setCode(function (InputInterface $input, OutputInterface $output): int {
        foreach ((new CatalogueRuns(Database::connect()))->latest() as $run) {
            $output->writeln(sprintf('%5d  %s  %-11s  queued %s  finished %s  %s%s', $run['id'], $run['country'], $run['status'], $run['queued'], $run['finished'] ?? '-',
                $run['stats'], $run['error'] ? '  error: '.$run['error'] : ''));
        }
        return Command::SUCCESS;
    }));
$app->addCommand((new Command('rollback'))
    ->setDescription('Hide the trails and groups a run created')
    ->addArgument('run', InputArgument::REQUIRED)
    ->setCode(function (InputInterface $input, OutputInterface $output): int {
        $hidden = (new CatalogueRuns(Database::connect()))->rollback((int)$input->getArgument('run'));
        $output->writeln(sprintf('Hidden %d trails and %d groups.', $hidden['trails'], $hidden['groups']));
        return Command::SUCCESS;
    }));
$app->run();
