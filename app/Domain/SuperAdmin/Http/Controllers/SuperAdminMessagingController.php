<?php

declare(strict_types=1);

namespace App\Domain\SuperAdmin\Http\Controllers;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Notifications\Messaging\MessagingEvents;
use App\Domain\Notifications\Messaging\MessagingRegistry;
use App\Domain\Notifications\Models\MessagingAccount;
use App\Domain\Notifications\Models\MessagingTemplate;
use App\Domain\Notifications\Services\MessagingAccountService;
use App\Domain\Settings\Models\SettingScope;
use App\Domain\Settings\Services\ConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase B50 — Module 30 §27 "Provider Management", Module 21 §31–34, §54;
 * owner decisions 3 and 4: Umar Techy's own WhatsApp (and SMS) accounts —
 * used for critical alerts to the platform's operators — the critical alert
 * template, which SMS providers may send real messages (after the sender-ID,
 * delivery and pricing checks), and the last 24 hours across all stores.
 * Platform staff only; changes ask for step-up and are audited.
 */
final class SuperAdminMessagingController
{
    public function __construct(
        private readonly MessagingRegistry $registry,
        private readonly MessagingAccountService $accounts,
        private readonly ConfigService $config,
    ) {}

    public function index(): JsonResponse
    {
        $channels = [];
        foreach (['whatsapp', 'sms'] as $channel) {
            $account = $this->accounts->account($channel, null);
            $channels[$channel] = [
                'providers' => array_values(array_map(fn ($adapter) => $this->accounts->describe($adapter, $account, true), $this->registry->forChannel($channel))),
                'connected' => $account?->provider,
            ];
        }
        $stats = \Illuminate\Support\Facades\DB::table('notification_messages')->whereIn('channel', ['sms', 'whatsapp'])->where('created_at', '>=', now()->subDay())
            ->selectRaw('channel, status, count(*) as n')->groupBy('channel', 'status')->get()
            ->groupBy('channel')->map(fn ($rows) => $rows->mapWithKeys(fn ($row) => [(string) $row->status => (int) $row->n]));
        $template = MessagingTemplate::query()->withoutGlobalScopes()->whereNull('store_id')->where('event_key', 'critical_alert')->first();

        return response()->json(['data' => [
            'channels' => $channels,
            'gated' => array_map(fn (string $key) => ['provider' => $key, 'label' => $this->registry->get($key)->label(), 'live_allowed' => $this->registry->liveAllowed($key)], MessagingRegistry::GATED),
            'alert_template' => ['variables' => MessagingEvents::PLATFORM['critical_alert']['variables'], 'whatsapp_body' => MessagingEvents::whatsappBody('critical_alert'),
                'template' => $template === null ? null : ['name' => $template->template_name, 'language' => $template->language_code, 'status' => $template->status, 'checked_at' => $template->checked_at?->toIso8601String()]],
            'alerts' => ['whatsapp_enabled' => (bool) $this->config->get('alerts.whatsapp_enabled'), 'whatsapp_recipients' => count((array) $this->config->get('alerts.whatsapp_recipients'))],
            'last_24h' => $stats,
            'stores_connected' => MessagingAccount::query()->withoutGlobalScopes()->whereNotNull('store_id')->where('is_enabled', true)
                ->selectRaw('provider, count(*) as n')->groupBy('provider')->pluck('n', 'provider'),
        ]]);
    }

    public function setLive(Request $request, string $provider, AuditLogger $audit): JsonResponse
    {
        abort_unless(in_array($provider, MessagingRegistry::GATED, true), 404);
        $data = $request->validate(['allowed' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:5', 'max:300']]);
        $current = (array) $this->config->get('messaging.live_providers');
        $next = $data['allowed'] ? array_values(array_unique([...$current, $provider])) : array_values(array_diff($current, [$provider]));
        $this->config->set('messaging.live_providers', $next, SettingScope::Platform, $request->user()->id, $data['reason']);
        $paused = 0;
        if (! $data['allowed']) {
            $paused = MessagingAccount::query()->withoutGlobalScopes()->where('provider', $provider)->where('environment', 'production')->where('is_enabled', true)->update(['is_enabled' => false]);
        }
        $audit->record($data['allowed'] ? 'messaging.provider_opened_for_live' : 'messaging.provider_closed_for_live', ['provider' => $provider, 'reason' => $data['reason'], 'accounts_switched_off' => $paused], null, null, $request->user(), platform: true);

        return response()->json(['data' => ['live_providers' => $next, 'accounts_switched_off' => $paused]]);
    }

    public function saveAccount(Request $request, string $provider): JsonResponse
    {
        $data = $request->validate(['environment' => ['required', 'string'], 'credentials' => ['nullable', 'array'], 'credentials.*' => ['nullable', 'string', 'max:1000']]);

        return response()->json(['data' => $this->accounts->describe($this->registry->get($provider), $this->accounts->save($provider, $data, $request->user(), null), true)]);
    }

    public function setEnabled(Request $request, string $channel): JsonResponse
    {
        $enabled = $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        $account = $this->accounts->account($channel, null) ?? abort(404);

        return response()->json(['data' => $this->accounts->describe($this->registry->get($account->provider), $this->accounts->setEnabled($account, $enabled, $request->user()), true)]);
    }

    public function disconnect(Request $request, string $channel): JsonResponse
    {
        $account = $this->accounts->account($channel, null) ?? abort(404);
        $this->accounts->disconnect($account, $request->user());

        return response()->json(null, 204);
    }

    public function test(Request $request, string $channel): JsonResponse
    {
        $number = $request->validate(['number' => ['required', 'string', 'max:20']])['number'];
        $account = $this->accounts->account($channel, null) ?? abort(404);

        return response()->json(['data' => $this->accounts->testSend($account, $number, $request->user())]);
    }

    public function saveTemplate(Request $request): JsonResponse
    {
        $data = $request->validate(['template_name' => ['required', 'string', 'max:120'], 'language_code' => ['required', 'string', 'max:16', Rule::notIn([''])]]);
        $mapping = $this->accounts->saveTemplate('critical_alert', trim($data['template_name']), trim($data['language_code']), $request->user(), null);

        return response()->json(['data' => ['name' => $mapping->template_name, 'language' => $mapping->language_code, 'status' => $mapping->status]]);
    }
}
