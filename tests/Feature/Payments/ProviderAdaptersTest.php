<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Domain\Payments\Providers\EasypaisaAdapter;
use App\Domain\Payments\Providers\FailureCategory;
use App\Domain\Payments\Providers\JazzCashAdapter;
use App\Domain\Payments\Providers\PayerField;
use App\Domain\Payments\Providers\ProviderCredentials;
use App\Domain\Payments\Providers\ProviderOutcome;
use App\Domain\Payments\Providers\WalletCharge;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase B48 — the JazzCash and Easypaisa adapters against faked HTTP (no
 * network, no real credentials): request signing, answer mapping, the
 * hash check on answers, and timeouts as Unknown (Module 12 §30, §59–62).
 *
 * These pin the adapters' behaviour as built. They do NOT prove the
 * providers' real APIs behave this way — that needs their sandboxes
 * (owner decision 2; docs/architecture/b48-payment-gateways.md §9).
 */
final class ProviderAdaptersTest extends TestCase
{
    private function charge(?string $mobile = '03001234567', ?string $cnic = '123456'): WalletCharge
    {
        return new WalletCharge('UT01TESTREFERENCE01', 150000, 'PKR', 'Order payment', CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'), CarbonImmutable::parse('2026-10-14 10:10:00', 'UTC'), $mobile, $cnic);
    }

    private function jazzCash(): ProviderCredentials
    {
        return new ProviderCredentials(['merchant_id' => 'MC10001', 'password' => 'pw', 'integrity_salt' => 'salt123'], ProviderCredentials::SANDBOX);
    }

    /** @param array<string, string> $fields */
    private function signed(array $fields): array
    {
        return $fields + ['pp_SecureHash' => JazzCashAdapter::hash($fields, 'salt123')];
    }

    public function test_the_jazzcash_hash_is_hmac_sha256_over_the_sorted_non_empty_values(): void
    {
        $fields = ['pp_TxnRefNo' => 'T1', 'pp_Amount' => '100', 'pp_BankID' => '', 'pp_MerchantID' => 'MC1', 'other' => 'x'];
        // Sorted keys: pp_Amount, pp_MerchantID, pp_TxnRefNo — empty and non-pp values left out.
        $this->assertSame(strtoupper(hash_hmac('sha256', 'salt&100&MC1&T1', 'salt')), JazzCashAdapter::hash($fields, 'salt'));
    }

    public function test_a_jazzcash_charge_is_signed_in_paisa_and_a_signed_success_is_paid(): void
    {
        Http::fake(['sandbox.jazzcash.com.pk/*' => Http::response($this->signed(['pp_ResponseCode' => '000', 'pp_ResponseMessage' => 'Thank you', 'pp_RetreivalReferenceNo' => '221014123', 'pp_Amount' => '150000', 'pp_TxnCurrency' => 'PKR', 'pp_TxnRefNo' => 'UT01TESTREFERENCE01']))]);

        $result = (new JazzCashAdapter())->chargeWallet($this->charge(), $this->jazzCash());

        $this->assertSame(ProviderOutcome::Succeeded, $result->outcome);
        $this->assertSame(150000, $result->amountMinor);
        $this->assertSame('221014123', $result->providerTransactionReference);
        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return str_ends_with($request->url(), '/ApplicationAPI/API/2.0/Purchase/DoMWalletTransaction')
                && $body['pp_Amount'] === '150000' && $body['pp_TxnCurrency'] === 'PKR' && $body['pp_MobileNumber'] === '03001234567' && $body['pp_CNIC'] === '123456'
                && $body['pp_TxnDateTime'] === '20261014150000' // Pakistan time
                && $body['pp_SecureHash'] === JazzCashAdapter::hash($body, 'salt123');
        });
    }

    public function test_jazzcash_answers_map_to_pending_failed_unknown_and_a_bad_hash_is_not_believed(): void
    {
        $adapter = new JazzCashAdapter();
        Http::fake(['*' => Http::sequence()
            ->push($this->signed(['pp_ResponseCode' => '157', 'pp_ResponseMessage' => 'Pending']))
            ->push($this->signed(['pp_ResponseCode' => '121', 'pp_ResponseMessage' => 'Declined']))
            ->push(['pp_ResponseCode' => '000', 'pp_SecureHash' => 'FORGED'])
            ->push('', 504)]);

        $this->assertSame(ProviderOutcome::Pending, $adapter->chargeWallet($this->charge(), $this->jazzCash())->outcome);
        $declined = $adapter->chargeWallet($this->charge(), $this->jazzCash());
        $this->assertSame([ProviderOutcome::Failed, FailureCategory::PaymentDeclined, '121'], [$declined->outcome, $declined->category, $declined->providerCode]);
        $forged = $adapter->chargeWallet($this->charge(), $this->jazzCash());
        $this->assertSame([ProviderOutcome::Unknown, FailureCategory::SignatureInvalid], [$forged->outcome, $forged->category]);
        $this->assertSame(ProviderOutcome::Unknown, $adapter->chargeWallet($this->charge(), $this->jazzCash())->outcome, 'a gateway error is not a failure');
    }

    public function test_a_jazzcash_inquiry_reads_the_payment_code_and_a_notification_is_checked_with_the_salt(): void
    {
        Http::fake(['*' => Http::response($this->signed(['pp_ResponseCode' => '000', 'pp_PaymentResponseCode' => '000', 'pp_Amount' => '150000', 'pp_TxnCurrency' => 'PKR']))]);
        $this->assertSame(ProviderOutcome::Succeeded, (new JazzCashAdapter())->inquire($this->charge(null, null), $this->jazzCash())->outcome);

        $notice = $this->signed(['pp_TxnRefNo' => 'UT01TESTREFERENCE01', 'pp_ResponseCode' => '000']);
        $this->assertTrue((new JazzCashAdapter())->verifyNotification('', $notice, [], $this->jazzCash()));
        $this->assertFalse((new JazzCashAdapter())->verifyNotification('', ['pp_ResponseCode' => '999'] + $notice, [], $this->jazzCash()));
        $this->assertSame('UT01TESTREFERENCE01', (new JazzCashAdapter())->notificationReference($notice, []));
    }

    public function test_an_easypaisa_charge_sends_the_credentials_header_and_its_inquiry_maps_statuses(): void
    {
        $credentials = new ProviderCredentials(['store_id' => '4321', 'username' => 'api-user', 'password' => 'api-pass', 'account_number' => '654321'], ProviderCredentials::SANDBOX);
        Http::fake(['easypaystg.easypaisa.com.pk/*' => Http::sequence()
            ->push(['responseCode' => '0000', 'responseDesc' => 'SUCCESS', 'transactionId' => '998877'])
            ->push(['responseCode' => '0000', 'transactionStatus' => 'PAID', 'transactionAmount' => '1500.00', 'transactionId' => '998877'])
            ->push(['responseCode' => '0000', 'transactionStatus' => 'FAILED'])
            ->push(['responseCode' => '0001', 'responseDesc' => 'System error'])]);
        $adapter = new EasypaisaAdapter();

        $this->assertSame(ProviderOutcome::Succeeded, $adapter->chargeWallet($this->charge(), $credentials)->outcome);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/easypay-service/rest/v4/initiate-ma-transaction')
            && $r->header('Credentials')[0] === base64_encode('api-user:api-pass')
            && $r->data()['transactionAmount'] === '1500.00' && $r->data()['mobileAccountNo'] === '03001234567' && $r->data()['storeId'] === '4321');

        $paid = $adapter->inquire($this->charge(null, null), $credentials);
        $this->assertSame([ProviderOutcome::Succeeded, 150000], [$paid->outcome, $paid->amountMinor]);
        $this->assertSame(ProviderOutcome::Failed, $adapter->inquire($this->charge(null, null), $credentials)->outcome);
        $this->assertSame(ProviderOutcome::Unknown, $adapter->inquire($this->charge(null, null), $credentials)->outcome, 'a failed inquiry tells us nothing');
    }

    public function test_no_connection_is_unknown_and_production_uses_the_production_host(): void
    {
        $called = null;
        Http::fake(function (Request $request) use (&$called) {
            $called = $request->url();
            throw new ConnectionException('timed out');
        });
        $result = (new EasypaisaAdapter())->chargeWallet($this->charge(), new ProviderCredentials(['store_id' => '1', 'username' => 'u', 'password' => 'p', 'account_number' => '1'], ProviderCredentials::PRODUCTION));
        $this->assertSame([ProviderOutcome::Unknown, FailureCategory::ProviderUnavailable], [$result->outcome, $result->category]);
        $this->assertStringStartsWith('https://easypay.easypaisa.com.pk/', (string) $called);
    }

    public function test_wallet_numbers_are_normalised_and_masked(): void
    {
        $this->assertSame('03001234567', PayerField::normalize(PayerField::MOBILE, '+92 300 1234567'));
        $this->assertSame('03001234567', PayerField::normalize(PayerField::MOBILE, '0300-1234567'));
        $this->assertNull(PayerField::normalize(PayerField::MOBILE, '0412345678'));
        $this->assertNull(PayerField::normalize(PayerField::CNIC_LAST6, '12345'));
        $this->assertSame('0300•••4567', PayerField::mask('03001234567'));
        $this->assertStringNotContainsString('pw', json_encode($this->jazzCash()));
    }
}
