<?php
declare(strict_types=1);
namespace App\Infrastructure;
final class Database
{
    public static function connect(): \PDO
    {
        return new \PDO('pgsql:host='.getenv('DB_HOST').';port=5432;dbname=gpix', 'gpix', getenv('DB_PASSWORD'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }
}
