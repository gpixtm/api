<?php
declare(strict_types=1);
namespace App\Presentation;
use App\Application\SyncLibrary;
use App\Application\CompleteElevations;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
final readonly class ApiController
{
    public function __construct(private SyncLibrary $library, private CompleteElevations $elevations, private \App\Infrastructure\WalkingDirections $directions, private \App\Infrastructure\PostgresTrailStatistics $statistics, private \App\Infrastructure\PostgresPublicTrails $public) {}
    public function statistics(): JsonResponse { return new JsonResponse($this->statistics->all()); }
    public function approach(Request $request): JsonResponse { return new JsonResponse($this->directions->route($this->body($request))); }
    public function publicTrails(Request $request): JsonResponse
    {
        return new JsonResponse($this->public->index($request->query->getInt('since'), $request->query->getInt('limit', \App\Infrastructure\PostgresPublicTrails::PAGE)));
    }
    public function publicTrail(string $id): JsonResponse { return new JsonResponse($this->public->find($id)); }
    public function reviews(string $id): JsonResponse { return new JsonResponse($this->public->reviews($id)); }
    public function review(Request $request, string $id): JsonResponse
    {
        return new JsonResponse($request->isMethod('DELETE') ? $this->public->removeReview($id) : $this->public->review($id, $this->body($request)));
    }
    public function places(Request $request): JsonResponse
    {
        return new JsonResponse($this->public->places($request->query->getInt('since'), $request->query->getInt('limit', \App\Infrastructure\PostgresPublicTrails::PAGE)));
    }
    public function place(Request $request, string $id, string $place): JsonResponse
    {
        return new JsonResponse($request->isMethod('DELETE') ? $this->public->removePlace($id, $place) : $this->public->savePlace($id, $place, $this->body($request)));
    }
    public function health(): JsonResponse { return new JsonResponse(['status' => 'ok']); }
    public function trails(): JsonResponse { return new JsonResponse($this->library->all()); }
    public function sync(Request $request): JsonResponse { return new JsonResponse($this->library->apply($this->body($request))); }
    public function elevations(Request $request): JsonResponse { return new JsonResponse($this->elevations->run($this->body($request))); }
    public function regions(): JsonResponse
    {
        $file = '/data/maps/catalog.json';
        return new JsonResponse(is_file($file) ? json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : []);
    }
    public function package(string $name): BinaryFileResponse
    {
        if (!preg_match('/^[a-zA-Z0-9_.-]+\.zip$/D', $name) || !is_file('/data/maps/'.$name)) throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        return new BinaryFileResponse('/data/maps/'.$name);
    }
    private function body(Request $request): array
    {
        if (strlen($request->getContent()) > 50 * 1024 * 1024) throw new \InvalidArgumentException('Payload exceeds 50 MiB');
        return $request->toArray();
    }
}
