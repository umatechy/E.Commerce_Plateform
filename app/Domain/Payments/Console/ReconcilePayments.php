<?php

declare(strict_types=1);

namespace App\Domain\Payments\Console;

use App\Domain\Payments\Services\OnlinePaymentService;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Phase B48 — Module 12 §53–55 "Reconciliation Jobs": every minute, ask the
 * provider about attempts still waiting, close those that ran out of time,
 * and give up order payments nobody finished (the order is cancelled and its
 * stock released). With --deep, also ask again about the last two days'
 * confirmed payments, so a reversal by the provider is noticed.
 *
 * Idempotent: every answer is applied under a row lock, at most once.
 */
final class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile {--deep : also re-check payments confirmed in the last two days}';

    protected $description = 'Ask providers about waiting payments, expire unfinished ones, and flag mismatches (Module 12 §53–55).';

    public function handle(OnlinePaymentService $payments, TenantContext $context): int
    {
        $counts = $payments->reconcile();
        $this->info("Checked {$counts['checked']} attempt(s); expired {$counts['expired_attempts']} attempt(s) and {$counts['expired_payments']} unpaid order payment(s); flagged {$counts['flagged_unknown']} without an answer.");

        if ($this->option('deep')) {
            $this->info('Re-checked '.$payments->reverify().' confirmed payment(s).');
        }
        $context->resolveToPlatform();

        return self::SUCCESS;
    }
}
