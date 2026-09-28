<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
$app=new Application('Gpix fixtures');
$app->addCommand((new Command('fixtures'))->setDescription('Append the one personal fixture; preserve passwords and library')->setCode(function(InputInterface $input,OutputInterface $output):int {
 $password=getenv('GPIX_FIXTURE_PASSWORD');
 (new App\Fixtures\PersonalAccount(App\Infrastructure\Database::connect(),$password===false||$password===''?null:$password))->load();
 $output->writeln('Fixture walker ready. Existing password and library preserved.');return Command::SUCCESS;
}));
$app->setDefaultCommand('fixtures',true);$app->run();
