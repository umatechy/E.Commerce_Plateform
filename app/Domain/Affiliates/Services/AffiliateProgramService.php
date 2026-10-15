<?php

declare(strict_types=1);

namespace App\Domain\Affiliates\Services;

use App\Domain\Affiliates\Models\AffiliateProgram;
use App\Domain\Affiliates\Models\AffiliateProgramVersion;
use App\Domain\Affiliates\Support\AffiliateRules;
use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Packages\Services\EntitlementService;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase B57 (Module 28 §2.1, §4, §7, §47, §108) — a store's affiliate
 * program: one engine, configured per program. A store has one program;
 * its rules and terms change by new immutable versions, so a commission
 * always shows the rules it was earned under.
 */
final class AffiliateProgramService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ConfigService $config,
    ) {}

    public function current(): ?AffiliateProgram
    {
        return AffiliateProgram::query()->with('currentVersion')->where('status', '!=', 'archived')->orderBy('id')->first();
    }

    /** The store's program, made as a draft the first time: no rules version until the owner saves them. */
    public function ensure(User $by): AffiliateProgram
    {
        $existing = $this->current();
        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($by) {
            $program = AffiliateProgram::query()->create([
                'store_id' => app(TenantContext::class)->storeId(), 'name' => 'Affiliate program', 'status' => 'draft', 'accepts_applications' => true,
            ]);
            $this->audit->record('affiliates.program.created', [], $program, actor: $by);

            return $program->refresh()->load('currentVersion');
        });
    }

    /**
     * New rules or terms: a new version from now on (§108). Commissions
     * already calculated keep theirs.
     *
     * @param array<string, mixed> $rules
     */
    public function update(User $by, AffiliateProgram $program, string $name, bool $acceptsApplications, array $rules, string $terms): AffiliateProgram
    {
        return DB::transaction(function () use ($by, $program, $name, $acceptsApplications, $rules, $terms) {
            $program->update(['name' => $name, 'accepts_applications' => $acceptsApplications]);
            $current = $program->currentVersion;
            if ($current === null || $current->rules != $rules || $current->terms !== $terms) {
                $version = $this->newVersion($program, $by, $rules, $terms);
                $this->audit->record('affiliates.program.rules_changed', ['version' => $version->version, 'rules' => $rules], $program, actor: $by);
            }

            return $program->refresh()->load('currentVersion');
        });
    }

    public function activate(User $by, AffiliateProgram $program): void
    {
        $this->refuseIf(! app(EntitlementService::class)->hasFeature('affiliate.programs'), 'feature_not_entitled', 'Your package does not include an affiliate program.');
        $this->refuseIf($program->status === 'active', 'already_active', 'The program is already running.');
        $this->refuseIf($program->currentVersion === null, 'no_rules', 'Save the program rules first.');
        $program->update(['status' => 'active']);
        $this->audit->record('affiliates.program.activated', ['version' => $program->currentVersion?->version], $program, actor: $by);
    }

    /** Paused: no new clicks or orders count (§71); commissions already earned go on as before. */
    public function pause(User $by, AffiliateProgram $program): void
    {
        $this->refuseIf($program->status !== 'active', 'not_active', 'The program is not running.');
        $program->update(['status' => 'paused']);
        $this->audit->record('affiliates.program.paused', [], $program, actor: $by);
    }

    /**
     * What an affiliate earns, in plain words, from the rules (§47, §135
     * transparency). Shown on the join page and in the portal.
     *
     * @param array<string, mixed> $rules
     * @return list<string>
     */
    public static function summary(array $rules, string $currency): array
    {
        $money = fn (int $minor) => $currency.' '.number_format($minor / 100, 2);
        $lines = [
            $rules['commission_type'] === 'fixed'
                ? 'You earn '.$money((int) $rules['fixed_minor']).' for each order you bring.'
                : 'You earn '.rtrim(rtrim(number_format(((int) $rules['rate_bp']) / 100, 2), '0'), '.').'% of the products in each order you bring, after discounts. Shipping and tax do not count.',
            "An order is yours when the shopper came through your link within {$rules['window_days']} days, or used your coupon code.",
        ];
        if ($rules['new_customers_only']) {
            $lines[] = 'Only first orders of new customers count.';
        }
        if ((int) $rules['min_order_minor'] > 0) {
            $lines[] = 'Orders count from '.$money((int) $rules['min_order_minor']).'.';
        }
        if ($rules['cap_per_order_minor'] !== null) {
            $lines[] = 'At most '.$money((int) $rules['cap_per_order_minor']).' per order.';
        }
        $lines[] = 'A commission is confirmed '.($rules['require_delivery'] ? 'once the order is delivered'.($rules['require_payment'] ? ' and paid' : '') : 'once the order is paid')
            .((int) $rules['hold_days'] > 0 ? " and {$rules['hold_days']} more days have passed, for returns." : '.');
        $lines[] = 'Refunds reduce the commission in the same proportion. Orders you place for yourself do not count.';
        if ((int) $rules['payout_threshold_minor'] > 0) {
            $lines[] = 'Payouts start from '.$money((int) $rules['payout_threshold_minor']).'.';
        }

        return $lines;
    }

    /** @param array<string, mixed> $rules */
    private function newVersion(AffiliateProgram $program, User $by, array $rules, string $terms): AffiliateProgramVersion
    {
        $version = AffiliateProgramVersion::query()->create([
            'store_id' => $program->store_id, 'affiliate_program_id' => $program->id,
            'version' => ((int) AffiliateProgramVersion::query()->where('affiliate_program_id', $program->id)->max('version')) + 1,
            'rules' => $rules, 'terms' => $terms, 'currency' => (string) $this->config->get('store.default_currency'), 'created_by' => $by->id,
        ]);
        $program->update(['current_version_id' => $version->id]);

        return $version;
    }

    private function refuseIf(bool $condition, string $code, string $message): void
    {
        if ($condition) {
            throw new AffiliateRefused($code, $message);
        }
    }
}
