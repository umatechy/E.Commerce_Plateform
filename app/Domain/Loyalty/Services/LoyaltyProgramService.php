<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Services;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Loyalty\Models\LoyaltyProgram;
use App\Domain\Packages\Services\EntitlementService;
use App\Domain\Settings\Services\ConfigService;

/**
 * Phase P2: the store's loyalty program — its rules, and turning it on or
 * off. Nothing earns points until the store has saved its own rates and
 * turned the program on, and only while its package includes
 * `loyalty.points` (Module 04 §8: Premium).
 */
final class LoyaltyProgramService
{
    public const FEATURE = 'loyalty.points';

    public function __construct(private readonly AuditLogger $audit) {}

    public function current(): ?LoyaltyProgram
    {
        return LoyaltyProgram::query()->first();
    }

    public function included(): bool
    {
        return app(EntitlementService::class)->hasFeature(self::FEATURE);
    }

    /** The program earns points now: included in the package and turned on. */
    public function running(?LoyaltyProgram $program = null): bool
    {
        $program ??= $this->current();

        return $program !== null && $program->is_enabled && $this->included();
    }

    /** @param array<string, mixed> $rules columns from LoyaltyRules::fromInput() */
    public function save(User $by, array $rules): LoyaltyProgram
    {
        $program = $this->current() ?? new LoyaltyProgram(['is_enabled' => false]);
        $before = $program->exists ? $program->only(array_keys($rules)) : null;
        // The amounts are entered in the store's currency of today.
        $program->fill([...$rules, 'currency' => strtoupper((string) app(ConfigService::class)->get('store.default_currency')), 'updated_by' => $by->id])->save();
        $this->audit->record('loyalty.program_saved', ['before' => $before, 'after' => $program->only(array_keys($rules))], $program, actor: $by);

        return $program;
    }

    /** @throws LoyaltyRefused */
    public function enable(User $by): LoyaltyProgram
    {
        $program = $this->current() ?? throw new LoyaltyRefused('no_program', 'Save your program\'s rules first.');
        if (! $this->included()) {
            throw new LoyaltyRefused('feature_not_entitled', 'Your package does not include loyalty points.');
        }
        if (! $program->is_enabled) {
            // Orders placed from the first start on earn points; turning it off and on again keeps that date.
            $program->update(['is_enabled' => true, 'started_at' => $program->started_at ?? now(), 'updated_by' => $by->id]);
            $this->audit->record('loyalty.program_enabled', [], $program, actor: $by);
        }

        return $program;
    }

    public function disable(User $by): ?LoyaltyProgram
    {
        $program = $this->current();
        if ($program !== null && $program->is_enabled) {
            $program->update(['is_enabled' => false, 'updated_by' => $by->id]);
            $this->audit->record('loyalty.program_disabled', [], $program, actor: $by);
        }

        return $program;
    }
}
