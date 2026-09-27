<?php
declare(strict_types=1);
namespace App\Application;
use App\Domain\Mutation;
use App\Domain\TrailStore;
final readonly class SyncLibrary
{
    public function __construct(private TrailStore $store) {}
    public function apply(array $request): array { return $this->store->apply(Mutation::fromArray($request)); }
    public function all(): array { return $this->store->all(); }
}
