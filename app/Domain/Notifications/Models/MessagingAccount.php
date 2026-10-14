<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Models;

use App\Domain\Payments\Providers\ProviderCredentials;
use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase B50 — Module 21 §31–32, §44 (1–2): an SMS or WhatsApp account. A
 * store's own (its customers' messages; the store pays its provider), or
 * Umar Techy's (store_id null: platform messages such as critical alerts).
 * Tenant-scoped: a store sees only its own; credentials encrypted and never
 * serialised. `webhook_token` names the account in its webhook address.
 *
 * @property int $id
 * @property string $public_id
 * @property ?int $store_id
 * @property string $channel
 * @property string $provider
 * @property string $environment
 * @property bool $is_enabled
 * @property array<string, string> $credentials
 * @property ?array<string, string> $credential_hints
 * @property string $webhook_token
 * @property ?\Illuminate\Support\Carbon $last_success_at
 * @property ?\Illuminate\Support\Carbon $last_failure_at
 * @property ?string $last_failure_message
 * @property ?int $updated_by
 */
final class MessagingAccount extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $table = 'messaging_accounts';

    protected $fillable = [
        'store_id', 'channel', 'provider', 'environment', 'is_enabled', 'credentials', 'credential_hints',
        'webhook_token', 'last_success_at', 'last_failure_at', 'last_failure_message', 'updated_by',
    ];

    protected $hidden = ['credentials', 'webhook_token'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'credentials' => 'encrypted:array',
            'credential_hints' => 'array',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }

    public function providerCredentials(): ProviderCredentials
    {
        return new ProviderCredentials(array_map('strval', (array) $this->credentials), $this->environment);
    }

    public function webhookUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/v1/messaging-webhooks/'.$this->webhook_token;
    }
}
