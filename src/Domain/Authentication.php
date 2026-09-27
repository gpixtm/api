<?php
declare(strict_types=1);
namespace App\Domain;
interface Authentication {
 public function throttle(string $kind,string $key,int $limit): void;
 public function authenticate(string $token): array;
 public function register(array $values): array;
 public function login(array $values): array;
 public function refresh(string $token): array;
 public function logout(string $refresh,string $access): void;
 public function forgot(string $email): void;
 public function reset(string $token,string $password): void;
 public function change(string $id,string $current,string $password): void;
}
