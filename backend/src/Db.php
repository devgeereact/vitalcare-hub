<?php

declare(strict_types=1);

namespace Vitalcare;

final class Db
{
    /** @param array{dsn:string,user:string,password:string} $c */
    public static function connect(array $c): \PDO
    {
        $pdo = new \PDO($c['dsn'], $c['user'], $c['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00', sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
        return $pdo;
    }

    /** UUIDv7: time-ordered, so primary-key inserts stay append-mostly. */
    public static function uuid(?int $unixMs = null): string
    {
        $ms = $unixMs ?? (int) floor(microtime(true) * 1000);
        $rand = random_bytes(10);
        $b = substr(pack('J', $ms), 2, 6) . $rand;
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x70);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
