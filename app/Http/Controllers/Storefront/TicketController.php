<?php

namespace App\Http\Controllers\Storefront;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Storefront\OrderTicketsResource;
use App\Models\Tenant\Order;
use App\Models\Tenant\Ticket;
use App\Support\TicketQr;
use Illuminate\Http\Response;

/** The links in the buyer's e-mail. Both routes are signed and addressed by tenant path (a link carries no header). */
class TicketController extends Controller
{
    public function show(string $reference): OrderTicketsResource
    {
        $order = Order::query()
            ->where('reference', $reference)
            ->whereIn('status', [OrderStatus::Paid, OrderStatus::Refunded])
            ->with(['event', 'items', 'tickets.ticketType:id,name'])
            ->firstOrFail();

        return new OrderTicketsResource($order);
    }

    public function qr(string $code): Response
    {
        $ticket = Ticket::query()->where('code', $code)->firstOrFail();

        return response(TicketQr::png($ticket->code), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
