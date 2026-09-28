<?php
declare(strict_types=1);
namespace App\Infrastructure;

/**
 * Body and backpack weight the app uses to estimate active calories.
 * Account data: private to its owner, restored on another phone.
 */
final readonly class PostgresWalkerProfile
{
    public function __construct(private \PDO $db, private OwnerContext $owner) {}

    public function read(): array
    {
        $q = $this->db->prepare('SELECT weight_kg, pack_kg FROM walker_profiles WHERE user_id=?');
        $q->execute([$this->owner->requireId()]);
        $row = $q->fetch(\PDO::FETCH_ASSOC) ?: ['weight_kg' => null, 'pack_kg' => null];
        return ['weightKg' => self::number($row['weight_kg']), 'packKg' => self::number($row['pack_kg'])];
    }

    public function save(array $body): array
    {
        $weight = self::field($body, 'weightKg', 25, 300);
        $pack = self::field($body, 'packKg', 0, 60);
        $this->db->prepare('INSERT INTO walker_profiles(user_id,weight_kg,pack_kg) VALUES (?,?,?) ON CONFLICT (user_id) DO UPDATE SET weight_kg=excluded.weight_kg, pack_kg=excluded.pack_kg, updated_at=now()')
            ->execute([$this->owner->requireId(), $weight, $pack]);
        return $this->read();
    }

    private static function field(array $body, string $key, float $minimum, float $maximum): ?float
    {
        $value = $body[$key] ?? null;
        if ($value === null) return null;
        if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value < $minimum || $value > $maximum) {
            throw new \InvalidArgumentException("Invalid $key");
        }
        return round((float)$value, 1);
    }

    private static function number(mixed $value): ?float { return $value === null ? null : (float)$value; }
}
