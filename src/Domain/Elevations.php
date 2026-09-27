<?php
declare(strict_types=1);
namespace App\Domain;
interface Elevations { public function sample(array $points): array; }
