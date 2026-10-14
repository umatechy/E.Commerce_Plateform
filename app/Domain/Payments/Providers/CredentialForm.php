<?php

declare(strict_types=1);

namespace App\Domain\Payments\Providers;

use Illuminate\Validation\ValidationException;

/**
 * Phase B48/B49: the one way provider credentials are taken from a form —
 * for payment providers and couriers alike.
 *
 * - A blank field keeps the stored value (secrets are write-only).
 * - Lengths and shapes are checked against the provider's own fields.
 * - Hints: secrets become "set"; other values are masked ("MC••••01").
 */
final class CredentialForm
{
    /**
     * @param list<CredentialField> $fields
     * @param array<string, mixed> $given
     * @param array<string, mixed> $stored values kept when a field is left empty
     * @return array<string, string>
     * @throws ValidationException
     */
    public static function values(array $fields, array $given, array $stored): array
    {
        $values = [];
        $errors = [];
        foreach ($fields as $field) {
            $value = isset($given[$field->key]) && is_string($given[$field->key]) ? trim($given[$field->key]) : '';
            if ($value === '') {
                $kept = (string) ($stored[$field->key] ?? '');
                if ($kept === '') {
                    $errors["credentials.{$field->key}"] = "{$field->label} is required.";
                }
                $values[$field->key] = $kept;

                continue;
            }
            if (mb_strlen($value) > $field->maxLength) {
                $errors["credentials.{$field->key}"] = "{$field->label} is at most {$field->maxLength} characters.";
            } elseif ($field->pattern !== null && preg_match($field->pattern, $value) !== 1) {
                $errors["credentials.{$field->key}"] = "{$field->label} does not look right. Copy it exactly as the provider gave it.";
            }
            $values[$field->key] = $value;
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $values;
    }

    /**
     * @param list<CredentialField> $fields
     * @param array<string, string> $values
     * @return array<string, string>
     */
    public static function hints(array $fields, array $values): array
    {
        $hints = [];
        foreach ($fields as $field) {
            $value = $values[$field->key] ?? '';
            $hints[$field->key] = $field->secret ? 'set' : (mb_strlen($value) <= 4 ? str_repeat('•', mb_strlen($value)) : mb_substr($value, 0, 2).'••••'.mb_substr($value, -2));
        }

        return $hints;
    }

    /**
     * Field names whose value changed (for the audit log — never the values).
     *
     * @param array<string, string> $new
     * @param array<string, mixed> $old
     * @return list<string>
     */
    public static function changed(array $new, array $old): array
    {
        return array_keys(array_filter($new, fn ($value, $key) => ($old[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));
    }
}
