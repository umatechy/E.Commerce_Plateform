<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domain\Events\Jobs\ConsumeOutboxEventJob;
use App\Domain\Events\Models\OutboxEvent;
use App\Domain\Notifications\Models\NotificationMessage;
use App\Domain\Notifications\Models\RecipientType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Phase B23 — billing events reach the store Owner by email through the
 * same outbox consumer every other notification uses (ADR-004).
 */
final class BillingNotificationTest extends TestCase
{
    use InteractsWithBilling, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-03-01 09:00:00'));
    }

    private function consumeOutbox(): void
    {
        OutboxEvent::query()->withoutTenantScope()->where('status', 'pending')->orderBy('id')->pluck('id')
            ->each(fn (int $id) => app()->call([new ConsumeOutboxEventJob($id), 'handle']));
    }

    /** @return list<string> */
    private function subjectsFor(int $ownerId): array
    {
        return NotificationMessage::query()->withoutTenantScope()
            ->where('recipient_type', RecipientType::User->value)->where('recipient_id', $ownerId)
            ->orderBy('id')->pluck('subject')->all();
    }

    public function test_the_owner_is_mailed_when_an_invoice_is_issued_overdue_and_paid(): void
    {
        Queue::fake(); // delivery itself is Module 21's concern
        [, $owner, $subscription] = $this->billedStore(2900, periodEnd: CarbonImmutable::parse('2026-03-05 09:00:00'));

        $this->runBilling();
        $this->travelTo(CarbonImmutable::parse('2026-03-05 09:00:00'));
        $this->runBilling();
        $this->travelTo(CarbonImmutable::parse('2026-03-08 09:00:00'));
        $this->runBilling();
        app(\App\Domain\Billing\Services\InvoiceService::class)->recordPayment($this->invoicesOf($subscription)->sole(), [
            'amount_minor' => 2900,
            'method' => \App\Domain\Billing\Models\InvoicePaymentMethod::BankTransfer,
            'received_at' => CarbonImmutable::now(),
            'idempotency_key' => 'notify-pay-0001',
        ], null);
        $this->consumeOutbox();
        // A retried delivery of the same events sends nothing twice.
        OutboxEvent::query()->withoutTenantScope()->update(['status' => 'pending']);
        $this->consumeOutbox();

        $this->assertSame([
            'Invoice INV-000001 for Acme Goods',
            'Payment overdue for invoice INV-000001',
            'Action needed: invoice INV-000001 is overdue',
            'Payment received for invoice INV-000001',
        ], $this->subjectsFor($owner->id));

        $message = NotificationMessage::query()->withoutTenantScope()->where('source_event_type', 'billing.invoice_issued')->sole();
        $this->assertSame($owner->email, $message->destination);
        $this->assertStringContainsString('29.00 USD', $message->body);
        $this->assertStringContainsString('2026-03-05 to 2026-04-05', $message->body);
    }

    public function test_a_zero_total_invoice_sends_no_mail(): void
    {
        Queue::fake();
        [, $owner] = $this->billedStore(0, status: 'active', periodEnd: CarbonImmutable::parse('2026-03-01 09:00:00'));

        $this->runBilling();
        $this->consumeOutbox();

        $this->assertSame([], $this->subjectsFor($owner->id));
    }
}
