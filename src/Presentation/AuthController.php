<?php
declare(strict_types=1);
namespace App\Presentation;

use App\Application\Authenticate;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

final readonly class AuthController
{
    public function __construct(private Authenticate $auth) {}

    public function handle(Request $request, string $action): JsonResponse
    {
        if (strlen($request->getContent()) > 8192) {
            throw new \InvalidArgumentException('Formulaire trop volumineux.');
        }
        $values = $request->getContent() !== '' ? $request->toArray() : [];
        $access = preg_replace('/^Bearer /', '', $request->headers->get('Authorization', ''));
        $result = $this->auth->run($action, $values, $request->attributes->get('user'), $access, $request->getClientIp() ?? 'unknown');
        return new JsonResponse($result, 200, ['Cache-Control' => 'no-store']);
    }
}
