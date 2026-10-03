<?php

declare(strict_types=1);

namespace App\Domain\Returns\Http\Controllers;

use App\Domain\Orders\Models\Customer;
use App\Domain\Orders\Models\Order;
use App\Domain\Returns\Exceptions\ReturnActionRefusedException;
use App\Domain\Returns\Http\Resources\ReturnResource;
use App\Domain\Returns\Models\ReturnRequest;
use App\Domain\Returns\Models\ReturnStatus;
use App\Domain\Returns\Services\ReturnEligibility;
use App\Domain\Returns\Services\ReturnPhotoService;
use App\Domain\Returns\Services\ReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What a person may do with the returns of their OWN order, whether
 * they are a signed-in customer (CustomerReturnController) or a guest
 * holding the emailed link (GuestReturnController). The controller
 * proves whose order it is and hands the order (and a query limited to
 * that person's returns) to these methods; nothing here trusts an id
 * from the request on its own.
 *
 * The view is the customer view of ReturnResource: no staff names,
 * warehouse or inspection notes.
 */
trait ServesOwnReturns
{
    private const OWN_WITH = ['items.orderItem', 'replacementOrder', 'photos'];

    /**
     * @param \Illuminate\Database\Eloquent\Builder<ReturnRequest> $returns limited to this person's returns of the order
     */
    private function returnableResponse(Request $request, Order $order, $returns): JsonResponse
    {
        $eligibility = app(ReturnEligibility::class);
        $lines = collect($eligibility->lines($order))->map(fn (array $line) => [
            ...$line,
            // For the person asking, "returnable" also means inside the return period.
            'returnable' => $line['window_open'] ? $line['returnable'] : 0,
        ])->values();

        return response()->json(['data' => [
            'enabled' => $eligibility->customersMayRequest(),
            'blocked' => $eligibility->orderBlock($order),
            'window_days' => $eligibility->windowDays(),
            'order' => ['id' => $order->public_id, 'order_number' => $order->order_number, 'currency' => $order->currency],
            'lines' => $lines,
            'max_photos' => (int) config('returns.photos.max_per_return'),
            'returns' => $returns->with(self::OWN_WITH)->orderByDesc('id')->get()
                ->map(fn (ReturnRequest $return) => (new ReturnResource($return))->resolve($request))->values(),
        ]]);
    }

    private function createResponse(Request $request, Order $order, ?Customer $customer, string $keyPrefix): JsonResponse
    {
        $rules = ReturnController::requestRules();
        // The person chooses what they would like; how the parcel travels is agreed when the store approves.
        unset($rules['return_method']);
        $data = $request->validate($rules);

        try {
            $return = app(ReturnService::class)->request($order, $data['items'], $data, $customer, $keyPrefix.$data['idempotency_key']);
        } catch (ReturnActionRefusedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }

        return $this->ownReturn($request, $return, 201);
    }

    /** Withdraws the request, until the store has received the goods. */
    private function cancelResponse(Request $request, ?ReturnRequest $return, ?Customer $customer): JsonResponse
    {
        return $this->actResponse($request, $return, fn (ReturnRequest $r) => app(ReturnService::class)->cancel($r, $customer));
    }

    /** The parcel is on its way (Module 13 §70), with the carrier and tracking number if there are any. */
    private function shippedResponse(Request $request, ?ReturnRequest $return, ?Customer $customer): JsonResponse
    {
        $data = $request->validate(['carrier' => ['nullable', 'string', 'max:64'], 'tracking_number' => ['nullable', 'string', 'max:128']]);

        return $this->actResponse($request, $return, function (ReturnRequest $r) use ($data, $customer) {
            if ($r->status !== ReturnStatus::Approved) {
                throw new ReturnActionRefusedException('The store has to approve the return before you send the items back.', 'not_approved');
            }

            return app(ReturnService::class)->markInTransit($r, $data, $customer);
        });
    }

    /** @param 'customer'|'guest' $uploadedBy */
    private function addPhotoResponse(Request $request, ?ReturnRequest $return, string $uploadedBy): JsonResponse
    {
        $data = $request->validate(ReturnController::photoRules());

        return $this->actResponse($request, $return, function (ReturnRequest $r) use ($data, $uploadedBy) {
            app(ReturnPhotoService::class)->add($r, $data['photo'], $uploadedBy);

            return $r->unsetRelation('photos');
        }, 201);
    }

    private function photoResponse(?ReturnRequest $return, string $photoPublicId): Response
    {
        abort_if($return === null, 404);

        // Through the person's own return: any other photo is not found.
        return app(ReturnPhotoService::class)->response($return->photos()->where('public_id', $photoPublicId)->firstOrFail());
    }

    private function actResponse(Request $request, ?ReturnRequest $return, callable $action, int $status = 200): JsonResponse
    {
        if ($return === null) {
            return $this->returnNotFound();
        }

        try {
            $changed = $action($return);
        } catch (ReturnActionRefusedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }

        return $this->ownReturn($request, $changed, $status);
    }

    private function ownReturn(Request $request, ReturnRequest $return, int $status = 200): JsonResponse
    {
        return response()->json(['data' => (new ReturnResource($return->load(self::OWN_WITH)))->resolve($request)], $status);
    }

    private function returnNotFound(): JsonResponse
    {
        return response()->json(['message' => 'Return not found.', 'code' => 'return_not_found'], 404);
    }
}
