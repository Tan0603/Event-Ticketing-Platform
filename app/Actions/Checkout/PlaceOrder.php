<?php

namespace App\Actions\Checkout;

use App\Domain\Inventory\TicketStock;
use App\Domain\Money\FeeCalculator;
use App\Domain\Orders\OrderStateMachine;
use App\Enums\EventStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Central\Tenant;
use App\Models\Tenant\Event;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\Tenant\TicketType;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlaceOrder
{
    /**
     * Reserve the tickets and create a pending order, atomically: either every line is held or nothing is.
     *
     * Lock order (CLAUDE.md): event -> order -> ticket types in ascending id. The event row is locked FOR SHARE,
     * so any number of checkouts run in parallel, but cancelling the event (FOR UPDATE) waits for them.
     * Stock is decided by TicketStock::hold(), one conditional UPDATE per line, never by a PHP check.
     */
    public function execute(CheckoutData $data): PlacedOrder
    {
        if (($replayed = $this->replay($data)) !== null) {
            return new PlacedOrder($replayed, true);
        }

        /** @var Event $event */
        $event = Event::query()->where('slug', $data->eventSlug)->where('status', EventStatus::Published)->firstOrFail();

        try {
            // Retries on a deadlock / serialization failure; the unique violation below is not retried.
            $order = DB::transaction(fn (): Order => $this->place($event->id, $data), 3);
        } catch (UniqueConstraintViolationException $e) {
            // Two requests with the same Idempotency-Key raced: the loser's holds were rolled back, hand it the winner's order.
            $winner = $this->replay($data);

            if ($winner === null) {
                throw $e;
            }

            return new PlacedOrder($winner, true);
        }

        return new PlacedOrder($order, false);
    }

    private function place(int $eventId, CheckoutData $data): Order
    {
        /** @var Event $event */
        $event = Event::query()->sharedLock()->findOrFail($eventId);

        if ($event->status !== EventStatus::Published || $event->ends_at->lte(now())) {
            throw new BusinessRuleException('event_not_on_sale', 'This event is not on sale.');
        }

        /** @var Tenant $tenant */
        $tenant = tenant();
        $now = now();

        $order = OrderStateMachine::begin([
            'reference' => 'ORD-'.Str::upper(Str::random(12)),
            'event_id' => $event->id,
            'buyer_name' => $data->buyerName,
            'buyer_email' => strtolower($data->buyerEmail),
            'currency' => $tenant->currency,
            'subtotal_minor' => 0,
            'fee_minor' => 0,
            'total_minor' => 0,
            'fee_percent_bps' => $tenant->fee_percent_bps,
            'fee_fixed_minor' => $tenant->fee_fixed_minor,
            'expires_at' => $now->copy()->addMinutes((int) config('ticketing.hold_minutes')),
            'idempotency_key' => $data->idempotencyKey,
            'request_fingerprint' => $data->idempotencyKey === null ? null : $data->fingerprint(),
        ]);

        $items = $data->items;
        ksort($items); // ascending ticket type id: the global lock order

        $lines = [];
        $subtotal = 0;
        foreach ($items as $ticketTypeId => $quantity) {
            $held = TicketStock::hold($ticketTypeId, $event->id, $quantity, $now)
                ?? $this->explainFailure($ticketTypeId, $event->id, $quantity, $now);

            $lineTotal = $held['price_minor'] * $quantity;
            $subtotal += $lineTotal;
            $lines[] = [
                'order_id' => $order->id,
                'ticket_type_id' => $held['id'],
                'ticket_type_name' => $held['name'],
                'unit_price_minor' => $held['price_minor'],
                'quantity' => $quantity,
                'line_total_minor' => $lineTotal,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        OrderItem::query()->insert($lines);

        $totals = FeeCalculator::totals($subtotal, $order->fee_percent_bps, $order->fee_fixed_minor);
        $order->forceFill([
            'subtotal_minor' => $totals->subtotalMinor,
            'fee_minor' => $totals->feeMinor,
            'total_minor' => $totals->totalMinor,
        ])->save();

        $order->load('items')->setRelation('event', $event);

        return $order;
    }

    /**
     * The hold failed. Work out why (read-only, only to give a precise error; the decision was already made by
     * the conditional UPDATE) and abort the transaction, which releases every earlier hold of this checkout.
     */
    private function explainFailure(int $ticketTypeId, int $eventId, int $quantity, CarbonInterface $now): never
    {
        /** @var TicketType|null $tier */
        $tier = TicketType::query()->with('event:id,ends_at')->find($ticketTypeId);

        if ($tier === null || $tier->event_id !== $eventId) {
            throw ValidationException::withMessages(['items' => ["Ticket type {$ticketTypeId} is not part of this event."]]);
        }
        if ($quantity > $tier->max_per_order) {
            throw ValidationException::withMessages(['items' => ["You can buy at most {$tier->max_per_order} \"{$tier->name}\" tickets per order."]]);
        }
        if ($tier->sales_start_at !== null && $now->lt($tier->sales_start_at)) {
            throw new BusinessRuleException('sales_not_started', "Sales for \"{$tier->name}\" have not started yet.");
        }
        if ($now->gte($tier->effectiveSalesEnd())) {
            throw new BusinessRuleException('sales_ended', "Sales for \"{$tier->name}\" have ended.");
        }

        $left = $tier->available();

        throw new BusinessRuleException(
            'insufficient_stock',
            $left === 0 ? "\"{$tier->name}\" is sold out." : "Only {$left} \"{$tier->name}\" tickets are left.",
        );
    }

    /** @phpstan-impure reads the database, so two calls can differ (a concurrent request may have inserted the order) */
    private function replay(CheckoutData $data): ?Order
    {
        if ($data->idempotencyKey === null) {
            return null;
        }

        $order = Order::query()->where('idempotency_key', $data->idempotencyKey)->with(['items', 'event'])->first();

        if ($order === null) {
            return null;
        }

        if ($order->request_fingerprint !== $data->fingerprint()) {
            throw new BusinessRuleException('idempotency_key_reused', 'This Idempotency-Key was already used for a different request.', 422);
        }

        return $order;
    }
}
