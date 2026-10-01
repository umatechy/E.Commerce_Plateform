<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Models\AuditActorType;
use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Settings\Services\StoreClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Shared, whitelisted filters for both audit-log listings (store staff
 * and Super Admin). Values are only ever bound as parameters; `action`
 * is a prefix match so "super_admin." lists every Super Admin action.
 */
final class AuditLogQuery
{
    /** @return array<string, array<int, mixed>> */
    public static function rules(): array
    {
        return [
            'action' => ['nullable', 'string', 'max:128'],
            'actor_type' => ['nullable', Rule::enum(AuditActorType::class)],
            'actor' => ['nullable', 'string', 'size:26'], // an actor public_id
            'subject_type' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @param array<string, mixed> $filters validated input
     * @return Builder<AuditLog>
     */
    public static function apply(Builder $query, array $filters): Builder
    {
        // "From" and "to" are calendar days in the store's timezone (UTC on
        // the platform's own listing) — Module 33 §50.
        $clock = app(StoreClock::class);

        return $query
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', 'like', addcslashes($action, '%_\\').'%'))
            ->when($filters['actor_type'] ?? null, fn ($q, $type) => $q->where('actor_type', $type))
            ->when($filters['actor'] ?? null, fn ($q, $actor) => $q->where('actor_public_id', $actor))
            ->when($filters['subject_type'] ?? null, fn ($q, $type) => $q->where('subject_type', $type))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', $clock->parseLocal($from)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', $clock->parseLocal($to)->endOfDay()->utc()))
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    public static function perPage(Request $request): int
    {
        return (int) $request->input('per_page', 50);
    }
}
