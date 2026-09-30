<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Listeners;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;

/**
 * Module 32 / ADR-002: authentication outcomes on the staff session
 * surface are security events. Staff identities are not owned by a
 * single store, so these land in the platform chain; the customer
 * surface records its own events with the customer's store
 * (CustomerAuthController / LoginCustomerRequest).
 *
 * Passwords are never recorded: of the attempted credentials only the
 * email is kept.
 */
final class RecordAuthenticationEvents
{
    /**
     * Resolved per event, never injected: Event::subscribe() builds this
     * subscriber once at boot, and AuditLogger depends on the request-
     * scoped TenantContext, which a long-lived worker resets between jobs.
     */
    private function audit(): AuditLogger
    {
        return app(AuditLogger::class);
    }

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'onLogin',
            Failed::class => 'onFailed',
            Logout::class => 'onLogout',
            Lockout::class => 'onLockout',
        ];
    }

    public function onLogin(Login $event): void
    {
        if ($event->user instanceof User) {
            $this->audit()->record('auth.login.succeeded', ['guard' => $event->guard, 'remember' => $event->remember], $event->user, actor: $event->user, platform: true);
        }
    }

    public function onFailed(Failed $event): void
    {
        if ($event->guard !== 'customer') {
            $this->audit()->record('auth.login.failed', ['guard' => $event->guard, 'email' => $event->credentials['email'] ?? null], platform: true);
        }
    }

    public function onLogout(Logout $event): void
    {
        if ($event->user instanceof User) {
            $this->audit()->record('auth.logout', ['guard' => $event->guard], $event->user, actor: $event->user, platform: true);
        }
    }

    public function onLockout(Lockout $event): void
    {
        // Fired by both login surfaces; a customer lockout is scoped to the
        // store resolved for that request, a staff one is platform-level.
        $isCustomer = $event->request->is('api/v1/customer/*');

        $this->audit()->record('auth.lockout', [
            'surface' => $isCustomer ? 'customer' : 'staff',
            'email' => $event->request->input('email'),
        ], platform: ! $isCustomer);
    }
}
