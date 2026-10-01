<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Services\Rehearsal;

/**
 * An isolated place to restore a backup into and inspect it, without
 * touching live data (Module 23 §39 "Clone / Recovery Environment",
 * §47 "Restore Testing").
 */
interface RehearsalTarget
{
    /**
     * Restores the plain SQL dump into a throw-away target, checks it,
     * and destroys the target again — also when a check fails.
     *
     * @return array{
     *     passed: bool,
     *     checks: list<array{name: string, passed: bool, detail: string}>,
     *     counts: array<string, int>,
     *     import_ms: int,
     *     validate_ms: int,
     *     target_removed: bool
     * }
     * @throws \Throwable when the target cannot be created or the import fails
     */
    public function rehearse(string $plainSqlPath): array;
}
