<?php

declare(strict_types=1);

namespace App\Domain\Support\Http\Controllers;

use App\Domain\Identity\Models\User;
use App\Domain\Support\Models\SupportCategory;
use App\Domain\Support\Models\SupportPriority;
use App\Domain\Support\Models\SupportStatus;
use App\Domain\Support\Models\SupportTicket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The answering team's actions, shared by the store inbox and the
 * platform (Super Admin) inbox. The using controller supplies the desk's
 * ticket query and its assignable agents.
 */
trait HandlesAgentActions
{
    use HandlesSupportRequests;

    /** @return Builder<SupportTicket> */
    abstract protected function tickets(Request $request): Builder;

    /** @return Collection<int, User> */
    abstract protected function assignable(SupportTicket $ticket, Request $request): Collection;

    /**
     * What the signed-in agent may do here, so the inbox can hide controls
     * it would refuse anyway (the server still checks every request).
     *
     * @return array{reply: bool, manage: bool}
     */
    abstract protected function abilities(Request $request): array;

    protected function inbox(Request $request): JsonResponse
    {
        $filters = $request->validate([
            ...$this->listRules(),
            'priority' => ['nullable', Rule::enum(SupportPriority::class)],
            'category' => ['nullable', Rule::enum(SupportCategory::class)],
            'assignee' => ['nullable', 'string', 'max:26'], // "me", "unassigned" or a user public id
            'breached' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $user = $request->user();

        $query = $this->applyStatusFilter($this->tickets($request)->with('assignee'), ['status' => $filters['status'] ?? 'active'])
            ->when($filters['priority'] ?? null, fn ($q, $p) => $q->where('priority', $p))
            ->when($filters['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when(($filters['assignee'] ?? null) === 'me', fn ($q) => $q->where('assignee_id', $user->id))
            ->when(($filters['assignee'] ?? null) === 'unassigned', fn ($q) => $q->whereNull('assignee_id'))
            ->when(! in_array($filters['assignee'] ?? null, [null, 'me', 'unassigned'], true),
                fn ($q) => $q->whereIn('assignee_id', User::query()->where('public_id', $filters['assignee'])->select('id')))
            ->when($request->boolean('breached'), fn ($q) => $q->whereNotNull('sla_breached_at'))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $like = '%'.addcslashes((string) $term, '%_\\').'%';
                $q->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('subject', 'like', $like)
                    ->orWhere('requester_email', 'like', $like)->orWhere('requester_name', 'like', $like));
            })
            // Overdue first, then the oldest waiting ticket.
            ->orderByRaw('sla_breached_at IS NULL')
            ->orderBy('first_response_due_at')
            ->orderBy('id');

        return $this->paginated($query->paginate((int) ($filters['per_page'] ?? 25)), fn (SupportTicket $t) => $this->presenter->summary($t, true));
    }

    protected function detailFor(Request $request, string $publicId): JsonResponse
    {
        return response()->json(['data' => $this->presenter->detail($this->findTicket($request, $publicId), true)]);
    }

    protected function agentReply(Request $request, string $publicId): JsonResponse
    {
        $ticket = $this->findTicket($request, $publicId);
        $validated = $request->validate([
            ...$this->messageRules(),
            'internal' => ['sometimes', 'boolean'],
            'status' => ['nullable', Rule::in([SupportStatus::Open->value, SupportStatus::AwaitingCustomer->value, SupportStatus::OnHold->value, SupportStatus::Resolved->value])],
        ]);

        return $this->orRefused(function () use ($request, $ticket, $validated) {
            $this->desk->replyAsAgent($ticket, $request->user(), $validated['body'], (bool) ($validated['internal'] ?? false),
                isset($validated['status']) ? SupportStatus::from($validated['status']) : null);

            return response()->json(['data' => $this->presenter->detail($this->findTicket($request, $ticket->public_id), true)], 201);
        });
    }

    /** @param bool $mayManage assign / priority / category, beyond status changes */
    protected function agentUpdate(Request $request, string $publicId, bool $mayManage): JsonResponse
    {
        $ticket = $this->findTicket($request, $publicId);
        $validated = $request->validate([
            'status' => ['sometimes', Rule::enum(SupportStatus::class)],
            'priority' => ['sometimes', Rule::enum(SupportPriority::class)],
            // Only the topics this ticket's desk offers (no "billing" on a shopper's ticket).
            'category' => ['sometimes', Rule::in(array_map(fn (SupportCategory $c) => $c->value, SupportCategory::forDesk($ticket->desk)))],
            'assignee' => ['sometimes', 'nullable', 'string', 'size:26'],
        ]);

        if (! $mayManage && array_diff(array_keys($validated), ['status']) !== []) {
            abort(403, 'Assigning and re-prioritising tickets needs the support.manage permission.');
        }

        $changes = [];
        foreach (['status' => SupportStatus::class, 'priority' => SupportPriority::class, 'category' => SupportCategory::class] as $field => $enum) {
            if (isset($validated[$field])) {
                $changes[$field] = $enum::from($validated[$field]);
            }
        }

        if (array_key_exists('assignee', $validated)) {
            $assignee = $validated['assignee'] === null ? null : $this->assignable($ticket, $request)->firstWhere('public_id', $validated['assignee']);

            if ($validated['assignee'] !== null && $assignee === null) {
                throw ValidationException::withMessages(['assignee' => 'This person cannot be assigned support tickets here.']);
            }

            $changes['assignee_id'] = $assignee?->id;
        }

        $this->desk->update($ticket, $request->user(), $changes);

        return $this->detailFor($request, $publicId);
    }

    protected function agentsFor(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->assignable(new SupportTicket(), $request)
            ->map(fn (User $u) => ['id' => $u->public_id, 'name' => $u->name])->values()]);
    }

    /** Workload and service figures for the team's dashboard. */
    protected function summaryOf(Request $request): JsonResponse
    {
        $active = [SupportStatus::Open->value, SupportStatus::AwaitingCustomer->value, SupportStatus::OnHold->value];
        $since = now()->subDays(30);
        // null (not 0) when there is nothing to average yet.
        $firstResponse = $this->tickets($request)->where('created_at', '>=', $since)->whereNotNull('first_responded_at')
            ->value(DB::raw('AVG(TIMESTAMPDIFF(MINUTE, created_at, first_responded_at))'));
        $satisfaction = $this->tickets($request)->where('created_at', '>=', $since)->whereNotNull('satisfaction_rating')->avg('satisfaction_rating');

        return response()->json(['data' => [
            'abilities' => $this->abilities($request),
            'by_status' => $this->tickets($request)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')->map(fn ($n) => (int) $n),
            'unassigned' => $this->tickets($request)->whereIn('status', $active)->whereNull('assignee_id')->count(),
            'breached' => $this->tickets($request)->whereIn('status', $active)->whereNotNull('sla_breached_at')->count(),
            'mine' => $this->tickets($request)->whereIn('status', $active)->where('assignee_id', $request->user()->id)->count(),
            'last_30_days' => [
                'created' => $this->tickets($request)->where('created_at', '>=', $since)->count(),
                'avg_first_response_minutes' => $firstResponse === null ? null : (int) round((float) $firstResponse),
                'satisfaction_avg' => $satisfaction === null ? null : round((float) $satisfaction, 2),
            ],
        ]]);
    }

    private function findTicket(Request $request, string $publicId): SupportTicket
    {
        return $this->tickets($request)->where('public_id', $publicId)->with(['messages', 'order', 'assignee'])->firstOrFail();
    }
}
