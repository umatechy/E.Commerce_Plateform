<?php

declare(strict_types=1);

namespace App\Domain\Support\Http\Controllers;

use App\Domain\Identity\Models\User;
use App\Domain\Support\Models\SupportCategory;
use App\Domain\Support\Models\SupportDesk;
use App\Domain\Support\Models\SupportTicket;
use App\Domain\Support\Policies\SupportPolicy;
use App\Domain\Support\Services\SupportDeskService;
use App\Domain\Support\Services\SupportPresenter;
use App\Domain\Support\Services\SupportRequester;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Module 34 — a store's team asking the platform for help (billing,
 * technical, account). Every member with support.platform (or the
 * Owner) sees all of the store's platform tickets: the conversation
 * belongs to the store, not to whoever opened it.
 */
final class MerchantPlatformSupportController
{
    use HandlesSupportRequests;

    public function __construct(
        private readonly SupportDeskService $desk,
        private readonly SupportPresenter $presenter,
        private readonly SupportPolicy $policy,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize($request);
        $filters = $request->validate($this->listRules());
        $page = $this->applyStatusFilter($this->tickets(), $filters)->orderByDesc('updated_at')->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 20));

        return $this->paginated($page, fn (SupportTicket $t) => $this->presenter->summary($t, false));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize($request);
        $validated = $request->validate($this->openRules(SupportDesk::Platform));
        /** @var User $user */
        $user = $request->user();

        [$ticket] = $this->desk->open(SupportDesk::Platform, $this->context->storeId(), SupportRequester::user($user), [
            'subject' => $validated['subject'],
            'category' => SupportCategory::from($validated['category']),
            'message' => $validated['message'],
        ]);

        return response()->json(['data' => $this->presenter->detail($ticket->refresh()->load(['messages', 'order']), false)], 201);
    }

    public function show(Request $request, string $ticket): JsonResponse
    {
        $this->authorize($request);

        return response()->json(['data' => $this->presenter->detail($this->find($ticket), false)]);
    }

    public function reply(Request $request, string $ticket): JsonResponse
    {
        $this->authorize($request);
        $found = $this->find($ticket);
        $validated = $request->validate($this->messageRules());
        /** @var User $user */
        $user = $request->user();

        return $this->orRefused(function () use ($found, $validated, $user) {
            $this->desk->replyAsRequester($found, $user->name, $user->id, $validated['body']);

            return response()->json(['data' => $this->presenter->detail($this->find($found->public_id), false)], 201);
        });
    }

    public function resolve(Request $request, string $ticket): JsonResponse
    {
        $this->authorize($request);
        $found = $this->find($ticket);

        return $this->orRefused(fn () => response()->json(['data' => $this->presenter->detail($this->desk->resolveByRequester($found)->load(['messages', 'order']), false)]));
    }

    public function rate(Request $request, string $ticket): JsonResponse
    {
        $this->authorize($request);
        $found = $this->find($ticket);
        $validated = $request->validate(['rating' => ['required', 'integer', 'min:1', 'max:5'], 'comment' => ['nullable', 'string', 'max:1000']]);

        return $this->orRefused(fn () => response()->json(['data' => $this->presenter->detail(
            $this->desk->rate($found, (int) $validated['rating'], $validated['comment'] ?? null)->load(['messages', 'order']), false,
        )]));
    }

    /** @return Builder<SupportTicket> */
    private function tickets(): Builder
    {
        return SupportTicket::query()->where('desk', SupportDesk::Platform->value);
    }

    private function find(string $publicId): SupportTicket
    {
        return $this->tickets()->where('public_id', $publicId)->with(['messages', 'order'])->firstOrFail();
    }

    private function authorize(Request $request): void
    {
        abort_unless($this->policy->platform($request->user()), 403);
    }
}
