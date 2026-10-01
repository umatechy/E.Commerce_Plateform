<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\DataProtection\Services\Rehearsal\RehearsalTarget;

/**
 * Stands in for the isolated rehearsal database, so the rehearsal's
 * orchestration (integrity, decoding, recording, alerts) is tested
 * without MySQL. The real target has its own test, against real MySQL.
 */
final class FakeRehearsalTarget implements RehearsalTarget
{
    /** The plain SQL each rehearsal was given. */
    public array $received = [];

    /** @param list<string> $failingChecks check names to report as failed */
    public function __construct(private readonly array $failingChecks = [], private readonly ?\Throwable $throws = null) {}

    public function rehearse(string $plainSqlPath): array
    {
        $this->received[] = (string) file_get_contents($plainSqlPath);

        if ($this->throws !== null) {
            throw $this->throws;
        }

        $checks = array_map(
            fn (string $name) => ['name' => $name, 'passed' => ! in_array($name, $this->failingChecks, true), 'detail' => 'fake'],
            ['core_tables_present', 'migration_history_present', 'schema_matches_live', 'relationships_intact', 'tenant_boundaries_intact', 'rehearsal_target_removed'],
        );

        return [
            'passed' => $this->failingChecks === [],
            'checks' => $checks,
            'counts' => ['stores' => 2, 'orders' => 5],
            'import_ms' => 12,
            'validate_ms' => 3,
            'target_removed' => true,
        ];
    }
}
