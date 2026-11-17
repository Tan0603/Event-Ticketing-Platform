<?php

namespace App\Http\Controllers\Storefront;

use App\Actions\Checkout\PlaceOrder;
use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\CheckoutRequest;
use App\Http\Resources\Storefront\OrderResource;
use App\Models\Tenant\Order;
use Illuminate\Http\JsonResponse;

class CheckoutController extends Controller
{
    public function store(CheckoutRequest $request, string $slug, PlaceOrder $placeOrder): JsonResponse
    {
        $placed = $placeOrder->execute($request->toData($slug));

        return (new OrderResource($placed->order))
            ->response()
            ->setStatusCode($placed->replayed ? 200 : 201)
            ->header('Idempotent-Replayed', $placed->replayed ? 'true' : 'false');
    }

    /** The order reference (unguessable, 60 bits) is the buyer's capability to see their order. */
    public function show(string $reference): OrderResource
    {
        return new OrderResource(Order::query()->where('reference', $reference)->with(['items', 'event'])->firstOrFail());
    }
}
