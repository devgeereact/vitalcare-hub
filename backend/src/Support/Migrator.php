<?php

declare(strict_types=1);

namespace Vitalcare\Support;

/**
 * Applies numbered SQL files from backend/migrations in filename order.
 * An applied file is never edited: its SHA-256 is recorded, and a changed file stops the run.
 * Statements are split on lines holding only `;;` so a file may contain triggers or procedures.
 */
final class Migrator
{
    public function __construct(private readonly \PDO $pdo, private readonly string $dir)
    {
    }

    /** @return list<string> names applied in this run */
    public function run(): array
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
               name VARCHAR(190) NOT NULL PRIMARY KEY,
               sha256 CHAR(64) NOT NULL,
               applied_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $done = [];
        foreach ($this->pdo->query('SELECT name, sha256 FROM schema_migrations') ?: [] as $r) {
            $done[(string) $r['name']] = (string) $r['sha256'];
        }

        $files = glob(rtrim($this->dir, '/') . '/*.sql') ?: [];
        sort($files);
        $applied = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (preg_match('/^\d{3,}[-_][a-z0-9_-]+\.sql$/', $name) !== 1) {
                throw new \RuntimeException("Migration file name is not numbered: $name");
            }
            $sql = (string) file_get_contents($file);
            $hash = hash('sha256', $sql);
            if (isset($done[$name])) {
                if ($done[$name] !== $hash) {
                    throw new \RuntimeException("Applied migration was edited: $name");
                }
                continue;
            }
            foreach (self::statements($sql) as $stmt) {
                $this->pdo->exec($stmt);
            }
            $ins = $this->pdo->prepare('INSERT INTO schema_migrations (name, sha256) VALUES (:n, :h)');
            $ins->execute([':n' => $name, ':h' => $hash]);
            $applied[] = $name;
        }
        return $applied;
    }

    /** @return list<string> */
    public static function statements(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $parts = str_contains($sql, "\n;;") ? preg_split('/^;;\s*$/m', $sql) : explode(';', $sql);
        $out = [];
        foreach ($parts ?: [] as $p) {
            $p = trim($p);
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return $out;
    }
}
