<?php

declare(strict_types=1);

namespace App\Domain\Payments\Services;

use App\Domain\Cart\Services\CheckoutService;
use App\Domain\Packages\Services\EntitlementService;
use App\Domain\Payments\Models\PaymentMethod;
use App\Domain\Payments\Models\PaymentProviderAccount;
use App\Domain\Settings\Services\ConfigService;

/**
 * Phase B48 (Module 12 §16, §18–19, §36–37): the ways a shopper of the
 * current store may pay, from one place — the storefront shows them, and
 * checkout asks refusal() again on the server before an order is placed
 * (the page is never the boundary).
 *
 * A method is offered when the package includes it (Module 04), the store
 * turned it on, and it can actually be used: bank transfer needs the bank
 * account, a wallet needs a working merchant account.
 */
final class PaymentOptions
{
    public function __construct(
        private readonly ConfigService $config,
        private readonly EntitlementService $entitlements,
        private readonly OnlinePaymentService $online,
    ) {}

    /**
     * Everything the checkout page needs, without the amount (limits are
     * given so the page can say why a method does not fit this cart).
     *
     * @return list<array<string, mixed>>
     */
    public function forStorefront(string $currency): array
    {
        if (! $this->entitlements->hasFeature('orders.basic')) {
            return [];
        }
        $options = [];
        if ($this->codOffered()) {
            $options[] = ['method' => 'cod', 'kind' => 'cod', 'min_minor' => $this->positive('payments.cod.min_order_minor'), 'max_minor' => $this->positive('payments.cod.max_order_minor')];
        }
        $wallets = $this->entitlements->hasFeature(CheckoutService::FEATURE_KEY_BY_METHOD['jazzcash']) ? $this->online->walletOptions(null, $currency) : [];
        foreach ($wallets as $wallet) {
            $account = PaymentProviderAccount::query()->where('provider', $wallet['provider'])->first();
            $options[] = ['method' => $wallet['method'], 'kind' => 'wallet', 'label' => $wallet['label'], 'payer_fields' => $wallet['payer_fields'], 'min_minor' => $account?->min_amount_minor, 'max_minor' => $account?->max_amount_minor];
        }
        if (($bank = $this->bankDetails()) !== null && $this->entitlements->hasFeature(CheckoutService::FEATURE_KEY_BY_METHOD['bank_transfer']) && (bool) $this->config->get('payments.bank_transfer.enabled')) {
            $options[] = ['method' => 'bank_transfer', 'kind' => 'bank_transfer', 'bank' => $bank];
        }

        return $options;
    }

    /** Method codes only (Phase B7 shape, kept for the headless API and the setup checklist). */
    public function methodCodes(string $currency): array
    {
        return array_values(array_unique(array_map(fn (array $o) => (string) $o['method'], $this->forStorefront($currency))));
    }

    /**
     * Why this method cannot pay this amount, or null when it can. Checkout
     * calls it with the order's server-computed payable amount.
     */
    public function refusal(PaymentMethod $method, int $amountMinor, string $currency): ?string
    {
        if ($method === PaymentMethod::MockRedirect) {
            return null; // Phase B7's test double: its own tests decide
        }
        if ($method === PaymentMethod::CashOnDelivery) {
            if (! $this->codOffered()) {
                return 'not_available';
            }
            $min = $this->positive('payments.cod.min_order_minor');
            $max = $this->positive('payments.cod.max_order_minor');

            return match (true) {
                $min !== null && $amountMinor < $min => 'below_minimum',
                $max !== null && $amountMinor > $max => 'above_maximum',
                default => null,
            };
        }
        if ($method === PaymentMethod::BankTransfer) {
            return (bool) $this->config->get('payments.bank_transfer.enabled') && $this->bankDetails() !== null ? null : 'not_available';
        }

        $account = PaymentProviderAccount::query()->where('provider', (string) $method->walletProvider())->first();

        return $account === null ? 'not_available' : $this->online->usableAtCheckout($account, $amountMinor, $currency);
    }

    /** @return array{account_title: string, bank_name: string, account_number: string, instructions: ?string}|null */
    public function bankDetails(): ?array
    {
        $title = (string) $this->config->get('payments.bank.account_title');
        $bank = (string) $this->config->get('payments.bank.bank_name');
        $number = (string) $this->config->get('payments.bank.account_number');
        if ($title === '' || $bank === '' || $number === '') {
            return null;
        }
        $instructions = $this->config->get('payments.bank.instructions');

        return ['account_title' => $title, 'bank_name' => $bank, 'account_number' => $number, 'instructions' => is_string($instructions) && $instructions !== '' ? $instructions : null];
    }

    private function codOffered(): bool
    {
        return (bool) $this->config->get('payments.cod.enabled') && $this->entitlements->hasFeature(CheckoutService::FEATURE_KEY_BY_METHOD['cod']);
    }

    private function positive(string $key): ?int
    {
        $value = (int) $this->config->get($key);

        return $value > 0 ? $value : null;
    }
}
