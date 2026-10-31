<?php

declare(strict_types=1);

namespace Vitalcare\Cms;

/**
 * Who changed what. Stores the actor, the action, the record type and id, and the NAMES of the fields
 * that changed. Never a value, so the log holds no personal data (D-018 item 7).
 */
final class Audit
{
    /** @param list<string> $fields field names (camelCase API names) */
    public static function log(\PDO $pdo, ?string $actorId, string $action, string $entityType, ?string $entityId, array $fields = []): void
    {
        foreach ($fields as $f) {
            if (preg_match('/^[a-zA-Z][a-zA-Z0-9]{0,39}$/', $f) !== 1) {
                throw new \InvalidArgumentException('Audit field names must be plain identifiers');
            }
        }
        if (preg_match('/^[a-z_.]{1,40}$/', $action) !== 1 || preg_match('/^[a-z_]{1,24}$/', $entityType) !== 1) {
            throw new \InvalidArgumentException('Audit action and type must be plain identifiers');
        }
        sort($fields);
        $pdo->prepare('INSERT INTO audit_log (actor_id, action, entity_type, entity_id, fields) VALUES (?,?,?,?,?)')
            ->execute([$actorId, $action, $entityType, $entityId, substr(implode(',', array_unique($fields)), 0, 500)]);
    }
}
