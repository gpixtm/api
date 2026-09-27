<?php
declare(strict_types=1);
namespace App\Infrastructure;
final class OwnerContext { public ?string $id=null; public function requireId(): string {return $this->id??throw new \LogicException('Missing authenticated owner');} }
