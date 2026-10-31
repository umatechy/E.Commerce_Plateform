<?php

declare(strict_types=1);

namespace App\Domain\Domains\Console;

use App\Domain\Domains\Models\Domain;
use App\Domain\Domains\Models\DomainStatus;
use App\Domain\Domains\Models\DomainType;
use App\Domain\Domains\Models\SslStatus;
use App\Domain\Domains\Services\DomainConnectionService;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Phase S2: moves customers' domains towards live by themselves — ownership
 * (TXT), pointing (A record), certificate — each store under its own tenant
 * context. Domains on their way are looked at every run (at most every ten
 * minutes each); live ones once a day, so a domain moved away shows up.
 */
final class CheckDomainConnectionsCommand extends Command
{
    protected $signature = 'domains:check-connections';

    protected $description = 'Check customers\' domains: ownership, address and certificate; make them live when ready.';

    public function handle(TenantContext $context, DomainConnectionService $connections): int
    {
        $due = Domain::query()->withoutTenantScope()->where('domain_type', DomainType::CustomDomain->value)
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->whereIn('status', [DomainStatus::Pending->value, DomainStatus::VerificationRequired->value, DomainStatus::Verified->value])
                    ->where(fn ($t) => $t->whereNull('dns_checked_at')->orWhere('dns_checked_at', '<=', now()->subMinutes(10))))
                ->orWhere(fn ($w) => $w->where('status', DomainStatus::Active->value)->where('ssl_status', '!=', SslStatus::Active->value))
                ->orWhere(fn ($w) => $w->where('status', DomainStatus::Active->value)->where(fn ($t) => $t->whereNull('dns_checked_at')->orWhere('dns_checked_at', '<=', now()->subDay()))))
            ->orderBy('id')->limit(200)->get();

        $live = 0;
        foreach ($due as $domain) {
            $after = $context->asStore($domain->store_id, fn () => $connections->check(Domain::query()->findOrFail($domain->id)));
            $live += $after->status === DomainStatus::Active && $domain->status !== DomainStatus::Active ? 1 : 0;
        }
        $this->info("Domains: {$due->count()} checked, {$live} became live.");

        return self::SUCCESS;
    }
}
