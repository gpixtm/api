<?php
declare(strict_types=1);
namespace App\Presentation;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ApiEvents implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array { return ['kernel.request' => ['authenticate', 64], 'kernel.exception' => ['error', 0]]; }
    public function __construct(private \App\Application\Authenticate $auth,private \App\Infrastructure\OwnerContext $owner){}
    public function authenticate(RequestEvent $event): void {
        $this->owner->id=null;$request=$event->getRequest();
        if(in_array($request->getPathInfo(),['/health','/api/auth/register','/api/auth/login','/api/auth/refresh','/api/auth/logout','/api/auth/forgot-password','/api/auth/reset-password'],true))return;
        $header=$request->headers->get('Authorization','');if(!str_starts_with($header,'Bearer '))throw new \Symfony\Component\HttpKernel\Exception\HttpException(401,'Connectez-vous pour continuer.');
        $user=$this->auth->identify(substr($header,7));$this->owner->id=$user['id'];$request->attributes->set('user',$user);
    }
    public function error(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();
        $status = $e instanceof \InvalidArgumentException ? 422 : ($e instanceof HttpExceptionInterface ? $e->getStatusCode() : 503);
        $event->setResponse(new JsonResponse(['error' => in_array($status,[401,422,429],true) ? $e->getMessage() : 'Service indisponible ou ressource introuvable'], $status));
    }
}
