<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Messaging;

use App\Domain\Payments\Providers\CredentialField;
use App\Domain\Payments\Providers\ProviderCredentials;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * SMS through Twilio's Programmable Messaging (owner decision 3 names
 * Twilio, or a Pakistani provider, "after API, sender-ID, delivery and
 * pricing verification").
 *
 * The API itself follows Twilio's public documentation: POST
 * https://api.twilio.com/2010-04-01/Accounts/{AccountSid}/Messages.json with
 * HTTP Basic auth (Account SID, Auth Token) and form fields To, Body, From
 * (or MessagingServiceSid) and StatusCallback; the answer's `sid` names the
 * message. Delivery reports are signed in X-Twilio-Signature: Base64 of the
 * HMAC-SHA1, keyed with the Auth Token, of the full callback URL followed by
 * every POST parameter, sorted by name, name and value appended.
 *
 * The rest of owner decision 3 — sender ID registration for Pakistan,
 * delivery and pricing — is a business check. Until Umar Techy has done it,
 * production use stays closed (needsPlatformGoAhead).
 */
final class TwilioSmsAdapter implements MessagingAdapter
{
    public function key(): string
    {
        return 'twilio';
    }

    public function label(): string
    {
        return 'SMS (Twilio)';
    }

    public function capabilities(): MessagingCapabilities
    {
        return new MessagingCapabilities(
            channel: 'sms', templates: false, freeText: true, deliveryReports: true, readReceipts: false,
            inbound: true, templateCheck: false, verifiedApi: true, needsPlatformGoAhead: true,
            note: 'Real SMS open once Umar Techy has checked sender ID, delivery and prices for Pakistan.',
        );
    }

    public function credentialFields(): array
    {
        return [
            new CredentialField('account_sid', 'Account SID', secret: false, maxLength: 34, pattern: '/^AC[0-9a-fA-F]{32}$/'),
            new CredentialField('auth_token', 'Auth token', secret: true, maxLength: 64),
            new CredentialField('sender', 'Sender', secret: false, maxLength: 40, pattern: '/^(\+[1-9]\d{7,14}|MG[0-9a-fA-F]{32}|[A-Za-z0-9 ]{1,11})$/', help: 'Your Twilio number (+…), a Messaging Service SID (MG…), or a registered sender name.'),
        ];
    }

    public function send(OutgoingMessage $message, ProviderCredentials $credentials): SendOutcome
    {
        $sender = $credentials->get('sender');
        $form = ['To' => $message->to, 'Body' => $message->text, ...(str_starts_with($sender, 'MG') ? ['MessagingServiceSid' => $sender] : ['From' => $sender])];
        if ($message->statusCallbackUrl !== null) {
            $form['StatusCallback'] = $message->statusCallbackUrl;
        }

        try {
            $response = Http::withBasicAuth($credentials->get('account_sid'), $credentials->get('auth_token'))->asForm()->acceptJson()
                ->connectTimeout(10)->timeout(20)
                ->post('https://api.twilio.com/2010-04-01/Accounts/'.rawurlencode($credentials->get('account_sid')).'/Messages.json', $form);
        } catch (ConnectionException) {
            return new SendOutcome(SendOutcome::UNKNOWN, code: 'no_answer', message: 'No answer from Twilio.');
        }

        $sid = $response->json('sid');
        if ($response->successful() && is_string($sid) && $sid !== '') {
            return SendOutcome::accepted($sid);
        }
        $text = (string) $response->json('message', 'Twilio did not accept the message.');
        if ($response->serverError() || $response->status() === 429) {
            return SendOutcome::failed('provider_busy', $text, permanent: false);
        }

        return SendOutcome::failed($response->status() === 401 ? 'credentials_refused' : 'twilio_'.$response->json('code', $response->status()), $text, permanent: true);
    }

    public function templateStatus(string $name, string $language, ProviderCredentials $credentials): string
    {
        return 'unknown'; // SMS needs no approved templates
    }

    public function verifyWebhook(Request $request, ProviderCredentials $credentials, string $publicUrl): bool
    {
        $given = (string) $request->header('X-Twilio-Signature', '');
        $token = $credentials->get('auth_token');
        if ($given === '' || $token === '') {
            return false;
        }

        return hash_equals(self::signature($publicUrl, $request->request->all(), $token), $given);
    }

    /** @param array<string, mixed> $params */
    public static function signature(string $url, array $params, string $authToken): string
    {
        ksort($params, SORT_STRING);
        $data = $url;
        foreach ($params as $name => $value) {
            $data .= $name.(is_scalar($value) ? (string) $value : '');
        }

        return base64_encode(hash_hmac('sha1', $data, $authToken, true));
    }

    public function parseWebhook(Request $request): array
    {
        $sid = (string) $request->input('MessageSid', $request->input('SmsSid', ''));
        if ($sid === '') {
            return [];
        }
        $status = (string) $request->input('MessageStatus', $request->input('SmsStatus', ''));
        if ($status === 'received') {
            return [new ProviderEvent(ProviderEvent::INBOUND, "in:{$sid}", from: (string) $request->input('From', ''), text: mb_substr((string) $request->input('Body', ''), 0, 160))];
        }
        $mapped = match ($status) {
            'sent' => 'sent',
            'delivered' => 'delivered',
            'undelivered', 'failed' => 'failed',
            default => null,
        };

        return $mapped === null ? [] : [new ProviderEvent(ProviderEvent::STATUS, "st:{$sid}:{$mapped}", $sid, $mapped,
            $request->filled('ErrorCode') ? (string) $request->input('ErrorCode') : null)];
    }
}
