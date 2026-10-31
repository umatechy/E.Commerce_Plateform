<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Services;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Models\NotificationMessageType;
use App\Domain\Notifications\Models\RecipientType;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\SuperAdmin\Support\PlatformRoles;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Models\StoreRequest;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Support\Str;

/**
 * Phase S1 (owner decision 2026-10-30): businesses ask Umar Techy for a
 * store; the team follows the request up and creates the store from it.
 *
 * The person asking gets a confirmation; the platform staff who may create
 * stores (capability `stores`) get the request by email. A second request
 * from the same address while the first is still new updates that one, so
 * a double click or a second try does not make two.
 */
final class StoreRequestService
{
    public function __construct(private readonly NotificationService $notifications, private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data validated */
    public function submit(array $data): StoreRequest
    {
        $email = Str::lower(trim((string) $data['email']));
        $fields = [
            'business_name' => trim((string) $data['business_name']), 'contact_name' => trim((string) $data['contact_name']), 'email' => $email,
            'phone' => trim((string) $data['phone']), 'city' => $data['city'] ?? null, 'business_category' => $data['business_category'] ?? null,
            'package_code' => $data['package_code'] ?? null, 'domain' => isset($data['domain']) ? Str::lower(trim((string) $data['domain'])) : null,
            'hosting' => $data['hosting'], 'message' => $data['message'] ?? null,
        ];
        $open = StoreRequest::query()->where('email', $email)->where('status', 'new')->where('created_at', '>=', now()->subDays(7))->first();
        if ($open !== null) {
            $open->update($fields);

            return $open;
        }
        $request = StoreRequest::query()->create([...$fields, 'status' => 'new']);
        $this->audit->record('platform.store_request_received', ['request' => $request->public_id, 'hosting' => $request->hosting], $request, platform: true);
        $this->notify($request);

        return $request;
    }

    /** The team followed it up: contacted, declined, or a note. */
    public function update(User $by, StoreRequest $request, string $status, ?string $note): StoreRequest
    {
        $request->update(['status' => $status, 'staff_note' => $note, 'handled_by' => $by->id]);
        $this->audit->record('platform.store_request_updated', ['request' => $request->public_id, 'status' => $status], $request, actor: $by, platform: true);

        return $request;
    }

    /** The store was created from the request. */
    public function storeCreated(User $by, StoreRequest $request, Store $store): void
    {
        $request->update(['status' => 'store_created', 'store_id' => $store->id, 'handled_by' => $by->id]);
        $this->audit->record('platform.store_request_store_created', ['request' => $request->public_id, 'store' => $store->public_id], $request, actor: $by, platform: true);
    }

    /** Personal data on request: the request is removed. */
    public function forget(User $by, StoreRequest $request): void
    {
        $this->audit->record('platform.store_request_removed', ['request' => $request->public_id], null, actor: $by, platform: true);
        $request->delete();
    }

    private function notify(StoreRequest $request): void
    {
        app(TenantContext::class)->asPlatform(function () use ($request) {
            $this->notifications->send(
                NotificationMessageType::Transactional, NotificationChannel::Email, RecipientType::Customer, null, $request->email,
                'We received your request for a store',
                'Hi {{contact}}, thank you for asking Umar Techy to build your online store. Our team will contact you soon on {{phone}} or by email to talk about your products, your domain and your hosting.',
                ['contact' => $request->contact_name, 'phone' => $request->phone], "store-request:{$request->id}:received", 'platform.store_request',
            );
            $staff = User::query()->whereNotNull('platform_role')->where('is_active', true)->get()
                ->filter(fn (User $u) => in_array('stores', PlatformRoles::capabilitiesOf($u->platform_role), true));
            foreach ($staff as $user) {
                $this->notifications->send(
                    NotificationMessageType::Transactional, NotificationChannel::Email, RecipientType::User, $user->id, $user->email,
                    'New store request: {{business}}',
                    "{{business}} ({{contact}}, {{phone}}, {{email}}) asks for a store.\nHosting: {{hosting}}. Domain: {{domain}}. Package: {{package}}.\n\n{{message}}\n\nOpen it: {{link}}",
                    [
                        'business' => $request->business_name, 'contact' => $request->contact_name, 'phone' => $request->phone, 'email' => $request->email,
                        'hosting' => ['umar_techy' => 'Umar Techy hosting', 'own_server' => 'their own server', 'not_sure' => 'not decided'][$request->hosting] ?? $request->hosting,
                        'domain' => $request->domain ?? 'none yet', 'package' => $request->package_code ?? 'not chosen', 'message' => (string) $request->message,
                        'link' => url('/super-admin/store-requests?request='.$request->public_id),
                    ],
                    "store-request:{$request->id}:staff:{$user->id}", 'platform.store_request',
                );
            }
        });
    }
}
