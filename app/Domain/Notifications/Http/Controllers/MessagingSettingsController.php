<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Http\Controllers;

use App\Domain\Notifications\Messaging\MessagingEvents;
use App\Domain\Notifications\Messaging\MessagingRegistry;
use App\Domain\Notifications\Models\MessagingTemplate;
use App\Domain\Notifications\Models\NotificationMessage;
use App\Domain\Notifications\Models\NotificationStatus;
use App\Domain\Notifications\Policies\NotificationPolicy;
use App\Domain\Notifications\Services\MessagingAccountService;
use App\Domain\Settings\Models\SettingScope;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Phase B50 (gap G10; Module 21 §18–19, §23, §31–37, §46, §50–52): the
 * store's SMS and WhatsApp — accounts, which order updates go where, the
 * WhatsApp templates that carry them, test messages, the daily limit.
 *
 * Reading: notifications.view. Updates, templates, limit: notifications.manage.
 * Accounts: messaging.providers (Owner and Administrator by default) and a
 * fresh password for credentials (step-up, on the routes).
 */
final class MessagingSettingsController
{
    public function __construct(
        private readonly MessagingRegistry $registry,
        private readonly MessagingAccountService $accounts,
        private readonly ConfigService $config,
        private readonly TenantContext $context,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $policy = app(NotificationPolicy::class);
        abort_unless($policy->view($request->user()), 403);
        $storeId = $this->context->storeId();
        $canProviders = $policy->providers($request->user());
        $templates = MessagingTemplate::query()->get()->keyBy('event_key');

        $channels = [];
        foreach (['whatsapp', 'sms'] as $channel) {
            $account = $this->accounts->account($channel, $storeId);
            $channels[$channel] = [
                'providers' => array_values(array_map(fn ($adapter) => $this->accounts->describe($adapter, $account, $canProviders), $this->registry->forChannel($channel))),
                'connected' => $account?->provider,
                'sent_today' => NotificationMessage::query()->where('channel', $channel)->whereIn('status', [NotificationStatus::Sent, NotificationStatus::Delivered])->where('sent_at', '>=', now()->startOfDay())->count(),
            ];
        }

        return response()->json(['data' => [
            'can_manage' => $policy->manage($request->user()),
            'can_configure_providers' => $canProviders,
            'channels' => $channels,
            'events' => array_map(fn (string $key) => [
                'key' => $key, 'label' => MessagingEvents::STORE[$key]['label'], 'variables' => MessagingEvents::STORE[$key]['variables'],
                'text' => MessagingEvents::STORE[$key]['text'], 'whatsapp_body' => MessagingEvents::whatsappBody($key),
                'template' => ($t = $templates->get($key)) === null ? null : ['name' => $t->template_name, 'language' => $t->language_code, 'status' => $t->status, 'checked_at' => $t->checked_at?->toIso8601String()],
            ], MessagingEvents::storeKeys()),
            'sms_events' => (array) $this->config->get('messaging.sms_events'),
            'whatsapp_events' => (array) $this->config->get('messaging.whatsapp_events'),
            'daily_limit' => (int) $this->config->get('messaging.daily_limit'),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless(app(NotificationPolicy::class)->manage($request->user()), 403);
        $data = $request->validate([
            'sms_events' => ['present', 'array'], 'sms_events.*' => ['string', Rule::in(MessagingEvents::storeKeys())],
            'whatsapp_events' => ['present', 'array'], 'whatsapp_events.*' => ['string', Rule::in(MessagingEvents::storeKeys())],
            'daily_limit' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);
        $actor = $request->user()->id;
        DB::transaction(function () use ($data, $actor) {
            $this->config->set('messaging.sms_events', array_values($data['sms_events']), SettingScope::Store, $actor);
            $this->config->set('messaging.whatsapp_events', array_values($data['whatsapp_events']), SettingScope::Store, $actor);
            $this->config->set('messaging.daily_limit', $data['daily_limit'], SettingScope::Store, $actor);
        });

        return response()->json(['data' => ['saved' => true]]);
    }

    public function saveAccount(Request $request, string $provider): JsonResponse
    {
        abort_unless(app(NotificationPolicy::class)->providers($request->user()), 403);
        $data = $request->validate(['environment' => ['required', 'string'], 'credentials' => ['nullable', 'array'], 'credentials.*' => ['nullable', 'string', 'max:1000']]);
        $account = $this->accounts->save($provider, $data, $request->user(), $this->context->storeId());

        return response()->json(['data' => $this->accounts->describe($this->registry->get($provider), $account, true)]);
    }

    public function setEnabled(Request $request, string $channel): JsonResponse
    {
        abort_unless(app(NotificationPolicy::class)->providers($request->user()), 403);
        $enabled = $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        $account = $this->accounts->account($channel, $this->context->storeId()) ?? abort(404);

        return response()->json(['data' => $this->accounts->describe($this->registry->get($account->provider), $this->accounts->setEnabled($account, $enabled, $request->user()), true)]);
    }

    public function disconnect(Request $request, string $channel): JsonResponse
    {
        abort_unless(app(NotificationPolicy::class)->providers($request->user()), 403);
        $account = $this->accounts->account($channel, $this->context->storeId()) ?? abort(404);
        $this->accounts->disconnect($account, $request->user());

        return response()->json(null, 204);
    }

    public function test(Request $request, string $channel): JsonResponse
    {
        abort_unless(app(NotificationPolicy::class)->providers($request->user()), 403);
        $number = $request->validate(['number' => ['required', 'string', 'max:20']])['number'];
        $account = $this->accounts->account($channel, $this->context->storeId()) ?? abort(404);

        return response()->json(['data' => $this->accounts->testSend($account, $number, $request->user())]);
    }

    public function saveTemplate(Request $request, string $event): JsonResponse
    {
        abort_unless(app(NotificationPolicy::class)->manage($request->user()), 403);
        $data = $request->validate(['template_name' => ['required', 'string', 'max:120'], 'language_code' => ['required', 'string', 'max:16']]);
        $mapping = $this->accounts->saveTemplate($event, trim($data['template_name']), trim($data['language_code']), $request->user(), $this->context->storeId());

        return response()->json(['data' => ['name' => $mapping->template_name, 'language' => $mapping->language_code, 'status' => $mapping->status, 'checked_at' => $mapping->checked_at?->toIso8601String()]]);
    }

    public function checkTemplate(Request $request, string $event): JsonResponse
    {
        abort_unless(app(NotificationPolicy::class)->manage($request->user()), 403);
        $mapping = MessagingTemplate::query()->where('event_key', $event)->firstOrFail();
        $mapping = $this->accounts->checkTemplate($mapping);

        return response()->json(['data' => ['name' => $mapping->template_name, 'language' => $mapping->language_code, 'status' => $mapping->status, 'checked_at' => $mapping->checked_at?->toIso8601String()]]);
    }
}
