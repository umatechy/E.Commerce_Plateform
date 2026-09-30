<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Console;

use App\Domain\Compliance\Services\AuditChainVerifier;
use Illuminate\Console\Command;

final class VerifyAuditChainCommand extends Command
{
    protected $signature = 'audit:verify {--chain= : one chain, e.g. "platform" or "store:12"}';

    protected $description = 'Verify that audit log hash chains have not been altered (Module 32).';

    public function handle(AuditChainVerifier $verifier): int
    {
        $results = $this->option('chain') ? [$verifier->verify((string) $this->option('chain'))] : $verifier->verifyAll();
        $broken = false;

        if ($results === []) {
            $this->info('No audit entries recorded yet.');
        }

        foreach ($results as $result) {
            $line = "{$result['chain']}: {$result['status']} ({$result['verified_entries']} verified)";

            if ($result['status'] === 'broken') {
                $broken = true;
                $this->error("{$line} — {$result['reason']}");
            } else {
                $this->info($line);
            }
        }

        return $broken ? self::FAILURE : self::SUCCESS;
    }
}
