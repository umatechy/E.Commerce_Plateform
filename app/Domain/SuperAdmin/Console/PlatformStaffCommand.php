<?php

declare(strict_types=1);

namespace App\Domain\SuperAdmin\Console;

use App\Domain\Identity\Models\User;
use App\Domain\SuperAdmin\Services\PlatformStaff;
use App\Domain\SuperAdmin\Support\PlatformRoles;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Phase B52 (Module 30 §3, §126–127 break-glass; Module 32 §65–66) — the
 * only way to give an account platform access, or to change a role when
 * nobody can sign in to the Super Admin (break-glass). Needs shell access
 * to the server, the operator's name, a reason and the email typed again;
 * audited and alerted like a change from the screen. The account must
 * already exist (it signs up or is invited like any other) and must use
 * two-step sign-in like all platform staff.
 */
final class PlatformStaffCommand extends Command
{
    protected $signature = 'platform:staff
        {email : The account}
        {role? : super_admin, platform_admin, operations_admin, support_admin, billing_admin, security_admin, read_only_auditor}
        {--remove : Take platform access away}
        {--operator= : Who is running this (your name)}
        {--reason= : Why, with the ticket or incident reference}
        {--confirm= : The account email again; required when not run interactively}';

    protected $description = 'Give, change or remove an account\'s Umar Techy platform role (server only; audited).';

    public function handle(PlatformStaff $staff): int
    {
        $operator = trim((string) $this->option('operator'));
        $reason = trim((string) $this->option('reason'));
        if ($operator === '' || mb_strlen($reason) < 10) {
            $this->error('Both --operator (your name) and --reason (at least 10 characters) are required.');

            return self::INVALID;
        }
        $role = $this->option('remove') ? null : (string) $this->argument('role');
        if ($role !== null && ! isset(PlatformRoles::ROLES[$role])) {
            $this->error('Role must be one of: '.implode(', ', array_keys(PlatformRoles::ROLES)).' (or use --remove).');

            return self::INVALID;
        }
        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            $this->error("No account has the email {$email}. It must sign up or be invited first.");

            return self::FAILURE;
        }
        $confirm = (string) ($this->option('confirm') ?? ($this->input->isInteractive() ? $this->ask('Type the account email again to confirm') : ''));
        if (mb_strtolower(trim($confirm)) !== mb_strtolower($email)) {
            $this->error('The confirmation does not match. Nothing was changed.');

            return self::FAILURE;
        }
        try {
            $staff->changeRole($user, $role, $reason, null, "console:{$operator}");
        } catch (ValidationException $e) {
            $this->error((string) collect($e->errors())->flatten()->first());

            return self::FAILURE;
        }
        $this->info($role === null ? "{$email} no longer has platform access." : "{$email} is now ".PlatformRoles::label($role).'.');
        if ($role !== null && ! $user->hasMfaEnabled()) {
            $this->warn('This account has no two-step sign-in yet: it must set it up at its next sign-in before it can use the Super Admin.');
        }

        return self::SUCCESS;
    }
}
