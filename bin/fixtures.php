<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Fixtures\LegacyOwnerAccount;
use App\Infrastructure\Database;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

$app = new Application('Gpix fixtures');
$app->addCommand((new Command('fixtures'))
    ->setDescription('Append the development historical-owner account; preserve passwords and library')
    ->setCode(function (InputInterface $input, OutputInterface $output): int {
        $setting = static fn (string $name, string $default): string => ($value = getenv($name)) === false || $value === '' ? $default : $value;
        $username = $setting('GPIX_FIXTURE_USERNAME', 'walker');
        $email = $setting('GPIX_FIXTURE_EMAIL', 'walker@example.test');
        $password = getenv('GPIX_FIXTURE_PASSWORD');
        (new LegacyOwnerAccount(Database::connect(), $username, $email, $password === false || $password === '' ? null : $password))->load();
        $output->writeln("Fixture $username ready. Existing password and library preserved.");

        return Command::SUCCESS;
    }));
$app->setDefaultCommand('fixtures', true);
$app->run();
