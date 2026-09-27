<?php
declare(strict_types=1);
namespace App\Domain;
/** Shared account invariants, independent of HTTP, persistence and crypto. */
final class AccountRules
{
    public static function password(string $value): void
    {
        $length = preg_match_all('/./us', $value);
        if ($length === false || $length < 8 || $length > 128) {
            throw new \InvalidArgumentException('Le mot de passe doit contenir de 8 à 128 caractères.');
        }
    }
    public static function username(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/D', $value)) {
            throw new \InvalidArgumentException('Identifiant : 3 à 32 lettres, chiffres ou _.');
        }
        return $value;
    }
    public static function email(string $value): string
    {
        $value = strtolower(trim($value));
        if (strlen($value) > 254 || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Adresse email invalide.');
        }
        return $value;
    }
}
