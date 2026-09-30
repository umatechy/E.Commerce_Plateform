<?php

declare(strict_types=1);

namespace App\Domain\Support\Http\Controllers;

use App\Domain\Support\Models\SupportCategory;
use App\Domain\Support\Models\SupportDesk;
use App\Domain\Support\Models\SupportStatus;
use App\Domain\Support\Models\SupportTicket;
use App\Domain\Support\Services\SupportActionRefusedException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

/** Validation and response helpers shared by the support controllers. */
trait HandlesSupportRequests
{
    /** @return array<string, list<mixed>> */
    protected function openRules(SupportDesk $desk): array
    {
        return [
            'subject' => ['required', 'string', 'max:200'],
            'category' => ['required', Rule::in(array_map(fn (SupportCategory $c) => $c->value, SupportCategory::forDesk($desk)))],
            'message' => ['required', 'string', 'max:'.(int) config('support.max_message_length')],
        ];
    }

    /** @return array<string, list<mixed>> */
    protected function messageRules(): array
    {
        return ['body' => ['required', 'string', 'max:'.(int) config('support.max_message_length')]];
    }

    /** @return array<string, list<mixed>> */
    protected function listRules(): array
    {
        return [
            'status' => ['nullable', Rule::in(['active', 'all', ...array_map(fn (SupportStatus $s) => $s->value, SupportStatus::cases())])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @param Builder<SupportTicket> $query
     * @param array<string, mixed> $filters
     * @return Builder<SupportTicket>
     */
    protected function applyStatusFilter(Builder $query, array $filters): Builder
    {
        return match ($filters['status'] ?? 'all') {
            'all' => $query,
            'active' => $query->whereIn('status', [SupportStatus::Open->value, SupportStatus::AwaitingCustomer->value, SupportStatus::OnHold->value]),
            default => $query->where('status', $filters['status']),
        };
    }

    /**
     * @param \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, SupportTicket> $page
     * @param callable(SupportTicket): array<string, mixed> $present
     */
    protected function paginated($page, callable $present): JsonResponse
    {
        return response()->json(['data' => [
            'tickets' => collect($page->items())->map($present)->all(),
            'pagination' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]]);
    }

    /** @param callable(): JsonResponse $action */
    protected function orRefused(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (SupportActionRefusedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], 409);
        }
    }
}
