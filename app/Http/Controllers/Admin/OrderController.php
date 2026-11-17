<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Orders\ListOrders;
use App\Actions\Orders\RefundOrder;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListOrdersRequest;
use App\Http\Requests\Admin\RefundOrderRequest;
use App\Http\Resources\Admin\OrderResource;
use App\Models\Tenant\Order;
use App\Models\Tenant\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    public function index(ListOrdersRequest $request, ListOrders $list): AnonymousResourceCollection
    {
        return OrderResource::collection($list->execute($request->validated()));
    }

    public function show(Request $request, Order $order): OrderResource
    {
        $this->authorize('view', $order);

        return new OrderResource($order->load(['event', 'items', 'tickets', 'refund']));
    }

    public function refund(RefundOrderRequest $request, Order $order, RefundOrder $refund): OrderResource
    {
        $this->authorize('refund', $order);
        /** @var User $admin */
        $admin = $request->user();

        $refunded = $refund->execute($order->id, $admin->id, $request->validated('reason'));

        return new OrderResource($refunded->load(['tickets']));
    }
}
