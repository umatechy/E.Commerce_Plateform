<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Console;

use App\Domain\Compliance\Services\AuditRetentionService;
use Illuminate\Console\Command;

final class PruneAuditLogCommand extends Command
{
    protected $signature = 'audit:prune';

    protected $description = 'Remove audit entries past compliance.audit.retention_days, keeping chains verifiable (Module 32).';

    public function handle(AuditRetentionService $retention): int
    {
        $this->info("Pruned {$retention->prune()} audit entr(ies).");

        return self::SUCCESS;
    }
}
