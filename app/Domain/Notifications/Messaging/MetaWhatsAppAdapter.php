<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Messaging;

use App\Domain\Payments\Providers\CredentialField;
use App\Domain\Payments\Providers\ProviderCredentials;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * WhatsApp through Meta's WhatsApp Business Cloud API (owner decision 3).
 *
 * Built from Meta's public documentation (developers.facebook.com,
 * business-messaging/whatsapp — checked 2026-10-14):
 * - send: POST https://graph.facebook.com/{version}/{phone-number-id}/messages,
 *   Bearer access token, JSON {messaging_product: whatsapp, recipient_type:
 *   individual, to, type: template, template: {name, language: {code},
 *   components: [{type: body, parameters: [{type: text, text}]}]}}; the
 *   answer's messages[0].id names the message;
 * - only templates go out business-initiated (outside a customer service
 *   window), so every notification uses the store's approved template;
 * - webhooks: GET verification with hub.mode / hub.verify_token /
 *   hub.challenge; POST payloads signed in X-Hub-Signature-256
 *   ("sha256=" + SHA-256 HMAC of the body with the App Secret); statuses
 *   (sent, delivered, read, failed) and incoming messages under
 *   entry[].changes[].value.
 *
 * Not yet sent against a live number: that needs a store's (or Umar
 * Techy's) WhatsApp Business account — owner input.
 */
final class MetaWhatsAppAdapter implements MessagingAdapter
{
    /** Meta error codes that mean "slow down", not "never" (rate and spam limits). */
    private const RETRYABLE = [4, 80007, 130429, 131048, 131056];

    public function key(): string
    {
        return 'meta_whatsapp';
    }

    public function label(): string
    {
        return 'WhatsApp (Meta Cloud API)';
    }

    public function capabilities(): MessagingCapabilities
    {
        return new MessagingCapabilities(
            channel: 'whatsapp', templates: true, freeText: false, deliveryReports: true, readReceipts: true,
            inbound: true, templateCheck: true, verifiedApi: true, needsPlatformGoAhead: false,
            note: 'Messages go out as templates approved in your WhatsApp Manager. Customers receive them only after saying yes to WhatsApp updates.',
        );
    }

    public function credentialFields(): array
    {
        return [
            new CredentialField('phone_number_id', 'Phone number ID', secret: false, maxLength: 32, pattern: '/^\d{6,32}$/', help: 'WhatsApp Manager → Phone numbers (a number, not the phone number itself).'),
            new CredentialField('business_account_id', 'WhatsApp Business Account ID', secret: false, maxLength: 32, pattern: '/^\d{6,32}$/', help: 'Used to check that your templates are approved.'),
            new CredentialField('access_token', 'Access token', secret: true, maxLength: 1000, help: 'A permanent (system user) token with whatsapp_business_messaging permission.'),
            new CredentialField('app_secret', 'App secret', secret: true, maxLength: 200, help: 'Your Meta app\'s App Secret: it proves that delivery reports come from Meta.'),
        ];
    }

    public function send(OutgoingMessage $message, ProviderCredentials $credentials): SendOutcome
    {
        if ($message->template === null) {
            return SendOutcome::failed('template_missing', 'No approved WhatsApp template is set for this message.', permanent: true);
        }
        $body = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $message->to,
            'type' => 'template',
            'template' => [
                'name' => $message->template['name'],
                'language' => ['code' => $message->template['language']],
                'components' => $message->template['parameters'] === [] ? [] : [[
                    'type' => 'body',
                    'parameters' => array_map(fn (string $value) => ['type' => 'text', 'text' => $value], $message->template['parameters']),
                ]],
            ],
        ];

        try {
            $response = Http::withToken($credentials->get('access_token'))->acceptJson()->asJson()
                ->connectTimeout(10)->timeout(20)
                ->post($this->graph().'/'.rawurlencode($credentials->get('phone_number_id')).'/messages', $body);
        } catch (ConnectionException) {
            return new SendOutcome(SendOutcome::UNKNOWN, code: 'no_answer', message: 'No answer from Meta.');
        }

        $id = $response->json('messages.0.id');
        if ($response->successful() && is_string($id) && $id !== '') {
            return SendOutcome::accepted($id);
        }
        $code = (int) $response->json('error.code', 0);
        $text = (string) $response->json('error.message', 'Meta did not accept the message.');
        if ($response->serverError() || in_array($code, self::RETRYABLE, true) || $response->status() === 429) {
            return SendOutcome::failed('provider_busy', $text, permanent: false);
        }

        return SendOutcome::failed($response->status() === 401 ? 'credentials_refused' : 'meta_'.$code, $text, permanent: true);
    }

    public function templateStatus(string $name, string $language, ProviderCredentials $credentials): string
    {
        try {
            $response = Http::withToken($credentials->get('access_token'))->acceptJson()->connectTimeout(10)->timeout(20)
                ->get($this->graph().'/'.rawurlencode($credentials->get('business_account_id')).'/message_templates', ['name' => $name]);
        } catch (ConnectionException) {
            return 'unknown';
        }
        if (! $response->successful()) {
            return 'unknown';
        }
        foreach ((array) $response->json('data', []) as $template) {
            if (($template['name'] ?? null) === $name && ($template['language'] ?? null) === $language) {
                return match (strtoupper((string) ($template['status'] ?? ''))) {
                    'APPROVED' => 'approved',
                    'REJECTED' => 'rejected',
                    'PAUSED', 'DISABLED' => 'paused',
                    default => 'submitted',
                };
            }
        }

        return 'unknown';
    }

    public function verifyWebhook(Request $request, ProviderCredentials $credentials, string $publicUrl): bool
    {
        $header = (string) $request->header('X-Hub-Signature-256', '');
        $secret = $credentials->get('app_secret');
        if (! str_starts_with($header, 'sha256=') || $secret === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $request->getContent(), $secret), substr($header, 7));
    }

    public function parseWebhook(Request $request): array
    {
        $events = [];
        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = (array) ($change['value'] ?? []);
                foreach ((array) ($value['statuses'] ?? []) as $status) {
                    $id = (string) ($status['id'] ?? '');
                    $state = (string) ($status['status'] ?? '');
                    if ($id === '' || ! in_array($state, ['sent', 'delivered', 'read', 'failed'], true)) {
                        continue;
                    }
                    $error = (array) (($status['errors'] ?? [])[0] ?? []);
                    $events[] = new ProviderEvent(ProviderEvent::STATUS, "st:{$id}:{$state}", $id, $state,
                        isset($error['code']) ? (string) $error['code'] : null, isset($error['title']) ? mb_substr((string) $error['title'], 0, 255) : null);
                }
                foreach ((array) ($value['messages'] ?? []) as $inbound) {
                    $id = (string) ($inbound['id'] ?? '');
                    if ($id === '') {
                        continue;
                    }
                    $events[] = new ProviderEvent(ProviderEvent::INBOUND, "in:{$id}", from: isset($inbound['from']) ? '+'.ltrim((string) $inbound['from'], '+') : null,
                        text: isset($inbound['text']['body']) ? mb_substr((string) $inbound['text']['body'], 0, 160) : null);
                }
            }
        }

        return $events;
    }

    private function graph(): string
    {
        return 'https://graph.facebook.com/'.config('messaging.meta_graph_version', 'v25.0');
    }
}
