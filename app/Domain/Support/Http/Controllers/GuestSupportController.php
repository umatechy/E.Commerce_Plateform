<?php

declare(strict_types=1);

namespace App\Domain\Support\Http\Controllers;

use App\Domain\Orders\Models\Customer;
use App\Domain\Orders\Models\Order;
use App\Domain\Support\Models\SupportCategory;
use App\Domain\Support\Models\SupportDesk;
use App\Domain\Support\Models\SupportTicket;
use App\Domain\Support\Services\SupportDeskService;
use App\Domain\Support\Services\SupportNotifier;
use App\Domain\Support\Services\SupportPresenter;
use App\Domain\Support\Services\SupportRequester;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Module 34 — the storefront contact form, and a guest's view of their
 * own request through its private link. A signed-in customer using the
 * form gets an ordinary account ticket instead.
 */
final class GuestSupportController
{
    use HandlesSupportRequests;

    public function __construct(
        private readonly SupportDeskService $desk,
        private readonly SupportPresenter $presenter,
        private readonly SupportNotifier $notifier,
    ) {}

    public function contact(Request $request): JsonResponse
    {
        $validated = $request->validate([
            ...$this->openRules(SupportDesk::Store),
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:190'],
            'order_number' => ['nullable', 'string', 'max:40'],
            // Honeypot: people never see this field; form-filling bots do.
            'website' => ['nullable', 'string', 'max:0'],
        ]);

        /** @var Store $store */
        $store = $request->attributes->get('storefront.store');
        $customer = $request->user() instanceof Customer ? $request->user() : null;
        $requester = $customer !== null ? SupportRequester::customer($customer) : SupportRequester::guest(trim($validated['name']), mb_strtolower(trim($validated['email'])));

        [$ticket, $token] = $this->desk->open(SupportDesk::Store, $store->id, $requester, [
            'subject' => $validated['subject'],
            'category' => SupportCategory::from($validated['category']),
            'message' => $validated['message'],
            'order_id' => $this->orderFor($validated['order_number'] ?? null, $requester->email, $customer),
        ]);

        if ($token !== null) {
            $this->notifier->guestAcknowledgement($ticket, $token);
        }

        return response()->json(['data' => [
            'id' => $ticket->public_id,
            'number' => $ticket->number,
            // Returned once, to the person who just wrote the request, so
            // the page can open it; also sent by email.
            'access_token' => $token,
        ]], 201);
    }

    public function show(Request $request, string $ticket): JsonResponse
    {
        return response()->json(['data' => $this->presenter->detail($this->find($request, $ticket), false)]);
    }

    public function reply(Request $request, string $ticket): JsonResponse
    {
        $found = $this->find($request, $ticket);
        $validated = $request->validate($this->messageRules());

        return $this->orRefused(function () use ($found, $validated) {
            $this->desk->replyAsRequester($found, $found->requester_name, null, $validated['body']);

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

    /**
     * An order is linked only when its number AND contact email match, so
     * the form cannot be used to probe other people's orders. A mismatch
     * is silently ignored (the request is still received).
     */
    private function orderFor(?string $number, string $email, ?Customer $customer): ?int
    {
        if ($number === null || trim($number) === '') {
            return null;
        }

        $order = Order::query()->where('order_number', trim($number))->with('customer')->first();
        if ($order === null) {
            return null;
        }

        $matches = $customer !== null
            ? $order->customer_id === $customer->id
            : in_array($email, array_filter([mb_strtolower((string) $order->guest_email), mb_strtolower((string) $order->customer?->email)]), true);

        return $matches ? $order->id : null;
    }

    /**
     * A wrong token looks exactly like a missing ticket. The storefront
     * sends the token in the X-Support-Token header, so it stays out of
     * URLs and server logs (the email link carries it in the #fragment,
     * which browsers never send); a `token` field is accepted too.
     */
    private function find(Request $request, string $publicId): SupportTicket
    {
        $token = (string) ($request->header('X-Support-Token') ?: ($request->input('token') ?? ''));
        $ticket = SupportTicket::query()
            ->where('desk', SupportDesk::Store->value)
            ->where('requester_type', SupportTicket::REQUESTER_GUEST)
            ->where('public_id', $publicId)
            ->with(['messages', 'order'])
            ->first();

        abort_if($ticket === null || $token === '' || ! $this->desk->guestTokenMatches($ticket, $token), 404);

        return $ticket;
    }
}
