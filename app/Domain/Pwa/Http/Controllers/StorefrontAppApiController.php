<?php

declare(strict_types=1);

namespace App\Domain\Pwa\Http\Controllers;

use App\Domain\Orders\Models\Customer;
use App\Domain\Pwa\Models\PushSubscription;
use App\Domain\Pwa\Services\PwaConfig;
use App\Domain\Settings\Services\Locales;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Phase B55 (Module 25 §51–54, §68–69) — the store app's calls from the
 * storefront.
 *
 * Push: a signed-in shopper, on their own device and only after they said
 * yes in the browser (§53), lets the store send notifications. The
 * subscription belongs to the store and the shopper resolved by the
 * server, never to one named in the request (§54). Sending them is
 * Module 21's job and is not built yet.
 *
 * Counters: installs, launches from the home screen, offline views and
 * push choices, per store and day, with no device identifier (§69).
 */
final class StorefrontAppApiController
{
    public function __construct(private readonly PwaConfig $pwa) {}

    public function subscribe(Request $request): JsonResponse
    {
        $store = $this->store($request);
        abort_unless($this->pwa->pushOffered($store), 404);
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:1000', 'url:https'],
            'keys.p256dh' => ['required', 'string', 'max:200', 'regex:/^[A-Za-z0-9_\-]+={0,2}$/'],
            'keys.auth' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_\-]+={0,2}$/'],
            'locale' => ['nullable', Rule::in(array_keys(Locales::SUPPORTED))],
        ]);
        /** @var Customer $customer */
        $customer = $request->user();
        $hash = hash('sha256', $data['endpoint']);

        $subscription = PushSubscription::query()->firstOrNew(['endpoint_hash' => $hash]);
        $subscription->fill([
            'store_id' => $store->id,
            'customer_id' => $customer->id,
            'endpoint' => $data['endpoint'],
            'public_key' => $data['keys']['p256dh'],
            'auth_secret' => $data['keys']['auth'],
            'locale' => $data['locale'] ?? 'en',
            'browser' => self::browser((string) $request->userAgent()),
            'status' => 'active',
            'revoked_at' => null,
        ])->save();
        $this->count($store, 'push_on');

        return response()->json(['message' => 'Notifications are on for this device.'], 201);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $store = $this->store($request);
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:1000']]);
        /** @var Customer $customer */
        $customer = $request->user();
        $updated = PushSubscription::query()->where('endpoint_hash', hash('sha256', $data['endpoint']))->where('customer_id', $customer->id)
            ->where('status', 'active')->update(['status' => 'revoked', 'revoked_at' => now()]);
        if ($updated > 0) {
            $this->count($store, 'push_off');
        }

        return response()->json(['message' => 'Notifications are off for this device.']);
    }

    public function event(Request $request): JsonResponse
    {
        $store = $this->store($request);
        $data = $request->validate(['event' => ['required', Rule::in((array) config('pwa.events'))]]);
        abort_unless($this->pwa->available($store), 404);
        if (in_array($data['event'], ['push_on', 'push_off'], true)) {
            abort(422, 'Push choices are counted by the server.'); // only counted when the subscription itself changes
        }
        $this->count($store, $data['event']);

        return response()->json(null, 204);
    }

    private function count(Store $store, string $event): void
    {
        $day = now()->toDateString();
        DB::table('pwa_daily_stats')->insertOrIgnore(['store_id' => $store->id, 'day' => $day, 'event' => $event, 'count' => 0]);
        DB::table('pwa_daily_stats')->where(['store_id' => $store->id, 'day' => $day, 'event' => $event])->increment('count');
    }

    /** "Chrome on Android": enough to tell devices apart in a list, nothing that identifies one. */
    public static function browser(string $agent): ?string
    {
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => null,
        };
        $system = match (true) {
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        return $browser === null ? $system : ($system === null ? $browser : "{$browser} on {$system}");
    }

    private function store(Request $request): Store
    {
        return $request->attributes->get('storefront.store');
    }
}
