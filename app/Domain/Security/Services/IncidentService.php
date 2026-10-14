<?php

declare(strict_types=1);

namespace App\Domain\Security\Services;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Security\Models\SecurityIncident;
use App\Domain\Security\Models\SecurityIncidentUpdate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase B52 (Module 30 §59 "Security Incidents", §87–88; Module 32
 * §60–64) — incidents with a severity, an owner, a status that moves
 * DETECTED → TRIAGED → INVESTIGATING → CONTAINING → MITIGATING → RESOLVED →
 * CLOSED, and a timeline that is only ever added to.
 *
 * Steps may be skipped (a small incident can go straight to resolved) and
 * a resolved incident can be reopened; a closed incident is final. Closing
 * needs a resolution. Every change is audited at platform level.
 */
final class IncidentService
{
    public function __construct(private readonly AuditLogger $audit, private readonly SecurityMonitor $monitor) {}

    /** @param array{title: string, severity: string, component: string, scope: string, affected_store_ids?: list<int>, impact?: ?string, detected_at?: ?string, owner_id?: ?int, note?: ?string} $data */
    public function open(array $data, User $actor): SecurityIncident
    {
        $incident = DB::transaction(function () use ($data, $actor) {
            $year = now()->year;
            $next = (int) SecurityIncident::query()->where('reference', 'like', "INC-{$year}-%")->lockForUpdate()->count() + 1;
            $incident = SecurityIncident::query()->create([
                'reference' => sprintf('INC-%d-%04d', $year, $next), 'title' => $data['title'], 'severity' => $data['severity'], 'status' => 'detected',
                'component' => $data['component'], 'scope' => $data['scope'], 'affected_store_ids' => $data['affected_store_ids'] ?? null,
                'impact' => $data['impact'] ?? null, 'owner_id' => $data['owner_id'] ?? $actor->id, 'opened_by' => $actor->id,
                'detected_at' => $data['detected_at'] ?? now(),
            ]);
            $this->line($incident, 'opened', null, 'detected', $data['note'] ?? null, $actor);

            return $incident;
        });
        $this->audit->record('security.incident_opened', ['incident' => $incident->reference, 'severity' => $incident->severity, 'title' => $incident->title], $incident, actor: $actor, platform: true);
        $this->monitor->record('incident_opened', $actor, $incident->reference, context: ['severity' => $incident->severity],
            severity: in_array($incident->severity, ['sev0', 'sev1'], true) ? 'critical' : 'medium');

        return $incident;
    }

    /** @param array{status?: ?string, severity?: ?string, owner_id?: ?int, note?: ?string, resolution?: ?string, postmortem_reference?: ?string, impact?: ?string} $data */
    public function update(SecurityIncident $incident, array $data, User $actor): SecurityIncident
    {
        return DB::transaction(function () use ($incident, $data, $actor) {
            $locked = SecurityIncident::query()->lockForUpdate()->findOrFail($incident->id);
            if ($locked->status === 'closed') {
                throw ValidationException::withMessages(['status' => 'A closed incident cannot be changed. Open a new one if it comes back.']);
            }
            $changed = false;
            if (($data['severity'] ?? null) !== null && $data['severity'] !== $locked->severity) {
                $this->line($locked, 'severity', $locked->severity, $data['severity'], null, $actor);
                $locked->severity = $data['severity'];
                $changed = true;
            }
            if (array_key_exists('owner_id', $data) && $data['owner_id'] !== null && $data['owner_id'] !== $locked->owner_id) {
                $this->line($locked, 'owner', (string) $locked->owner_id, (string) $data['owner_id'], null, $actor);
                $locked->owner_id = $data['owner_id'];
                $changed = true;
            }
            foreach (['resolution', 'postmortem_reference', 'impact'] as $field) {
                if (array_key_exists($field, $data) && $data[$field] !== null) {
                    $locked->{$field} = $data[$field];
                    $changed = true;
                }
            }
            if (($data['status'] ?? null) !== null && $data['status'] !== $locked->status) {
                $to = (string) $data['status'];
                if ($to === 'closed' && ($locked->status !== 'resolved' || trim((string) $locked->resolution) === '')) {
                    throw ValidationException::withMessages(['status' => 'Resolve the incident and write its resolution before closing it.']);
                }
                $this->line($locked, 'status', $locked->status, $to, $data['note'] ?? null, $actor);
                $locked->status = $to;
                $locked->resolved_at = $to === 'resolved' ? now() : ($to === 'closed' ? $locked->resolved_at : null);
                $locked->closed_at = $to === 'closed' ? now() : null;
                $changed = true;
            } elseif (trim((string) ($data['note'] ?? '')) !== '') {
                $this->line($locked, 'note', null, null, $data['note'], $actor);
                $changed = true;
            }
            if (! $changed) {
                return $locked;
            }
            $locked->save();
            $this->audit->record('security.incident_updated', ['incident' => $locked->reference, 'status' => $locked->status, 'severity' => $locked->severity], $locked, actor: $actor, platform: true);

            return $locked;
        });
    }

    private function line(SecurityIncident $incident, string $kind, ?string $from, ?string $to, ?string $note, User $actor): void
    {
        SecurityIncidentUpdate::query()->create([
            'security_incident_id' => $incident->id, 'kind' => $kind, 'from_value' => $from, 'to_value' => $to,
            'note' => $note === null || trim($note) === '' ? null : trim($note), 'author_id' => $actor->id,
        ]);
    }
}
