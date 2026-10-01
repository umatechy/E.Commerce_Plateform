<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Services;

/**
 * The single definition of how an audit entry is canonicalized and
 * hashed, shared by the writer (AuditLogger) and the checker
 * (AuditChainVerifier) so the two can never drift apart.
 */
final class AuditHasher
{
    /** Every column that is part of the tamper-evident content, in hashing order. */
    public const HASHED_COLUMNS = [
        'public_id', 'store_id', 'chain_key', 'sequence', 'action',
        'actor_type', 'actor_id', 'actor_public_id', 'actor_label',
        'impersonator_id', 'impersonator_label', 'surface',
        'subject_type', 'subject_id', 'subject_public_id',
        'context', 'ip_address', 'user_agent', 'previous_hash', 'created_at',
    ];

    private const INTEGER_COLUMNS = ['store_id', 'sequence', 'actor_id', 'impersonator_id', 'subject_id'];

    /** @param array<string, mixed> $row raw column values */
    public static function hash(array $row): string
    {
        $fields = [];

        foreach (self::HASHED_COLUMNS as $column) {
            $value = $row[$column] ?? null;
            // Normalize driver differences (ints may come back as strings).
            $fields[$column] = $value === null ? null : (in_array($column, self::INTEGER_COLUMNS, true) ? (int) $value : (string) $value);
        }

        // Added in Phase B29. Hashed only when present, so entries written
        // before the column existed still verify, and one written with a
        // request ID cannot have it changed or removed unnoticed.
        if (($row['request_id'] ?? null) !== null) {
            $fields['request_id'] = (string) $row['request_id'];
        }

        return hash('sha256', json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * Canonical JSON for a context array: associative keys sorted at every
     * depth, lists kept in order, fixed encoding flags.
     *
     * @param array<array-key, mixed> $context
     */
    public static function canonicalJson(array $context): string
    {
        return json_encode(self::sortKeys($context), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function sortKeys(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortKeys($item);
            }
        }

        return $value;
    }
}
