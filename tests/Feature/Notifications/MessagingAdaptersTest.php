<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domain\Notifications\Messaging\MetaWhatsAppAdapter;
use App\Domain\Notifications\Messaging\OutgoingMessage;
use App\Domain\Notifications\Messaging\PhoneNumber;
use App\Domain\Notifications\Messaging\ProviderEvent;
use App\Domain\Notifications\Messaging\SendOutcome;
use App\Domain\Notifications\Messaging\TwilioSmsAdapter;
use App\Domain\Payments\Providers\ProviderCredentials;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase B50 — the Meta WhatsApp Cloud API and Twilio SMS adapters against
 * faked HTTP (no network, no real credentials): the request each sends,
 * how answers map to accepted / retry / give up / unknown, webhook
 * signatures, and reading delivery reports. Phone numbers in E.164.
 *
 * These pin the adapters to the providers' published documentation
 * (docs/architecture/b50-whatsapp-sms.md §4). A live check with real
 * accounts is still owed before production (owner decision 3).
 */
final class MessagingAdaptersTest extends TestCase
{
    private function meta(): ProviderCredentials
    {
        return new ProviderCredentials(['phone_number_id' => '1065403522', 'business_account_id' => '1029384756', 'access_token' => 'EAAJ-token', 'app_secret' => 'meta-app-secret'], ProviderCredentials::PRODUCTION);
    }

    private function twilio(string $sender = '+15005550006'): ProviderCredentials
    {
        return new ProviderCredentials(['account_sid' => 'AC'.str_repeat('a', 32), 'auth_token' => 'twilio-token', 'sender' => $sender], ProviderCredentials::SANDBOX);
    }

    private function template(): OutgoingMessage
    {
        return new OutgoingMessage('+923001234567', 'ignored by WhatsApp', ['name' => 'order_confirmed_v1', 'language' => 'en', 'parameters' => ['Ayesha', 'Rose House']]);
    }

    public function test_phone_numbers_become_e164_with_pakistan_as_the_default_country(): void
    {
        $this->assertSame('+923001234567', PhoneNumber::e164('0300 1234567'));
        $this->assertSame('+923001234567', PhoneNumber::e164('0300-123-4567'));
        $this->assertSame('+923001234567', PhoneNumber::e164('00923001234567'));
        $this->assertSame('+447700900123', PhoneNumber::e164('+44 7700 900123'));
        $this->assertNull(PhoneNumber::e164('12345'));
        $this->assertNull(PhoneNumber::e164(''));
        $this->assertNull(PhoneNumber::e164(null));
        $this->assertSame('+92300•••4567', PhoneNumber::mask('+923001234567'));
    }

    public function test_meta_sends_an_approved_template_with_its_values_in_order(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.HBgM']]])]);

        $outcome = (new MetaWhatsAppAdapter())->send($this->template(), $this->meta());

        $this->assertSame(SendOutcome::ACCEPTED, $outcome->outcome);
        $this->assertSame('wamid.HBgM', $outcome->providerMessageId);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://graph.facebook.com/'.config('messaging.meta_graph_version').'/1065403522/messages'
            && $request->hasHeader('Authorization', 'Bearer EAAJ-token')
            && $request['type'] === 'template' && $request['to'] === '+923001234567'
            && $request['template']['name'] === 'order_confirmed_v1' && $request['template']['language'] === ['code' => 'en']
            && $request['template']['components'][0]['parameters'] === [['type' => 'text', 'text' => 'Ayesha'], ['type' => 'text', 'text' => 'Rose House']]);
    }

    public function test_meta_answers_map_to_retry_give_up_or_unknown_and_free_text_is_never_sent(): void
    {
        $adapter = new MetaWhatsAppAdapter();
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['error' => ['code' => 131056, 'message' => 'Pair rate limit hit']], 400)
            ->push(['error' => ['code' => 131026, 'message' => 'Message undeliverable']], 400)
            ->push(['error' => ['code' => 190, 'message' => 'Invalid token']], 401)
            ->push(['error' => ['code' => 1, 'message' => 'Server']], 503)
            ->pushFailedConnection('timed out')]);

        $busy = $adapter->send($this->template(), $this->meta());
        $this->assertSame(['provider_busy', false], [$busy->code, $busy->permanent]);
        $undeliverable = $adapter->send($this->template(), $this->meta());
        $this->assertSame(['meta_131026', true], [$undeliverable->code, $undeliverable->permanent]);
        $refused = $adapter->send($this->template(), $this->meta());
        $this->assertSame(['credentials_refused', true], [$refused->code, $refused->permanent]);
        $this->assertFalse($adapter->send($this->template(), $this->meta())->permanent);

        $noTemplate = $adapter->send(new OutgoingMessage('+923001234567', 'free text'), $this->meta());
        $this->assertSame(['template_missing', true], [$noTemplate->code, $noTemplate->permanent]);
        Http::assertSentCount(4);
        $this->assertSame(SendOutcome::UNKNOWN, $adapter->send($this->template(), $this->meta())->outcome);
    }

    public function test_meta_reads_template_approval_from_the_business_account(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [
            ['name' => 'order_confirmed_v1', 'language' => 'ur', 'status' => 'REJECTED'],
            ['name' => 'order_confirmed_v1', 'language' => 'en', 'status' => 'APPROVED'],
        ]])]);
        $adapter = new MetaWhatsAppAdapter();

        $this->assertSame('approved', $adapter->templateStatus('order_confirmed_v1', 'en', $this->meta()));
        $this->assertSame('rejected', $adapter->templateStatus('order_confirmed_v1', 'ur', $this->meta()));
        $this->assertSame('unknown', $adapter->templateStatus('order_confirmed_v1', 'fr', $this->meta()));
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/1029384756/message_templates?name=order_confirmed_v1'));
    }

    public function test_meta_webhooks_need_the_app_secret_signature_and_their_reports_are_read(): void
    {
        $raw = (string) json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
            'statuses' => [
                ['id' => 'wamid.A', 'status' => 'delivered'],
                ['id' => 'wamid.B', 'status' => 'failed', 'errors' => [['code' => 131026, 'title' => 'Message undeliverable']]],
                ['id' => 'wamid.C', 'status' => 'deleted'],
            ],
            'messages' => [['id' => 'wamid.IN', 'from' => '923001234567', 'type' => 'text', 'text' => ['body' => 'STOP']]],
        ]]]]]]);
        $adapter = new MetaWhatsAppAdapter();
        $signed = Request::create('/hook', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'meta-app-secret')], $raw);
        $forged = Request::create('/hook', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'guess')], $raw);

        $this->assertTrue($adapter->verifyWebhook($signed, $this->meta(), ''));
        $this->assertFalse($adapter->verifyWebhook($forged, $this->meta(), ''));
        $events = $adapter->parseWebhook($signed);
        $this->assertCount(3, $events, 'unknown states are skipped');
        $this->assertSame([ProviderEvent::STATUS, 'wamid.A', 'delivered'], [$events[0]->kind, $events[0]->providerMessageId, $events[0]->status]);
        $this->assertSame(['failed', '131026', 'Message undeliverable'], [$events[1]->status, $events[1]->errorCode, $events[1]->errorText]);
        $this->assertSame([ProviderEvent::INBOUND, '+923001234567', 'STOP'], [$events[2]->kind, $events[2]->from, $events[2]->text]);
    }

    public function test_twilio_sends_a_form_with_the_sender_or_messaging_service_and_maps_answers(): void
    {
        Http::fake(['api.twilio.com/*' => Http::sequence()
            ->push(['sid' => 'SM'.str_repeat('1', 32), 'status' => 'queued'], 201)
            ->push(['sid' => 'SM'.str_repeat('2', 32), 'status' => 'queued'], 201)
            ->push(['code' => 21211, 'message' => "The 'To' number is not valid."], 400)
            ->push(['code' => 20429, 'message' => 'Too many requests'], 429)]);
        $adapter = new TwilioSmsAdapter();
        $message = new OutgoingMessage('+923001234567', 'Your order UT-1001 is confirmed.', statusCallbackUrl: 'https://shop.example/api/v1/messaging-webhooks/abc');

        $this->assertSame('SM'.str_repeat('1', 32), $adapter->send($message, $this->twilio())->providerMessageId);
        $adapter->send($message, $this->twilio('MG'.str_repeat('f', 32)));
        $invalid = $adapter->send($message, $this->twilio());
        $this->assertSame(['twilio_21211', true], [$invalid->code, $invalid->permanent]);
        $this->assertSame(['provider_busy', false], [$adapter->send($message, $this->twilio())->code, false]);

        $sent = Http::recorded();
        [$first] = $sent[0];
        $this->assertSame('https://api.twilio.com/2010-04-01/Accounts/AC'.str_repeat('a', 32).'/Messages.json', $first->url());
        $this->assertTrue($first->hasHeader('Authorization', 'Basic '.base64_encode('AC'.str_repeat('a', 32).':twilio-token')));
        $this->assertSame(['To' => '+923001234567', 'Body' => 'Your order UT-1001 is confirmed.', 'From' => '+15005550006', 'StatusCallback' => 'https://shop.example/api/v1/messaging-webhooks/abc'], $first->data());
        [$second] = $sent[1];
        $this->assertSame('MG'.str_repeat('f', 32), $second->data()['MessagingServiceSid']);
        $this->assertArrayNotHasKey('From', $second->data());
    }

    public function test_twilio_webhooks_are_signed_over_the_url_and_sorted_fields_and_their_reports_are_read(): void
    {
        $url = 'https://shop.example/api/v1/messaging-webhooks/abc';
        $fields = ['MessageSid' => 'SM9', 'MessageStatus' => 'undelivered', 'ErrorCode' => '30003', 'AccountSid' => 'AC1'];
        // Twilio's documented scheme: the full URL, then each field name and value sorted by name, HMAC-SHA1 with the auth token, base64.
        $expected = base64_encode(hash_hmac('sha1', $url.'AccountSidAC1ErrorCode30003MessageSidSM9MessageStatusundelivered', 'twilio-token', true));
        $this->assertSame($expected, TwilioSmsAdapter::signature($url, $fields, 'twilio-token'));

        $adapter = new TwilioSmsAdapter();
        $request = Request::create('/hook', 'POST', $fields, [], [], ['HTTP_X_TWILIO_SIGNATURE' => $expected]);
        $this->assertTrue($adapter->verifyWebhook($request, $this->twilio(), $url));
        $this->assertFalse($adapter->verifyWebhook($request, $this->twilio(), $url.'?x=1'));
        $tampered = Request::create('/hook', 'POST', ['MessageStatus' => 'delivered'] + $fields, [], [], ['HTTP_X_TWILIO_SIGNATURE' => $expected]);
        $this->assertFalse($adapter->verifyWebhook($tampered, $this->twilio(), $url));

        [$event] = $adapter->parseWebhook($request);
        $this->assertSame(['st:SM9:failed', 'SM9', 'failed', '30003'], [$event->key, $event->providerMessageId, $event->status, $event->errorCode]);
        [$inbound] = $adapter->parseWebhook(Request::create('/hook', 'POST', ['MessageSid' => 'SM8', 'SmsStatus' => 'received', 'From' => '+923001234567', 'Body' => 'STOP']));
        $this->assertSame([ProviderEvent::INBOUND, '+923001234567', 'STOP'], [$inbound->kind, $inbound->from, $inbound->text]);
    }
}
