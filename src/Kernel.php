<?php
declare(strict_types=1);
namespace App;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use App\Presentation\ApiController;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;
    public function registerBundles(): iterable { yield new \Symfony\Bundle\FrameworkBundle\FrameworkBundle(); }
    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', ['secret' => '%env(APP_SECRET)%', 'http_method_override' => false, 'handle_all_throwables' => true, 'router' => ['utf8' => true], 'http_client' => []]);
        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->load('App\\', __DIR__.'/')->exclude([__DIR__.'/Kernel.php', __DIR__.'/Domain/Mutation.php', __DIR__.'/Fixtures/']);
        $services->set(\PDO::class, \PDO::class)->factory([Infrastructure\Database::class, 'connect']);
        $services->alias(Domain\Authentication::class, Infrastructure\Accounts::class);
        $services->alias(Domain\TrailStore::class, Infrastructure\PostgresTrailStore::class);
        $services->alias(Domain\Elevations::class, Infrastructure\OpenTopoElevations::class);
        $services->set(Presentation\AuthController::class)->public()->tag('controller.service_arguments');
        $services->set(ApiController::class)->public()->tag('controller.service_arguments');
    }
    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        foreach(['register','login','me','refresh','logout','forgot-password','reset-password','change-password'] as $action) {
            $routes->add('auth_'.$action,'/api/auth/'.$action)->controller([Presentation\AuthController::class,'handle'])->defaults(['action'=>$action])->methods([$action==='me'?'GET':'POST']);
        }
        $routes->add('health', '/health')->controller([ApiController::class, 'health'])->methods(['GET']);
        $routes->add('trails', '/api/trails')->controller([ApiController::class, 'trails'])->methods(['GET']);
        $routes->add('statistics', '/api/statistics')->controller([ApiController::class, 'statistics'])->methods(['GET']);
        $routes->add('sync', '/api/sync')->controller([ApiController::class, 'sync'])->methods(['POST']);
        $routes->add('regions', '/api/regions')->controller([ApiController::class, 'regions'])->methods(['GET']);
        $routes->add('package', '/api/packages/{name}')->controller([ApiController::class, 'package'])->methods(['GET']);
        $routes->add('elevations', '/api/elevations')->controller([ApiController::class, 'elevations'])->methods(['POST']);
        $routes->add('approach', '/api/approach')->controller([ApiController::class, 'approach'])->methods(['POST']);
    }
}
