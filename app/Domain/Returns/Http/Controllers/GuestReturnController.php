<?php

declare(strict_types=1);

namespace App\Domain\Returns\Http\Controllers;

use App\Domain\Orders\Models\Order;
use App\Domain\Returns\Models\ReturnRequest;
use App\Domain\Returns\Services\GuestReturnLinks;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module 09 §8–9, §45 (Phase B34): returns for someone without an
 * account. They ask for a link with the order number and email
 * (`lookup`, which always answers the same); the link's token, sent in
 * the `X-Return-Token` header, then opens that one order's returns in
 * this store. See GuestReturnLinks for why the mailbox is the proof.
 *
 * The token is a header, not part of the path, so it stays out of
 * access logs of the API.
 */
final class GuestReturnController
{
    use ServesOwnReturns;

    public function __construct(private readonly GuestReturnLinks $links) {}

    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_number' => ['required', 'string', 'max:40'],
            'email' => ['required', 'string', 'email', 'max:255'],
            // Honeypot: people never see this field; form-filling bots do.
            'website' => ['nullable', 'string', 'max:0'],
        ]);

        $this->links->request($this->currentStore($request), $data['order_number'], $data['email']);

        return response()->json(['data' => ['sent' => true], 'message' => 'If the order number and email belong together, we have sent a link to that email address.'], 202);
    }

    public function returnable(Request $request): JsonResponse
    {
        $order = $this->order($request);

        return $order === null ? $this->linkInvalid() : $this->returnableResponse($request, $order, ReturnRequest::query()->where('order_id', $order->id));
    }

    public function store(Request $request): JsonResponse
    {
        $order = $this->order($request);

        return $order === null ? $this->linkInvalid() : $this->createResponse($request, $order, null, "guest:{$order->id}:");
    }

    public function cancel(Request $request, string $returnPublicId): JsonResponse
    {
        $order = $this->order($request);

        return $order === null ? $this->linkInvalid() : $this->cancelResponse($request, $this->own($order, $returnPublicId), null);
    }

    public function shipped(Request $request, string $returnPublicId): JsonResponse
    {
        $order = $this->order($request);

        return $order === null ? $this->linkInvalid() : $this->shippedResponse($request, $this->own($order, $returnPublicId), null);
    }

    public function addPhoto(Request $request, string $returnPublicId): JsonResponse
    {
        $order = $this->order($request);

        return $order === null ? $this->linkInvalid() : $this->addPhotoResponse($request, $this->own($order, $returnPublicId), 'guest');
    }

    public function photo(Request $request, string $returnPublicId, string $photo): Response
    {
        $order = $this->order($request);
        abort_if($order === null, 404);

        return $this->photoResponse($this->own($order, $returnPublicId), $photo);
    }

    private function order(Request $request): ?Order
    {
        return $this->links->order($this->currentStore($request), $request->header('X-Return-Token'));
    }

    /** A return of the order the link opens; any other is not found. */
    private function own(Order $order, string $publicId): ?ReturnRequest
    {
        return ReturnRequest::query()->where('public_id', $publicId)->where('order_id', $order->id)->first();
    }

    private function currentStore(Request $request): Store
    {
        return $request->attributes->get('storefront.store');
    }

    private function linkInvalid(): JsonResponse
    {
        return response()->json(['message' => 'This link is not valid any more. Ask for a new one with your order number and email.', 'code' => 'return_link_invalid'], 404);
    }
}
