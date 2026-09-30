<?php

declare(strict_types=1);

namespace App\Domain\Support\Http\Controllers;

use App\Domain\Support\Models\SupportDesk;
use App\Domain\Support\Models\SupportTicket;
use App\Domain\Support\Policies\SupportPolicy;
use App\Domain\Support\Services\SupportAgents;
use App\Domain\Support\Services\SupportDeskService;
use App\Domain\Support\Services\SupportPresenter;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Module 34 — the store's support inbox (its shoppers' requests). */
final class StoreSupportController
{
    use HandlesAgentActions;

    public function __construct(
        private readonly SupportDeskService $desk,
        private readonly SupportPresenter $presenter,
        private readonly SupportAgents $agents,
        private readonly SupportPolicy $policy,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($this->policy->view($request->user()), 403);

        return $this->inbox($request);
    }

    public function show(Request $request, string $ticket): JsonResponse
    {
        abort_unless($this->policy->view($request->user()), 403);

        return $this->detailFor($request, $ticket);
    }

    public function reply(Request $request, string $ticket): JsonResponse
    {
        abort_unless($this->policy->reply($request->user()), 403);

        return $this->agentReply($request, $ticket);
    }

    public function update(Request $request, string $ticket): JsonResponse
    {
        abort_unless($this->policy->reply($request->user()), 403);

        return $this->agentUpdate($request, $ticket, $this->policy->manage($request->user()));
    }

    public function agentsList(Request $request): JsonResponse
    {
        abort_unless($this->policy->view($request->user()), 403);

        return $this->agentsFor($request);
    }

    public function summary(Request $request): JsonResponse
    {
        abort_unless($this->policy->view($request->user()), 403);

        return $this->summaryOf($request);
    }

    protected function tickets(Request $request): Builder
    {
        return SupportTicket::query()->where('desk', SupportDesk::Store->value);
    }

    protected function assignable(SupportTicket $ticket, Request $request): Collection
    {
        return $this->agents->forStore($this->context->storeId());
    }

    protected function abilities(Request $request): array
    {
        return ['reply' => $this->policy->reply($request->user()), 'manage' => $this->policy->manage($request->user())];
    }
}
