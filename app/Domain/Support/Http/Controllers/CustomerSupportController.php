<?php

declare(strict_types=1);

namespace App\Domain\Support\Http\Controllers;

use App\Domain\Orders\Models\Customer;
use App\Domain\Orders\Models\Order;
use App\Domain\Support\Models\SupportCategory;
use App\Domain\Support\Models\SupportDesk;
use App\Domain\Support\Models\SupportTicket;
use App\Domain\Support\Services\SupportDeskService;
use App\Domain\Support\Services\SupportPresenter;
use App\Domain\Support\Services\SupportRequester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Module 34 — a signed-in customer's own support requests with their
 * store. A ticket that is not theirs is simply not found.
 */
final class CustomerSupportController
{
    use HandlesSupportRequests;

    public function __construct(
        private readonly SupportDeskService $desk,
        private readonly SupportPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate($this->listRules());
        $customer = $this->customer($request);
        $page = $this->applyStatusFilter($this->own($customer), $filters)
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 20));

        return $this->paginated($page, fn (SupportTicket $t) => $this->presenter->summary($t, false));
    }

    public function store(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        $validated = $request->validate([...$this->openRules(SupportDesk::Store), 'order' => ['nullable', 'string', 'size:26']]);

        $orderId = null;
        if (isset($validated['order'])) {
            $orderId = Order::query()->where('public_id', $validated['order'])->where('customer_id', $customer->id)->value('id');
            if ($orderId === null) {
                throw ValidationException::withMessages(['order' => 'This order was not found in your account.']);
            }
        }

        [$ticket] = $this->desk->open(SupportDesk::Store, $customer->store_id, SupportRequester::customer($customer), [
            'subject' => $validated['subject'],
            'category' => SupportCategory::from($validated['category']),
            'message' => $validated['message'],
            'order_id' => $orderId,
        ]);

        return response()->json(['data' => $this->presenter->detail($ticket->refresh()->load(['messages', 'order']), false)], 201);
    }

    public function show(Request $request, string $ticket): JsonResponse
    {
        return response()->json(['data' => $this->presenter->detail($this->find($request, $ticket), false)]);
    }

    public function reply(Request $request, string $ticket): JsonResponse
    {
        $found = $this->find($request, $ticket);
        $validated = $request->validate($this->messageRules());
        $customer = $this->customer($request);

        return $this->orRefused(function () use ($found, $validated, $customer) {
            $this->desk->replyAsRequester($found, $customer->name, $customer->id, $validated['body']);

            return response()->json(['data' => $this->presenter->detail($found->refresh()->load(['messages', 'order']), false)], 201);
        });
    }

    public function resolve(Request $request, string $ticket): JsonResponse
    {
        $found = $this->find($request, $ticket);

        return $this->orRefused(fn () => response()->json(['data' => $this->presenter->detail($this->desk->resolveByRequester($found)->load(['messages', 'order']), false)]));
    }

    public function rate(Request $request, string $ticket): JsonResponse
    {
        $found = $this->find($request, $ticket);
        $validated = $request->validate(['rating' => ['required', 'integer', 'min:1', 'max:5'], 'comment' => ['nullable', 'string', 'max:1000']]);

        return $this->orRefused(fn () => response()->json(['data' => $this->presenter->detail(
            $this->desk->rate($found, (int) $validated['rating'], $validated['comment'] ?? null)->load(['messages', 'order']), false,
        )]));
    }

    /** @return \Illuminate\Database\Eloquent\Builder<SupportTicket> */
    private function own(Customer $customer): \Illuminate\Database\Eloquent\Builder
    {
        return SupportTicket::query()
            ->where('desk', SupportDesk::Store->value)
            ->where('requester_type', SupportTicket::REQUESTER_CUSTOMER)
            ->where('requester_id', $customer->id);
    }

    private function find(Request $request, string $publicId): SupportTicket
    {
        return $this->own($this->customer($request))->where('public_id', $publicId)->with(['messages', 'order'])->firstOrFail();
    }

    private function customer(Request $request): Customer
    {
        return $request->user();
    }
}
