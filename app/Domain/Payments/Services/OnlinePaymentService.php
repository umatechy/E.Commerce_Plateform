<?php

declare(strict_types=1);

namespace App\Domain\Payments\Services;

use App\Domain\Billing\Exceptions\BillingActionRefusedException;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoicePaymentMethod;
use App\Domain\Billing\Models\InvoiceStatus;
use App\Domain\Billing\Services\InvoiceService;
use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Domain\Identity\Models\User;
use App\Domain\Payments\Exceptions\OnlinePaymentRefusedException;
use App\Domain\Payments\Jobs\ChargeWalletAttempt;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Domain\Payments\Models\PaymentMethod;
use App\Domain\Payments\Models\PaymentProviderAccount;
use App\Domain\Payments\Models\PaymentReconciliationItem;
use App\Domain\Payments\Models\PaymentStatus;
use App\Domain\Payments\Models\PaymentWebhookEvent;
use App\Domain\Payments\Models\PlatformPaymentAccount;
use App\Domain\Payments\Models\WebhookEventStatus;
use App\Domain\Payments\Providers\FailureCategory;
use App\Domain\Payments\Providers\PayerField;
use App\Domain\Payments\Providers\ProviderAdapter;
use App\Domain\Payments\Providers\ProviderCredentials;
use App\Domain\Payments\Providers\ProviderOutcome;
use App\Domain\Payments\Providers\ProviderRegistry;
use App\Domain\Payments\Providers\ProviderResult;
use App\Domain\Payments\Providers\WalletCharge;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Tenancy\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase B48 (gap G9; Module 12 §8, §24–30, §35–37, §41–43, §53–55, §59–62;
 * SRS PAY-001…010): the online payment engine on top of Phase B7's
 * PaymentService. It owns attempts; PaymentService stays the only writer of
 * payments and their transactions, InvoiceService of invoice payments.
 *
 * The rules it keeps:
 * - the amount and currency are the server's (the order's payable amount,
 *   the invoice's amount due), and what the provider confirms is checked
 *   against them before anything is recorded (Module 12 §11–12);
 * - a payment is paid only on the provider's word, read server-to-server —
 *   never on a browser redirect or an unsigned notice (§25, §27; PAY-005);
 * - no new attempt while one is waiting for an answer (Module 30 §96), and
 *   a timeout is "unknown", settled by asking the provider (§61–62);
 * - every answer is applied once, under a row lock (§28, §82);
 * - the provider is called outside any database transaction.
 */
final class OnlinePaymentService
{
    /** How long a single wallet charge may take before reconciliation calls it. */
    public const ATTEMPT_MINUTES = 10;

    /** Attempts per order payment: enough to correct a typo or switch wallet, not to probe numbers. */
    public const MAX_ATTEMPTS = 5;

    /** A Test payment from the settings page: Rs 1.00. */
    public const TEST_AMOUNT_MINOR = 100;

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly PaymentService $payments,
        private readonly ConfigService $config,
        private readonly TenantContext $context,
        private readonly RecordsOutboxEvents $outbox,
    ) {}

    // ------------------------------------------------------------------
    // What a shopper may use (Module 12 §35–37)
    // ------------------------------------------------------------------

    /**
     * The store's wallet methods a shopper can pay this amount with now.
     * Sandbox accounts are never offered to shoppers: their payments are not
     * real money (they are checked with a Test payment instead). A null
     * amount leaves the account's minimum and maximum out.
     *
     * @return list<array{method: string, provider: string, label: string, payer_fields: list<array<string, string>>}>
     */
    public function walletOptions(?int $amountMinor, string $currency): array
    {
        $options = [];
        foreach (PaymentProviderAccount::query()->where('is_enabled', true)->orderBy('id')->get() as $account) {
            if ($this->usableAtCheckout($account, $amountMinor, $currency) === null) {
                $adapter = $this->providers->get($account->provider);
                $options[] = [
                    'method' => (string) PaymentMethod::forWalletProvider($account->provider)?->value,
                    'provider' => $account->provider,
                    'label' => $account->provider === 'simulator' ? 'Test wallet (no real money)' : $adapter->label(),
                    'payer_fields' => array_map(fn (PayerField $f) => $f->toArray(), $adapter->payerFields()),
                ];
            }
        }

        return $options;
    }

    /** Why this account cannot take this payment at checkout, or null when it can. */
    public function usableAtCheckout(PaymentProviderAccount $account, ?int $amountMinor, string $currency): ?string
    {
        $adapter = $this->providers->find($account->provider);

        return match (true) {
            $adapter === null || ! $account->is_enabled => 'not_available',
            ! $adapter->capabilities()->walletPayment => 'not_available',
            $account->provider === 'simulator' => $this->limits($account, $amountMinor, $currency, $adapter),
            ! $account->isLive() => 'sandbox_only',
            ! $this->providers->liveAllowed($account->provider) => 'not_live_yet',
            default => $this->limits($account, $amountMinor, $currency, $adapter),
        };
    }

    private function limits(PaymentProviderAccount $account, ?int $amountMinor, string $currency, ProviderAdapter $adapter): ?string
    {
        return match (true) {
            ! in_array(strtoupper($currency), $adapter->capabilities()->currencies, true) => 'currency_not_supported',
            $amountMinor !== null && $account->min_amount_minor !== null && $amountMinor < $account->min_amount_minor => 'below_minimum',
            $amountMinor !== null && $account->max_amount_minor !== null && $amountMinor > $account->max_amount_minor => 'above_maximum',
            default => null,
        };
    }

    // ------------------------------------------------------------------
    // Order payments
    // ------------------------------------------------------------------

    /** A guest's key to follow and retry this payment; only its hash is stored. */
    public function issueClientToken(Payment $payment): string
    {
        $token = Str::random(48);
        $payment->forceFill(['client_token_hash' => hash('sha256', $token)])->save();

        return $token;
    }

    public function tokenMatches(Payment $payment, ?string $token): bool
    {
        return is_string($token) && $token !== '' && $payment->client_token_hash !== null
            && hash_equals($payment->client_token_hash, hash('sha256', $token));
    }

    /**
     * Start (or retry) collecting an order's payment from a wallet.
     *
     * @param array<string, mixed> $payer what the shopper typed
     * @throws OnlinePaymentRefusedException
     */
    public function startForOrder(Payment $payment, PaymentMethod $method, array $payer): PaymentAttempt
    {
        $provider = $method->walletProvider() ?? throw new OnlinePaymentRefusedException('method_not_online', 'This way of paying is not an online payment.');
        $account = PaymentProviderAccount::query()->where('provider', $provider)->first();
        if ($account === null || ($why = $this->usableAtCheckout($account, $payment->amount_minor, $payment->currency)) !== null) {
            throw new OnlinePaymentRefusedException('method_not_available', 'This way of paying is not available for this order.');
        }
        $adapter = $this->providers->get($provider);
        [$mobile, $cnic] = $this->payer($adapter, $payer);
        $window = (int) $this->config->get('payments.order_payment_minutes');

        $attempt = DB::transaction(function () use ($payment, $method, $account, $mobile, $window) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if (! in_array($payment->status, [PaymentStatus::RequiresAction, PaymentStatus::Pending], true) || $payment->method->walletProvider() === null) {
                throw new OnlinePaymentRefusedException('payment_not_open', 'This order does not need an online payment.', 409);
            }
            if ($payment->created_at->copy()->addMinutes($window)->isPast()) {
                throw new OnlinePaymentRefusedException('payment_window_over', 'The time to pay for this order has run out.', 409);
            }
            $attempts = $payment->attempts()->get();
            if ($attempts->contains(fn (PaymentAttempt $a) => $a->isInFlight())) {
                // Module 30 §96: an unanswered attempt may still take the money.
                throw new OnlinePaymentRefusedException('attempt_in_progress', 'A payment for this order is still being confirmed. Please wait for it before trying again.', 409);
            }
            if ($attempts->count() >= self::MAX_ATTEMPTS) {
                throw new OnlinePaymentRefusedException('too_many_attempts', 'This order has had too many payment attempts. Please contact the store.', 429);
            }
            $this->payments->switchWalletMethod($payment, $method);

            return $this->newAttempt([
                'store_id' => $payment->store_id, 'purpose' => PaymentAttempt::ORDER, 'payment_id' => $payment->id,
                'provider_account_id' => $account->id, 'provider' => $account->provider, 'environment' => $account->environment,
                'amount_minor' => $payment->amount_minor, 'currency' => $payment->currency, 'payer_hint' => PayerField::mask($mobile),
            ]);
        });

        ChargeWalletAttempt::dispatch($attempt->id, $attempt->store_id, $mobile, $cnic)->afterCommit();

        return $attempt->fresh() ?? $attempt;
    }

    // ------------------------------------------------------------------
    // Invoice payments (a store paying Umar Techy; Module 29)
    // ------------------------------------------------------------------

    /** @return list<array{provider: string, label: string, payer_fields: list<array<string, string>>}> */
    public function invoiceOptions(Invoice $invoice): array
    {
        $options = [];
        foreach (PlatformPaymentAccount::query()->where('is_enabled', true)->orderBy('id')->get() as $account) {
            if ($this->platformAccountUsable($account, $invoice->currency)) {
                $adapter = $this->providers->get($account->provider);
                $options[] = ['provider' => $account->provider, 'label' => $account->provider === 'simulator' ? 'Test wallet (no real money)' : $adapter->label(), 'payer_fields' => array_map(fn (PayerField $f) => $f->toArray(), $adapter->payerFields())];
            }
        }

        return $options;
    }

    private function platformAccountUsable(PlatformPaymentAccount $account, string $currency): bool
    {
        $adapter = $this->providers->find($account->provider);

        return $adapter !== null && $account->is_enabled && $adapter->capabilities()->walletPayment
            && in_array(strtoupper($currency), $adapter->capabilities()->currencies, true)
            && ($account->provider === 'simulator' || ($account->isLive() && $this->providers->liveAllowed($account->provider)));
    }

    /**
     * @param array<string, mixed> $payer
     * @throws OnlinePaymentRefusedException
     */
    public function startForInvoice(Invoice $invoice, string $provider, array $payer, User $actor): PaymentAttempt
    {
        $account = PlatformPaymentAccount::query()->where('provider', $provider)->first();
        if ($account === null || ! $this->platformAccountUsable($account, $invoice->currency)) {
            throw new OnlinePaymentRefusedException('method_not_available', 'This way of paying is not available.');
        }
        [$mobile, $cnic] = $this->payer($this->providers->get($provider), $payer);

        $attempt = DB::transaction(function () use ($invoice, $account, $mobile, $actor) {
            $invoice = Invoice::query()->withoutTenantScope()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== InvoiceStatus::Open || $invoice->amountDue() <= 0) {
                throw new OnlinePaymentRefusedException('invoice_not_open', 'This invoice has nothing left to pay.', 409);
            }
            $attempts = PaymentAttempt::query()->withoutTenantScope()->where('invoice_id', $invoice->id)->get();
            if ($attempts->contains(fn (PaymentAttempt $a) => $a->isInFlight())) {
                throw new OnlinePaymentRefusedException('attempt_in_progress', 'A payment for this invoice is still being confirmed. Please wait for it before trying again.', 409);
            }
            if ($attempts->where('created_at', '>=', now()->subDay())->count() >= self::MAX_ATTEMPTS) {
                throw new OnlinePaymentRefusedException('too_many_attempts', 'Too many payment attempts today. Please try again tomorrow or use another way to pay.', 429);
            }

            return $this->newAttempt([
                'store_id' => $invoice->store_id, 'purpose' => PaymentAttempt::INVOICE, 'invoice_id' => $invoice->id,
                'platform_account_id' => $account->id, 'provider' => $account->provider, 'environment' => $account->environment,
                'amount_minor' => $invoice->amountDue(), 'currency' => $invoice->currency, 'payer_hint' => PayerField::mask($mobile),
                'created_by' => $actor->id,
            ]);
        });

        ChargeWalletAttempt::dispatch($attempt->id, $attempt->store_id, $mobile, $cnic)->afterCommit();

        return $attempt->fresh() ?? $attempt;
    }

    // ------------------------------------------------------------------
    // Test payments (a store checking its sandbox account)
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $payer
     * @throws OnlinePaymentRefusedException
     */
    public function startTest(PaymentProviderAccount $account, array $payer, User $actor): PaymentAttempt
    {
        if ($account->isLive()) {
            throw new OnlinePaymentRefusedException('live_account', 'Test payments are made with a sandbox account. A live account is checked by a real order.');
        }
        $adapter = $this->providers->find($account->provider) ?? throw new OnlinePaymentRefusedException('method_not_available', 'This provider is not available.');
        [$mobile, $cnic] = $this->payer($adapter, $payer);
        if (PaymentAttempt::query()->where('provider_account_id', $account->id)->whereIn('status', [PaymentAttempt::PENDING, PaymentAttempt::UNKNOWN])->exists()) {
            throw new OnlinePaymentRefusedException('attempt_in_progress', 'A test payment is still waiting for an answer.', 409);
        }

        $attempt = $this->newAttempt([
            'store_id' => $account->store_id, 'purpose' => PaymentAttempt::TEST, 'provider_account_id' => $account->id,
            'provider' => $account->provider, 'environment' => $account->environment,
            'amount_minor' => self::TEST_AMOUNT_MINOR, 'currency' => $adapter->capabilities()->currencies[0],
            'payer_hint' => PayerField::mask($mobile), 'created_by' => $actor->id,
        ]);
        ChargeWalletAttempt::dispatch($attempt->id, $attempt->store_id, $mobile, $cnic)->afterCommit();

        return $attempt->fresh() ?? $attempt;
    }

    // ------------------------------------------------------------------
    // Talking to the provider
    // ------------------------------------------------------------------

    /** The first and only charge request of an attempt (ChargeWalletAttempt). */
    public function charge(PaymentAttempt $attempt, string $mobile, ?string $cnicLast6): void
    {
        // Once asked, never asked again: a retried job must not charge twice.
        $claimed = PaymentAttempt::query()->whereKey($attempt->id)->where('status', PaymentAttempt::PENDING)->where('check_count', 0)
            ->update(['check_count' => 1, 'last_checked_at' => now()]);
        if ($claimed !== 1) {
            return;
        }
        [$adapter, $credentials] = $this->connection($attempt);
        $charge = new WalletCharge(
            reference: $attempt->reference, amountMinor: $attempt->amount_minor, currency: $attempt->currency,
            description: $this->description($attempt), createdAt: CarbonImmutable::instance($attempt->created_at),
            expiresAt: CarbonImmutable::instance($attempt->expires_at), mobile: $mobile, cnicLast6: $cnicLast6, payerHint: $attempt->payer_hint,
        );

        try {
            $result = $adapter->chargeWallet($charge, $credentials);
        } catch (\Throwable $e) {
            report($e);
            $result = ProviderResult::unknown(FailureCategory::UnknownProviderError, 'The provider call did not finish.');
        }
        $this->apply($attempt, $result, fromInquiry: false);
    }

    /** Ask the provider what happened (Module 12 §25 step 6, §55). */
    public function refresh(PaymentAttempt $attempt): PaymentAttempt
    {
        [$adapter, $credentials] = $this->connection($attempt);
        if (! $adapter->capabilities()->statusInquiry) {
            return $attempt;
        }
        try {
            $result = $adapter->inquire($attempt->asCharge($this->description($attempt)), $credentials);
        } catch (\Throwable $e) {
            report($e);
            $result = ProviderResult::unknown(FailureCategory::UnknownProviderError, 'The provider call did not finish.');
        }
        $this->apply($attempt, $result, fromInquiry: true);

        return $attempt->fresh() ?? $attempt;
    }

    /** One provider answer, applied once (Module 12 §28, §82). */
    private function apply(PaymentAttempt $attempt, ProviderResult $result, bool $fromInquiry): void
    {
        DB::transaction(function () use ($attempt, $result, $fromInquiry) {
            $attempt = PaymentAttempt::query()->withoutTenantScope()->lockForUpdate()->findOrFail($attempt->id);
            $updates = ['last_checked_at' => now()];
            if ($fromInquiry) {
                $updates['check_count'] = $attempt->check_count + 1;
            }
            $account = $this->accountOf($attempt);

            switch ($result->outcome) {
                case ProviderOutcome::Succeeded:
                    if ($attempt->status === PaymentAttempt::SUCCEEDED) {
                        break; // already recorded: a second notice changes nothing
                    }
                    $amount = $result->amountMinor ?? $attempt->amount_minor;
                    $currency = strtoupper($result->currency ?? $attempt->currency);
                    $updates += ['provider_transaction_reference' => $result->providerTransactionReference ?? $attempt->provider_transaction_reference, 'confirmed_amount_minor' => $amount, 'confirmed_currency' => $currency, 'provider_code' => $result->providerCode, 'provider_message' => $result->providerMessage];
                    if ($amount !== $attempt->amount_minor || $currency !== $attempt->currency) {
                        // Money arrived, but not what was asked: not recorded as paid; a person decides.
                        $category = $currency !== $attempt->currency ? FailureCategory::CurrencyMismatch : FailureCategory::AmountMismatch;
                        $attempt->update($updates + ['status' => PaymentAttempt::UNKNOWN, 'failure_category' => $category->value]);
                        $this->flag($attempt, $category->value, ['expected_minor' => $attempt->amount_minor, 'expected_currency' => $attempt->currency, 'confirmed_minor' => $amount, 'confirmed_currency' => $currency]);
                        break;
                    }
                    $attempt->update($updates + ['status' => PaymentAttempt::SUCCEEDED, 'failure_category' => null, 'completed_at' => now()]);
                    $account?->forceFill(['last_success_at' => now(), 'verified_at' => $account->verified_at ?? now()])->save();
                    $this->settle($attempt);
                    break;

                case ProviderOutcome::Failed:
                    if ($attempt->status === PaymentAttempt::SUCCEEDED) {
                        // Module 12 §51: the provider now reports a recorded payment as failed or reversed.
                        $attempt->update(['last_checked_at' => now(), 'check_count' => $updates['check_count'] ?? $attempt->check_count]);
                        $this->flag($attempt, 'status_changed', ['provider_code' => $result->providerCode]);
                        break;
                    }
                    if (in_array($attempt->status, [PaymentAttempt::FAILED, PaymentAttempt::EXPIRED], true)) {
                        $attempt->update(['last_checked_at' => now()]);
                        break;
                    }
                    $category = $result->category ?? FailureCategory::PaymentDeclined;
                    $attempt->update($updates + ['status' => PaymentAttempt::FAILED, 'failure_category' => $category->value, 'provider_code' => $result->providerCode, 'provider_message' => $result->providerMessage, 'completed_at' => now()]);
                    $account?->forceFill(['last_failure_at' => now(), 'last_failure_category' => $category->value])->save();
                    $this->outbox->recordEventFor($attempt->store_id, 'payment.attempt_failed', ['attempt_id' => $attempt->id, 'payment_id' => $attempt->payment_id, 'category' => $category->value], "payment-attempt:{$attempt->id}:failed");
                    break;

                case ProviderOutcome::Pending:
                    if (in_array($attempt->status, [PaymentAttempt::PENDING, PaymentAttempt::UNKNOWN], true)) {
                        $attempt->update($updates + ['status' => PaymentAttempt::PENDING, 'provider_code' => $result->providerCode, 'provider_transaction_reference' => $result->providerTransactionReference ?? $attempt->provider_transaction_reference]);
                    } else {
                        $attempt->update(['last_checked_at' => now()]);
                    }
                    break;

                case ProviderOutcome::Unknown:
                    if ($attempt->status === PaymentAttempt::PENDING) {
                        $attempt->update($updates + ['status' => PaymentAttempt::UNKNOWN, 'failure_category' => ($result->category ?? FailureCategory::UnknownProviderError)->value, 'provider_message' => $result->providerMessage]);
                    } else {
                        $attempt->update($updates);
                    }
                    break;
            }
        });
    }

    /** Money confirmed for an attempt: record it where it belongs. */
    private function settle(PaymentAttempt $attempt): void
    {
        if ($attempt->purpose === PaymentAttempt::ORDER && $attempt->payment_id !== null) {
            $payment = Payment::query()->withoutTenantScope()->findOrFail($attempt->payment_id);
            if ($payment->status === PaymentStatus::Paid || $payment->paidAmountMinor() > 0) {
                // Paid twice (e.g. a late answer after another attempt succeeded): keep the money visible, refund by hand.
                $this->flag($attempt, 'paid_after_expiry', ['reason' => 'already_paid']);

                return;
            }
            if ($payment->status === PaymentStatus::Expired) {
                $this->flag($attempt, 'paid_after_expiry', ['reason' => 'order_given_up']);
            }
            $this->payments->recordProviderPayment($payment, $attempt);
        }

        if ($attempt->purpose === PaymentAttempt::INVOICE && $attempt->invoice_id !== null) {
            $invoice = Invoice::query()->withoutTenantScope()->findOrFail($attempt->invoice_id);
            try {
                app(InvoiceService::class)->recordPayment($invoice, [
                    'amount_minor' => $attempt->amount_minor,
                    'method' => InvoicePaymentMethod::MobileWallet,
                    'reference' => trim(($this->providers->find($attempt->provider)?->label() ?? $attempt->provider).' '.($attempt->provider_transaction_reference ?? $attempt->reference)),
                    'note' => 'Paid online ('.$attempt->reference.').',
                    'received_at' => CarbonImmutable::now(),
                    'idempotency_key' => "attempt:{$attempt->id}",
                ], null);
            } catch (BillingActionRefusedException) {
                // The invoice was settled meanwhile (another payment, a credit note): flag it, refund by hand.
                $this->flag($attempt, 'paid_after_expiry', ['reason' => 'invoice_already_settled']);
            }
        }
    }

    private function flag(PaymentAttempt $attempt, string $kind, array $details): void
    {
        $item = PaymentReconciliationItem::query()->withoutTenantScope()->firstOrCreate(
            ['payment_attempt_id' => $attempt->id, 'kind' => $kind],
            ['store_id' => $attempt->store_id, 'payment_id' => $attempt->payment_id, 'details' => $details + ['reference' => $attempt->reference]],
        );
        if ($item->wasRecentlyCreated) {
            if ($attempt->payment_id !== null) {
                Payment::query()->withoutTenantScope()->whereKey($attempt->payment_id)->update(['reconciliation_status' => 'mismatch']);
            }
            $this->outbox->recordEventFor($attempt->store_id, 'payment.reconciliation_flagged', ['item_id' => $item->id, 'kind' => $kind, 'attempt_id' => $attempt->id], "payment-reconciliation:{$item->id}");
        }
    }

    // ------------------------------------------------------------------
    // Refunds through the provider (Module 12 §46–49)
    // ------------------------------------------------------------------

    /** Whether this payment's money can be sent back by the provider's own API. */
    public function providerRefundable(Payment $payment): bool
    {
        $adapter = $payment->provider !== null ? $this->providers->find($payment->provider) : null;

        return $adapter !== null && $adapter->capabilities()->refund && $this->paidAttempt($payment) !== null;
    }

    /**
     * Ask the provider to send the money back, then record it. Nothing is
     * recorded unless the provider confirms; an unanswered refund is
     * reported, not retried blindly (the same key is sent again, so a
     * provider that deduplicates refunds will not pay twice).
     *
     * @throws OnlinePaymentRefusedException
     * @throws \App\Domain\Payments\Exceptions\RefundExceedsRefundableBalanceException
     */
    public function refundThroughProvider(Payment $payment, int $amountMinor, ?string $reason, User $actor, string $idempotencyKey): \App\Domain\Payments\Models\PaymentTransaction
    {
        if ($existing = \App\Domain\Payments\Models\PaymentTransaction::query()->where('idempotency_key', $idempotencyKey)->first()) {
            return $existing;
        }
        $attempt = $this->paidAttempt($payment) ?? throw new OnlinePaymentRefusedException('not_refundable', 'This payment has no confirmed online charge to refund.');
        [$adapter, $credentials] = $this->connection($attempt);
        $refundable = $payment->refundableAmountMinor();
        if ($amountMinor > $refundable) {
            throw new \App\Domain\Payments\Exceptions\RefundExceedsRefundableBalanceException($amountMinor, $refundable);
        }
        if ($amountMinor < $refundable && ! $adapter->capabilities()->partialRefund) {
            throw new OnlinePaymentRefusedException('partial_refund_not_supported', $adapter->label().' can only refund the whole remaining amount through its API.');
        }

        try {
            $result = $adapter->refund($attempt->asCharge($this->description($attempt)), $amountMinor, $idempotencyKey, $credentials);
        } catch (\Throwable $e) {
            report($e);
            $result = ProviderResult::unknown(FailureCategory::UnknownProviderError);
        }

        return match ($result->outcome) {
            ProviderOutcome::Succeeded => $this->payments->refund($payment, $amountMinor, $reason, $actor->id, $idempotencyKey, $result->providerTransactionReference),
            ProviderOutcome::Failed => throw new OnlinePaymentRefusedException('provider_refund_failed', $adapter->label().' did not make the refund: '.(($result->category ?? FailureCategory::UnknownProviderError)->staffLabel()).'.'),
            default => throw new OnlinePaymentRefusedException('provider_refund_unknown', $adapter->label().' did not confirm the refund. Check its merchant portal before trying again.', 502),
        };
    }

    private function paidAttempt(Payment $payment): ?PaymentAttempt
    {
        return PaymentAttempt::query()->where('payment_id', $payment->id)->where('status', PaymentAttempt::SUCCEEDED)->latest('id')->first();
    }

    // ------------------------------------------------------------------
    // Reconciliation (Module 12 §53–55; scheduled: payments:reconcile)
    // ------------------------------------------------------------------

    /** @return array{checked: int, expired_attempts: int, expired_payments: int, flagged_unknown: int} */
    public function reconcile(int $limit = 100): array
    {
        $counts = ['checked' => 0, 'expired_attempts' => 0, 'expired_payments' => 0, 'flagged_unknown' => 0];

        // 1. Ask about attempts still waiting: every 30 seconds on their first day, then hourly.
        //    Attempts flagged for a wrong amount or currency wait for a person instead.
        $this->context->resolveToPlatform();
        $waiting = PaymentAttempt::query()
            ->whereIn('status', [PaymentAttempt::PENDING, PaymentAttempt::UNKNOWN])
            ->where(fn ($q) => $q->whereNull('failure_category')->orWhereNotIn('failure_category', [FailureCategory::AmountMismatch->value, FailureCategory::CurrencyMismatch->value]))
            ->where('created_at', '<=', now()->subSeconds(30))
            ->where(fn ($q) => $q->whereNull('last_checked_at')->orWhere('last_checked_at', '<=', now()->subSeconds(30)))
            ->where(fn ($q) => $q->where('created_at', '>', now()->subDay())->orWhere('last_checked_at', '<=', now()->subHour()))
            ->orderBy('last_checked_at')->limit($limit)->get();
        foreach ($waiting as $attempt) {
            $this->context->resolveToStore($attempt->store_id);
            $attempt = $this->refresh($attempt);
            $counts['checked']++;

            if ($attempt->status === PaymentAttempt::PENDING && $attempt->expires_at->isPast()) {
                $attempt->update(['status' => PaymentAttempt::EXPIRED, 'failure_category' => FailureCategory::PaymentExpired->value, 'completed_at' => now()]);
                $counts['expired_attempts']++;
            }
            if ($attempt->status === PaymentAttempt::UNKNOWN && $attempt->created_at->lte(now()->subDay())) {
                $this->flag($attempt, 'unresolved_unknown', ['checks' => $attempt->check_count]);
                $counts['flagged_unknown']++;
            }
        }

        // 2. Order payments nobody finished within the payment window: expire, cancel, give the stock back.
        $this->context->resolveToPlatform();
        $window = (int) $this->config->get('payments.order_payment_minutes');
        $stale = Payment::query()
            ->whereIn('method', PaymentMethod::walletValues())
            ->whereIn('status', [PaymentStatus::RequiresAction->value, PaymentStatus::Pending->value])
            ->where('created_at', '<=', now()->subMinutes($window))
            ->whereDoesntHave('attempts', fn ($q) => $q->whereIn('status', [PaymentAttempt::PENDING, PaymentAttempt::UNKNOWN]))
            ->limit($limit)->get();
        foreach ($stale as $payment) {
            $this->context->resolveToStore($payment->store_id);
            $this->payments->expireUnpaid($payment);
            $counts['expired_payments']++;
        }

        return $counts;
    }

    /**
     * Module 12 §51, §55: confirmed payments of the last two days asked
     * about again, so a later reversal by the provider is seen.
     */
    public function reverify(int $limit = 200): int
    {
        $this->context->resolveToPlatform();
        $recent = PaymentAttempt::query()
            ->where('status', PaymentAttempt::SUCCEEDED)->where('purpose', '!=', PaymentAttempt::TEST)
            ->where('completed_at', '>=', now()->subDays(2))
            ->where('last_checked_at', '<=', now()->subHours(6))
            ->orderBy('last_checked_at')->limit($limit)->get();
        foreach ($recent as $attempt) {
            $this->context->resolveToStore($attempt->store_id);
            $this->refresh($attempt);
        }

        return $recent->count();
    }

    // ------------------------------------------------------------------
    // Provider notifications (Module 12 §26–30, §70; PAY-006/007/008)
    // ------------------------------------------------------------------

    /**
     * A notification is a hint, never proof: after the checks below the
     * engine asks the provider itself (inquiry) and applies that answer.
     * Checks: dedup of the exact delivery, our reference, the account's
     * signature where the provider signs, the time window where it dates.
     */
    public function handleNotification(ProviderAdapter $adapter, Request $request): PaymentWebhookEvent
    {
        $raw = $request->getContent();
        $input = $request->all();
        $headers = collect($request->headers->all())->map(fn (array $values) => $values[0] ?? null)->all();
        $eventId = 'sha256:'.hash('sha256', $adapter->key().'|'.$raw.'|'.json_encode($request->query()));

        if ($existing = PaymentWebhookEvent::query()->where('provider', $adapter->key())->where('external_event_id', $eventId)->first()) {
            return $existing; // the same delivery again: nothing more happens (PAY-007)
        }

        $reference = $adapter->notificationReference($input, $headers);
        $attempt = $reference !== null ? PaymentAttempt::query()->withoutTenantScope()->where('reference', $reference)->where('provider', $adapter->key())->first() : null;

        $event = PaymentWebhookEvent::query()->create([
            'store_id' => $attempt?->store_id, 'payment_id' => $attempt?->payment_id, 'provider' => $adapter->key(),
            'external_event_id' => $eventId, 'status' => WebhookEventStatus::Received,
            // Only what identifies the notice is kept; it may carry hashes and wallet details.
            'payload' => ['reference' => $reference],
        ]);

        $reject = function (string $why) use ($event): PaymentWebhookEvent {
            $event->update(['status' => WebhookEventStatus::Failed, 'failure_reason' => $why, 'processed_at' => now()]);

            return $event;
        };

        if ($attempt === null) {
            $event->update(['status' => WebhookEventStatus::Ignored, 'failure_reason' => 'No attempt with this reference.', 'processed_at' => now()]);

            return $event;
        }

        $this->context->resolveToStore($attempt->store_id);
        [, $credentials] = $this->connection($attempt);
        if ($adapter->capabilities()->signedNotifications && ! $adapter->verifyNotification($raw, $input, $headers, $credentials)) {
            return $reject('Signature verification failed.');
        }
        $sentAt = $adapter->notificationSentAt($input, $headers);
        if ($sentAt !== null && abs(now()->getTimestamp() - $sentAt) > (int) $this->config->get('payments.webhook_tolerance_seconds')) {
            return $reject('Outside the allowed time window.'); // PAY-008: an old notice sent again
        }

        $this->refresh($attempt);
        $event->update(['status' => WebhookEventStatus::Processed, 'processed_at' => now()]);

        return $event->fresh() ?? $event;
    }

    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $payer
     * @return array{0: string, 1: ?string}
     * @throws OnlinePaymentRefusedException
     */
    private function payer(ProviderAdapter $adapter, array $payer): array
    {
        $values = [];
        foreach ($adapter->payerFields() as $field) {
            $value = PayerField::normalize($field->key, $payer[$field->key] ?? null);
            if ($value === null) {
                throw new OnlinePaymentRefusedException('payer_invalid', $field->key === PayerField::MOBILE
                    ? 'Enter the mobile number of the wallet, such as 03001234567.'
                    : 'Enter the last 6 digits of the CNIC the wallet is registered with.');
            }
            $values[$field->key] = $value;
        }

        return [$values[PayerField::MOBILE], $values[PayerField::CNIC_LAST6] ?? null];
    }

    /** @param array<string, mixed> $attributes */
    private function newAttempt(array $attributes): PaymentAttempt
    {
        return PaymentAttempt::query()->create($attributes + [
            'reference' => self::newReference(),
            'status' => PaymentAttempt::PENDING,
            'expires_at' => now()->addMinutes(self::ATTEMPT_MINUTES),
        ]);
    }

    /** Ours, unique, short enough for every provider (JazzCash allows 20): "UT" + 18 characters. */
    public static function newReference(): string
    {
        return 'UT'.strtoupper(substr((string) Str::ulid(), -18));
    }

    /** @return array{0: ProviderAdapter, 1: ProviderCredentials} */
    private function connection(PaymentAttempt $attempt): array
    {
        $account = $this->accountOf($attempt) ?? throw new \RuntimeException("The merchant account of attempt {$attempt->reference} no longer exists.");

        // Credentials belong to one environment: after a switch to production the sandbox ones are gone.
        return [$this->providers->get($attempt->provider), $account->environment === $attempt->environment ? $account->providerCredentials() : new ProviderCredentials([], $attempt->environment)];
    }

    private function accountOf(PaymentAttempt $attempt): PaymentProviderAccount|PlatformPaymentAccount|null
    {
        return $attempt->platform_account_id !== null
            ? PlatformPaymentAccount::query()->find($attempt->platform_account_id)
            : PaymentProviderAccount::query()->withoutTenantScope()->where('store_id', $attempt->store_id)->find($attempt->provider_account_id);
    }

    private function description(PaymentAttempt $attempt): string
    {
        return match ($attempt->purpose) {
            PaymentAttempt::INVOICE => 'Umar Techy invoice',
            PaymentAttempt::TEST => 'Test payment',
            default => 'Order payment',
        };
    }

    /** For staff screens: the provider's label, or the code when the provider is gone. */
    public function providerLabel(string $provider): string
    {
        return $this->providers->find($provider)?->label() ?? $provider;
    }

}
