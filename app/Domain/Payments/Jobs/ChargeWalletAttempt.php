<?php

declare(strict_types=1);

namespace App\Domain\Payments\Jobs;

use App\Domain\Payments\Models\PaymentAttempt;
use App\Domain\Payments\Services\OnlinePaymentService;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Phase B48: asks the provider for the money of one attempt. A queued job,
 * because a wallet charge waits while the shopper approves on the phone;
 * the shopper's page follows the attempt meanwhile.
 *
 * Encrypted on the queue (it carries the wallet number), tried once: a
 * second try could charge twice — an attempt that got no answer is left
 * Unknown and settled by inquiry, never by charging again (Module 12 §61–62).
 */
final class ChargeWalletAttempt implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly int $attemptId,
        public readonly int $storeId,
        public readonly string $mobile,
        public readonly ?string $cnicLast6,
    ) {}

    public function handle(TenantContext $context, OnlinePaymentService $payments): void
    {
        $context->resolveToStore($this->storeId);
        $attempt = PaymentAttempt::query()->find($this->attemptId);
        if ($attempt !== null) {
            $payments->charge($attempt, $this->mobile, $this->cnicLast6);
        }
    }
}
