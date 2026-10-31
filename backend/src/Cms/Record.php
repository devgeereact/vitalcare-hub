<?php

declare(strict_types=1);

namespace Vitalcare\Cms;

use Vitalcare\Http\HttpError;

/**
 * A field spec maps API names to columns and input types:
 *   'firstName' => ['first_name', 'text', ['max' => 100, 'required' => true]]
 * read() validates a body against it (all fields for a create, only the fields sent for an update);
 * out() turns a database row into the API shape with proper types.
 */
final class Record
{
    /**
     * @param array<string,array{0:string,1:string,2?:array<string,mixed>}> $spec
     * @return array<string,mixed> column => value, only for the fields that are being written
     */
    public static function read(array $spec, Input $in, bool $partial): array
    {
        $out = [];
        foreach ($spec as $key => $def) {
            [$col, $type] = $def;
            $o = $def[2] ?? [];
            if (($o['readonly'] ?? false) === true || ($partial && !$in->has($key))) {
                continue;
            }
            $v = $in->value($key, $type, $o);
            if ($v === null && array_key_exists('default', $o)) {
                if ($partial) {
                    continue;   // an empty value for a NOT NULL field leaves it as it is
                }
                $v = $o['default'];
            }
            $out[$col] = is_bool($v) ? (int) $v : $v;
        }
        return $out;
    }

    /**
     * @param array<string,array{0:string,1:string,2?:array<string,mixed>}> $spec
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function out(array $spec, array $row): array
    {
        $r = ['id' => (string) $row['id']];
        foreach ($spec as $key => $def) {
            [$col, $type] = $def;
            $v = $row[$col] ?? null;
            $r[$key] = match (true) {
                $v === null => null,
                $type === 'bool' => (int) $v === 1,
                $type === 'int' => (int) $v,
                $type === 'decimal' => (float) $v,
                $type === 'datetime' => LondonTime::fromUtc((string) $v),
                default => (string) $v,
            };
        }
        return $r;
    }

    /** API names of the columns being written, for the audit log. */
    public static function names(array $spec, array $values): array
    {
        $names = [];
        foreach ($spec as $key => $def) {
            if (array_key_exists($def[0], $values)) {
                $names[] = $key;
            }
        }
        return $names;
    }

    /** @param array<string,mixed> $values column => value; column names come from a spec, never a request */
    public static function insert(\PDO $pdo, string $table, array $values): void
    {
        $cols = array_keys($values);
        $pdo->prepare('INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
            ->execute(array_values($values));
    }

    /** @param array<string,mixed> $values */
    public static function update(\PDO $pdo, string $table, string $id, array $values): void
    {
        if ($values === []) {
            return;
        }
        $set = implode(', ', array_map(fn (string $c) => "$c = ?", array_keys($values)));
        $pdo->prepare("UPDATE $table SET $set WHERE id = ?")->execute([...array_values($values), $id]);
    }

    /** @return array<string,mixed> the row, or 404 */
    public static function find(\PDO $pdo, string $sql, array $params): array
    {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        if ($row === false) {
            throw HttpError::notFound();
        }
        return $row;
    }

    /** True when a row with this id exists in the table (and, if given, the condition holds). */
    public static function exists(\PDO $pdo, string $table, string $id, string $where = '1=1'): bool
    {
        $st = $pdo->prepare("SELECT 1 FROM $table WHERE id = ? AND $where");
        $st->execute([$id]);
        return $st->fetchColumn() !== false;
    }

    /** A LIKE pattern for user text, with the wildcards in it escaped. */
    public static function like(string $q): string
    {
        return '%' . addcslashes($q, '%_\\') . '%';
    }
}
