<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Models\AuditActorType;
use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Compliance\Models\AuditSurface;
use App\Domain\DeveloperPlatform\Support\ApiKeyContext;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Customer;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Module 32 — the only writer of audit_logs (Phase B22).
 *
 * The write is synchronous and joins the caller's transaction (ADR-004:
 * "the audit record's own write is synchronous"): an action that rolls
 * back leaves no audit entry claiming it happened. Who acted, through
 * which surface, on which store and under which impersonation is derived
 * server-side from the authenticated principal, ApiKeyContext and
 * TenantContext — callers only say WHAT happened.
 */
final class AuditLogger
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ApiKeyContext $apiKey,
    ) {}

    /**
     * @param array<string, mixed> $context  free-form details; secrets are redacted
     * @param ?int $storeId  owning store when it differs from the resolved
     *                       tenant (e.g. a platform action on one store);
     *                       otherwise the resolved store, or none
     * @param ?Authenticatable $actor  explicit actor when the request user is
     *                                 not (yet) the right one, e.g. at login
     * @param bool $platform  record in the platform chain regardless of any
     *                        resolved store (identity events of staff users,
     *                        who are not owned by any single store)
     */
    public function record(
        string $action,
        array $context = [],
        ?Model $subject = null,
        ?int $storeId = null,
        ?Authenticatable $actor = null,
        bool $platform = false,
    ): AuditLog {
        $storeId = $platform ? null : ($storeId ?? $this->resolvedStoreId());
        $chainKey = $storeId === null ? 'platform' : "store:{$storeId}";
        $principal = $this->actorAttributes($actor);
        $request = app()->bound('request') ? app('request') : null;

        $row = [
            'public_id' => (string) Str::ulid(),
            'store_id' => $storeId,
            'chain_key' => $chainKey,
            'action' => $action,
            ...$principal,
            ...$this->impersonatorAttributes(),
            'subject_type' => $subject !== null ? Str::snake(class_basename($subject)) : null,
            'subject_id' => $subject?->getKey(),
            'subject_public_id' => $subject?->getAttribute('public_id'),
            'context' => AuditHasher::canonicalJson($this->redact($context)),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent() !== null ? Str::limit($request->userAgent(), 250, '') : null,
            'request_id' => $request?->attributes->get(\App\Http\Middleware\AssignRequestId::ATTRIBUTE), // SRS API-011
            'created_at' => now()->format('Y-m-d H:i:s'),
        ];

        $entry = DB::transaction(function () use ($row, $chainKey) {
            // Row lock on the chain head serializes appends to this chain,
            // so sequence numbers and hash links are gap-free under
            // concurrency. The head is created on first use.
            DB::table('audit_chain_heads')->insertOrIgnore(['chain_key' => $chainKey, 'last_sequence' => 0, 'anchor_sequence' => 0]);
            $head = DB::table('audit_chain_heads')->where('chain_key', $chainKey)->lockForUpdate()->first();

            $row['sequence'] = (int) $head->last_sequence + 1;
            $row['previous_hash'] = $head->last_hash;
            $row['hash'] = AuditHasher::hash($row);

            $id = DB::table('audit_logs')->insertGetId($row);

            DB::table('audit_chain_heads')->where('chain_key', $chainKey)->update([
                'last_sequence' => $row['sequence'],
                'last_hash' => $row['hash'],
                'updated_at' => now(),
            ]);

            return AuditLog::query()->findOrFail($id);
        });

        if (config('compliance.audit.mirror_to_log')) {
            // Same message and caller context as the pre-B22 file audit log,
            // so existing log shipping and alerting keep working unchanged.
            Log::channel('audit')->info($action, $context);
        }

        return $entry;
    }

    private function resolvedStoreId(): ?int
    {
        return ! $this->tenant->isPlatform() && $this->tenant->hasStore() ? $this->tenant->storeId() : null;
    }

    /** @return array{actor_type: string, actor_id: ?int, actor_public_id: ?string, actor_label: ?string, surface: string} */
    private function actorAttributes(?Authenticatable $explicit): array
    {
        if ($explicit === null && $this->apiKey->isSet()) {
            $key = $this->apiKey->get();

            return $this->actor(AuditActorType::ApiKey, $key->id, $key->public_id, $key->key_prefix, AuditSurface::DeveloperApi);
        }

        $principal = $explicit ?? Auth::user();

        if ($principal instanceof User) {
            $surface = $principal->isPlatformStaff() && ($this->tenant->isPlatform() || $this->tenant->isImpersonating())
                ? AuditSurface::SuperAdmin
                : AuditSurface::Staff;

            return $this->actor(AuditActorType::User, $principal->id, $principal->public_id, $principal->email, $surface);
        }

        if ($principal instanceof Customer) {
            return $this->actor(AuditActorType::Customer, $principal->id, $principal->public_id, $principal->email, AuditSurface::Customer);
        }

        // No principal: the scheduler/queue/console, or an unauthenticated
        // HTTP request (e.g. a failed login). The test runner is a console
        // process too, but it stands in for HTTP traffic.
        return app()->runningInConsole() && ! app()->runningUnitTests()
            ? $this->actor(AuditActorType::System, null, null, 'system', AuditSurface::System)
            : $this->actor(AuditActorType::Anonymous, null, null, null, AuditSurface::Public);
    }

    /** @return array{actor_type: string, actor_id: ?int, actor_public_id: ?string, actor_label: ?string, surface: string} */
    private function actor(AuditActorType $type, ?int $id, ?string $publicId, ?string $label, AuditSurface $surface): array
    {
        return [
            'actor_type' => $type->value,
            'actor_id' => $id,
            'actor_public_id' => $publicId,
            'actor_label' => $label !== null ? Str::limit($label, 188) : null,
            'surface' => $surface->value,
        ];
    }

    /** @return array{impersonator_id: ?int, impersonator_label: ?string} */
    private function impersonatorAttributes(): array
    {
        if (! $this->tenant->isImpersonating()) {
            return ['impersonator_id' => null, 'impersonator_label' => null];
        }

        $superAdminId = $this->tenant->actingSuperAdminId();

        return [
            'impersonator_id' => $superAdminId,
            'impersonator_label' => $superAdminId !== null ? User::query()->whereKey($superAdminId)->value('email') : null,
        ];
    }

    /**
     * @param array<array-key, mixed> $context
     * @return array<array-key, mixed>
     */
    private function redact(array $context): array
    {
        $patterns = config('compliance.audit.redact_keys', []);
        $maxLength = (int) config('compliance.audit.max_value_length', 1000);

        foreach ($context as $key => $value) {
            if (is_string($key) && Str::contains(strtolower($key), $patterns)) {
                $context[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $context[$key] = $this->redact($value);
            } elseif ($value instanceof \BackedEnum) {
                $context[$key] = $value->value;
            } elseif ($value instanceof \DateTimeInterface) {
                $context[$key] = $value->format(DATE_ATOM);
            } elseif (is_string($value) && mb_strlen($value) > $maxLength) {
                $context[$key] = mb_substr($value, 0, $maxLength).'…';
            } elseif (is_object($value)) {
                $context[$key] = method_exists($value, '__toString') ? (string) $value : $value::class;
            }
        }

        return $context;
    }
}
