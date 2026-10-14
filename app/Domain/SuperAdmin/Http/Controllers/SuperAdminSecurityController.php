<?php

declare(strict_types=1);

namespace App\Domain\SuperAdmin\Http\Controllers;

use App\Domain\DataProtection\Services\BackupStatusReport;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserSession;
use App\Domain\Identity\Services\UserSessions;
use App\Domain\Security\Models\SecurityEvent;
use App\Domain\Security\Models\SecurityIncident;
use App\Domain\Security\Services\EmergencyControls;
use App\Domain\Security\Services\IncidentService;
use App\Domain\SuperAdmin\Services\PlatformStaff;
use App\Domain\SuperAdmin\Support\PlatformRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Phase B52 — Super Admin → Security (Module 32 §85 "Security Dashboard",
 * §58–64; Module 30 §3, §58–61, §87–88). Platform level only; every role
 * can look ("view"), changes need the "security" capability and step-up.
 *
 * - Overview: sign-in failures, lockouts, alerts, MFA coverage of platform
 *   staff and store owners, privileged sessions, open incidents, backup
 *   and restore-test status.
 * - Platform staff: role, two-step sign-in, last use, sessions; change a
 *   role, end someone's sessions.
 * - Security events (filterable), emergency controls, incidents.
 */
final class SuperAdminSecurityController
{
    public function __construct(private readonly EmergencyControls $controls) {}

    public function overview(BackupStatusReport $backups): JsonResponse
    {
        $day = now()->subDay();
        $week = now()->subDays(7);
        $count = fn (string $type, $since, ?\Closure $more = null) => SecurityEvent::query()->where('type', $type)->where('created_at', '>=', $since)->when($more, $more)->count();
        $staff = User::query()->whereNotNull('platform_role');
        $owners = User::query()->whereIn('id', DB::table('store_user')->join('roles', 'roles.id', '=', 'store_user.role_id')->where('roles.slug', 'owner')->where('store_user.status', 'active')->select('store_user.user_id'));
        $backup = $backups->platform();

        return response()->json(['data' => [
            'failed_logins_24h' => $count('login_failed', $day),
            'failed_privileged_logins_24h' => $count('login_failed', $day, fn ($q) => $q->whereIn('user_id', User::query()->whereNotNull('platform_role')->select('id'))),
            'lockouts_24h' => $count('login_locked', $day),
            'alerts_7d' => $count('alert', $week),
            'mfa' => [
                'staff' => (clone $staff)->where('is_active', true)->count(), 'staff_with_mfa' => (clone $staff)->where('is_active', true)->whereNotNull('mfa_confirmed_at')->count(),
                'owners' => (clone $owners)->count(), 'owners_with_mfa' => (clone $owners)->whereNotNull('mfa_confirmed_at')->count(),
            ],
            'privileged_sessions' => UserSession::query()->where('privileged', true)->whereNull('revoked_at')->where('last_seen_at', '>=', now()->subMinutes((int) config('security.sessions.privileged_idle_minutes')))->count(),
            'open_incidents' => SecurityIncident::query()->whereNotIn('status', ['resolved', 'closed'])->selectRaw('severity, count(*) as n')->groupBy('severity')->pluck('n', 'severity'),
            'backups' => ['last_verified_at' => $backup['last_verified_at'] ?? null, 'last_rehearsal_at' => $backup['last_rehearsal_at'] ?? null, 'last_scheduled_status' => $backup['last_scheduled_backup_status'] ?? null],
            'recent_alerts' => SecurityEvent::query()->where('type', 'alert')->orderByDesc('id')->limit(10)->get()->map(fn (SecurityEvent $e) => $this->event($e))->all(),
            'controls' => $this->controls->describe(),
            'roles' => collect(PlatformRoles::ROLES)->map(fn (array $r, string $key) => ['key' => $key, 'label' => $r[0], 'description' => $r[1], 'capabilities' => PlatformRoles::capabilitiesOf($key)])->values(),
            'capabilities' => PlatformRoles::CAPABILITIES,
            'severities' => collect(SecurityIncident::SEVERITIES)->map(fn (array $s, string $key) => ['key' => $key, 'label' => $s[0], 'description' => $s[1]])->values(),
            'session_limits' => ['idle_minutes' => (int) config('security.sessions.privileged_idle_minutes'), 'absolute_hours' => (int) config('security.sessions.privileged_absolute_hours')],
        ]]);
    }

    public function events(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => ['nullable', 'string', 'max:48'], 'severity' => ['nullable', Rule::in(['info', 'low', 'medium', 'high', 'critical'])], 'q' => ['nullable', 'string', 'max:120']]);
        $page = SecurityEvent::query()->with('user:id,name,email')
            ->when($data['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($data['severity'] ?? null, fn ($q, $s) => $q->where('severity', $s))
            ->when($data['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('ip_address', $s)->orWhere('subject', 'like', "%{$s}%")
                ->orWhereIn('user_id', User::query()->where('email', 'like', "%{$s}%")->select('id'))))
            ->orderByDesc('id')->paginate(50);

        return response()->json(['data' => collect($page->items())->map(fn (SecurityEvent $e) => $this->event($e))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function staff(): JsonResponse
    {
        $last = UserSession::query()->selectRaw('user_id, max(last_seen_at) as last_seen, sum(case when revoked_at is null then 1 else 0 end) as open')->groupBy('user_id');
        $rows = User::query()->whereNotNull('platform_role')->leftJoinSub($last, 's', 's.user_id', '=', 'users.id')
            ->orderBy('users.name')->get(['users.*', 's.last_seen', 's.open']);

        return response()->json(['data' => $rows->map(fn (User $u) => [
            'id' => $u->public_id, 'name' => $u->name, 'email' => $u->email, 'role' => PlatformRoles::normalize($u->platform_role), 'role_label' => PlatformRoles::label($u->platform_role),
            'is_active' => $u->is_active, 'mfa' => $u->hasMfaEnabled(), 'last_seen_at' => $u->getAttribute('last_seen') ? \Illuminate\Support\Carbon::parse($u->getAttribute('last_seen'))->toIso8601String() : null,
            'open_sessions' => (int) $u->getAttribute('open'),
        ])->all()]);
    }

    public function changeRole(Request $request, User $user, PlatformStaff $staff): JsonResponse
    {
        $data = $request->validate(['role' => ['nullable', Rule::in(array_keys(PlatformRoles::ROLES))], 'reason' => ['required', 'string', 'min:5', 'max:300']]);
        abort_unless($user->isPlatformStaff(), 422, 'Platform access is given on the server only (platform:staff).');
        $staff->changeRole($user, $data['role'] ?? null, $data['reason'], $request->user(), $request->user()->email);

        return $this->staff();
    }

    public function revokeSessions(Request $request, User $user, UserSessions $sessions): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']])['reason'];
        $count = $sessions->revokeAll($user, 'revoked', $request->user());
        app(\App\Domain\Compliance\Services\AuditLogger::class)->record('security.user_sessions_revoked', ['target' => $user->public_id, 'count' => $count, 'reason' => $reason], $user, actor: $request->user(), platform: true);

        return response()->json(['data' => ['ended' => $count]]);
    }

    public function setControl(Request $request, string $key): JsonResponse
    {
        $data = $request->validate(['value' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:5', 'max:300']]);
        $this->controls->set($key, (bool) $data['value'], $data['reason'], $request->user());

        return response()->json(['data' => $this->controls->describe()]);
    }

    public function incidents(Request $request): JsonResponse
    {
        $open = $request->boolean('open', false);
        $rows = SecurityIncident::query()->with('owner:id,name,email')->when($open, fn ($q) => $q->whereNotIn('status', ['resolved', 'closed']))
            ->orderByRaw("case when status in ('resolved','closed') then 1 else 0 end")->orderBy('severity')->orderByDesc('id')->limit(100)->get();

        return response()->json(['data' => $rows->map(fn (SecurityIncident $i) => $this->incident($i, false))->all()]);
    }

    public function showIncident(SecurityIncident $incident): JsonResponse
    {
        return response()->json(['data' => $this->incident($incident->load(['owner:id,name,email', 'updates.author:id,name,email']), true)]);
    }

    public function openIncident(Request $request, IncidentService $incidents): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:5', 'max:200'], 'severity' => ['required', Rule::in(array_keys(SecurityIncident::SEVERITIES))],
            'component' => ['required', 'string', 'max:60'], 'scope' => ['required', Rule::in(SecurityIncident::SCOPES)],
            'impact' => ['nullable', 'string', 'max:5000'], 'note' => ['nullable', 'string', 'max:5000'],
            'detected_at' => ['nullable', 'date', 'before_or_equal:now'], 'affected_store_ids' => ['nullable', 'array', 'max:500'], 'affected_store_ids.*' => ['integer', 'exists:stores,id'],
        ]);
        $incident = $incidents->open($data, $request->user());

        return $this->showIncident($incident)->setStatusCode(201);
    }

    public function updateIncident(Request $request, SecurityIncident $incident, IncidentService $incidents): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(SecurityIncident::STATUSES)], 'severity' => ['nullable', Rule::in(array_keys(SecurityIncident::SEVERITIES))],
            'owner_id' => ['nullable', 'string', 'size:26'], 'note' => ['nullable', 'string', 'max:5000'], 'resolution' => ['nullable', 'string', 'max:5000'],
            'postmortem_reference' => ['nullable', 'string', 'max:300'], 'impact' => ['nullable', 'string', 'max:5000'],
        ]);
        if (($data['owner_id'] ?? null) !== null) {
            $owner = User::query()->where('public_id', $data['owner_id'])->whereNotNull('platform_role')->firstOrFail();
            $data['owner_id'] = $owner->id;
        }
        $incidents->update($incident, $data, $request->user());

        return $this->showIncident($incident->fresh() ?? $incident);
    }

    /** @return array<string, mixed> */
    private function event(SecurityEvent $e): array
    {
        return [
            'id' => $e->id, 'type' => $e->type, 'severity' => $e->severity, 'at' => $e->created_at->toIso8601String(),
            'user' => $e->user === null ? null : ['name' => $e->user->name, 'email' => $e->user->email], 'subject' => $e->subject,
            'store_id' => $e->store_id, 'ip_address' => $e->ip_address, 'alerted' => $e->alerted, 'request_id' => $e->request_id,
            'summary' => $e->type === 'alert' ? ($e->context['summary'] ?? null) : null, 'details' => $e->type === 'alert' ? ($e->context['what'] ?? null) : null,
            'action' => $e->type === 'alert' ? ($e->context['action'] ?? null) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function incident(SecurityIncident $i, bool $full): array
    {
        return [
            'id' => $i->public_id, 'reference' => $i->reference, 'title' => $i->title, 'severity' => $i->severity, 'status' => $i->status,
            'component' => $i->component, 'scope' => $i->scope, 'affected_store_ids' => $i->affected_store_ids ?? [], 'impact' => $i->impact,
            'owner' => $i->owner === null ? null : ['id' => $i->owner->public_id, 'name' => $i->owner->name, 'email' => $i->owner->email],
            'detected_at' => $i->detected_at->toIso8601String(), 'resolved_at' => $i->resolved_at?->toIso8601String(), 'closed_at' => $i->closed_at?->toIso8601String(),
            'resolution' => $i->resolution, 'postmortem_reference' => $i->postmortem_reference,
            'timeline' => $full ? $i->updates->map(fn ($u) => ['kind' => $u->kind, 'from' => $u->from_value, 'to' => $u->to_value, 'note' => $u->note,
                'author' => $u->author->name ?? $u->author->email ?? null, 'at' => $u->created_at->toIso8601String()])->all() : null,
        ];
    }
}
