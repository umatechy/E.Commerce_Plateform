<?php

declare(strict_types=1);

namespace App\Domain\SuperAdmin\Http\Controllers;

use App\Domain\Support\Http\Controllers\HandlesAgentActions;
use App\Domain\Support\Models\SupportDesk;
use App\Domain\Support\Models\SupportTicket;
use App\Domain\Support\Services\SupportAgents;
use App\Domain\Support\Services\SupportDeskService;
use App\Domain\Support\Services\SupportPresenter;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Module 34 — the platform's support inbox: every store's platform-desk
 * tickets (super_admin.platform group: platform context, audited). A
 * store's shopper tickets are never visible here.
 */
final class SuperAdminSupportController
{
    use HandlesAgentActions;

    public function __construct(
        private readonly SupportDeskService $desk,
        private readonly SupportPresenter $presenter,
        private readonly SupportAgents $agents,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['store' => ['nullable', 'string', 'size:26']]);

        return $this->inbox($request);
    }

    public function show(Request $request, string $ticket): JsonResponse
    {
        return $this->detailFor($request, $ticket);
    }

    public function reply(Request $request, string $ticket): JsonResponse
    {
        return $this->agentReply($request, $ticket);
    }

    public function update(Request $request, string $ticket): JsonResponse
    {
        return $this->agentUpdate($request, $ticket, true);
    }

    public function agentsList(Request $request): JsonResponse
    {
        return $this->agentsFor($request);
    }

    public function summary(Request $request): JsonResponse
    {
        return $this->summaryOf($request);
    }

    protected function tickets(Request $request): Builder
    {
        return SupportTicket::query()
            ->with(['store' => fn ($q) => $q->withTrashed()])
            ->where('desk', SupportDesk::Platform->value)
            ->when($request->query('store'), fn ($q, $store) => $q->where('store_id', Store::query()->withTrashed()->where('public_id', $store)->value('id') ?? 0));
    }

    protected function assignable(SupportTicket $ticket, Request $request): Collection
    {
        return $this->agents->forPlatform();
    }

    protected function abilities(Request $request): array
    {
        return ['reply' => true, 'manage' => true];
    }
}
