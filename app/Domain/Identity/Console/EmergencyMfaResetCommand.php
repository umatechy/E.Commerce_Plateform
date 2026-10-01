<?php

declare(strict_types=1);

namespace App\Domain\Identity\Console;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\MfaService;
use Illuminate\Console\Command;

/**
 * The controlled emergency MFA reset (owner decision 2026-10-01;
 * Module 32 §8.3, §66): for a staff account whose authenticator AND
 * recovery codes are both lost.
 *
 * It exists only as a console command, so it needs shell access to the
 * server. There is no HTTP route for it and no screen. It follows
 * docs/security/incident-response-runbook.md "Emergency MFA reset".
 *
 * It refuses to run unless the operator names themselves, gives the
 * reason, and repeats the account's email as confirmation. It never
 * reads or prints the old secret or the recovery codes. Afterwards the
 * account has no MFA, so an account that must have MFA (platform staff,
 * store owners) is sent to enrollment before it can do anything else.
 */
final class EmergencyMfaResetCommand extends Command
{
    protected $signature = 'mfa:emergency-reset
        {email : The email of the one account to reset}
        {--operator= : Who is running this (your name)}
        {--reason= : Why, with the incident or ticket reference}
        {--confirm= : The account email again; required when not run interactively}';

    protected $description = 'Emergency reset of one staff account\'s MFA (runbook procedure; audited).';

    public function handle(MfaService $mfa): int
    {
        $operator = trim((string) $this->option('operator'));
        $reason = trim((string) $this->option('reason'));

        if ($operator === '' || mb_strlen($reason) < 10) {
            $this->error('Both --operator (your name) and --reason (at least 10 characters, with the incident reference) are required.');

            return self::INVALID;
        }

        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No staff account has the email {$email}.");

            return self::FAILURE;
        }

        if ($user->mfa_secret === null) {
            $this->error('This account has no MFA to reset.');

            return self::FAILURE;
        }

        $this->line("Account:  {$user->name} <{$user->email}>");
        $this->line('Type:     '.($user->isPlatformStaff() ? "platform staff ({$user->platform_role})" : ($user->ownsAStore() ? 'store owner' : 'store staff')));
        $this->line("Operator: {$operator}");
        $this->line("Reason:   {$reason}");

        $confirmation = $this->option('confirm') ?? ($this->input->isInteractive() ? $this->ask('Type the account email again to reset its MFA') : null);

        if (! is_string($confirmation) || ! hash_equals(mb_strtolower($user->email), mb_strtolower(trim($confirmation)))) {
            $this->error('The confirmation does not match the account email. Nothing was changed.');

            return self::FAILURE;
        }

        $mfa->emergencyReset($user, $operator, $reason);

        $this->info('MFA was reset. The old secret and recovery codes are destroyed and remembered sign-ins are revoked.');
        $this->line('Next (runbook): verify the person, have them set a new password, and have them enrol MFA again at once.');

        return self::SUCCESS;
    }
}
