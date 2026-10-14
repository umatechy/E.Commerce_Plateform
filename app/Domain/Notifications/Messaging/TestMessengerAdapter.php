<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Messaging;

use App\Domain\Payments\Providers\CredentialField;
use App\Domain\Payments\Providers\ProviderCredentials;
use Illuminate\Http\Request;

/**
 * "Test messenger": an SMS or WhatsApp provider that sends nothing and calls
 * nobody, so the whole flow (sending, templates, delivery reports, STOP,
 * limits) can be used and tested before real accounts exist. Only where
 * config('messaging.simulator') is on and never in production
 * (MessagingRegistry).
 *
 * The last four digits of the number choose what happens: …0002 refused
 * (bad number), …0003 provider busy (tried again), …0004 no answer;
 * anything else accepted. Delivery reports are posted to its webhook signed
 * with X-Test-Messenger-Signature (SHA-256 HMAC of the body).
 */
final class TestMessengerAdapter implements MessagingAdapter
{
    public function __construct(private readonly string $channel) {}

    public function key(): string
    {
        return 'test_'.$this->channel;
    }

    public function label(): string
    {
        return $this->channel === 'whatsapp' ? 'Test WhatsApp' : 'Test SMS';
    }

    public function capabilities(): MessagingCapabilities
    {
        return new MessagingCapabilities(
            channel: $this->channel, templates: $this->channel === 'whatsapp', freeText: $this->channel === 'sms',
            deliveryReports: true, readReceipts: $this->channel === 'whatsapp', inbound: true, templateCheck: $this->channel === 'whatsapp',
            verifiedApi: true, needsPlatformGoAhead: false, note: 'Sends nothing. Local and test systems only.',
        );
    }

    public function credentialFields(): array
    {
        return [
            new CredentialField('sender', 'Test sender', secret: false, maxLength: 40),
            new CredentialField('secret', 'Test signing secret', secret: true, maxLength: 100),
        ];
    }

    public function send(OutgoingMessage $message, ProviderCredentials $credentials): SendOutcome
    {
        if ($this->channel === 'whatsapp' && $message->template === null) {
            return SendOutcome::failed('template_missing', 'No approved WhatsApp template is set for this message.', permanent: true);
        }

        return match (substr($message->to, -4)) {
            '0002' => SendOutcome::failed('invalid_number', 'This number cannot receive messages.', permanent: true),
            '0003' => SendOutcome::failed('provider_busy', 'Too many messages at once; try again later.', permanent: false),
            '0004' => new SendOutcome(SendOutcome::UNKNOWN, code: 'no_answer', message: 'No answer from the test messenger.'),
            default => SendOutcome::accepted('TM-'.strtoupper(substr(hash('sha256', $message->reference.$message->to), 0, 16))),
        };
    }

    public function templateStatus(string $name, string $language, ProviderCredentials $credentials): string
    {
        return str_contains($name, 'rejected') ? 'rejected' : 'approved';
    }

    public function verifyWebhook(Request $request, ProviderCredentials $credentials, string $publicUrl): bool
    {
        $given = (string) $request->header('X-Test-Messenger-Signature', '');

        return $given !== '' && $credentials->get('secret') !== '' && hash_equals(hash_hmac('sha256', $request->getContent(), $credentials->get('secret')), $given);
    }

    public function parseWebhook(Request $request): array
    {
        $events = [];
        foreach ((array) $request->input('events', []) as $event) {
            $id = (string) ($event['id'] ?? '');
            if (($event['type'] ?? null) === 'status' && $id !== '') {
                $events[] = new ProviderEvent(ProviderEvent::STATUS, "st:{$id}:".($event['status'] ?? ''), $id, (string) ($event['status'] ?? ''), $event['error'] ?? null);
            } elseif (($event['type'] ?? null) === 'inbound') {
                $events[] = new ProviderEvent(ProviderEvent::INBOUND, 'in:'.($event['key'] ?? uniqid()), from: (string) ($event['from'] ?? ''), text: (string) ($event['text'] ?? ''));
            }
        }

        return $events;
    }
}
