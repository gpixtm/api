<?php
declare(strict_types=1);
namespace App\Domain;
interface TrailStore
{
    /** Atomically deduplicate, compare revision, persist payload and record receipt. */
    public function apply(Mutation $mutation): array;
    public function all(): array;
}
