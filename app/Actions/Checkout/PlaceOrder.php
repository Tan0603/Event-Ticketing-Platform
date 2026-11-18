<?php

namespace App\Actions\Checkout;

use App\Actions\Payments\MarkOrderPaid;
use App\Domain\Inventory\EventLock;
use App\Domain\Inventory\TicketStock;
use App\Domain\Money\FeeCalculator;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Promo\PromoRedemption;
use App\Enums\EventStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Central\Tenant;
use App\Models\Tenant\Event;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\Tenant\PromoCode;
use App\Models\Tenant\TicketType;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlaceOrder
{
    public function __construct(private readonly MarkOrderPaid $markPaid) {}

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
        $event = EventLock::acquire($eventId);

        if ($event->status !== EventStatus::Published || $event->ends_at->lte(now())) {
            throw new BusinessRuleException('event_not_on_sale', 'This event is not on sale.');
        }

        /** @var Tenant $tenant */
        $tenant = tenant();
        $now = now();

        $order = OrderStateMachine::begin([
            'reference' => 'ORD-'.Str::upper(Str::random(12)),
            'payment_reference' => 'pay_'.Str::lower(Str::random(12)),
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

        // Cheap read-only check first, so an unknown code is refused before any stock is touched. The atomic
        // redemption below is the real decision; this only gives the common mistakes a precise error.
        $promo = $data->promoCode === null ? null : $this->findPromo($data->promoCode, $event, $now);

        $items = $data->items;
        ksort($items); // ascending ticket type id: the global lock order

        $lines = [];
        $priced = [];
        foreach ($items as $ticketTypeId => $quantity) {
            $held = TicketStock::hold($ticketTypeId, $event->id, $quantity, $now, $event->capacity !== null)
                ?? $this->explainFailure($ticketTypeId, $event->id, $quantity, $now);

            $unitFee = FeeCalculator::ticketFee($held['price_minor'], $order->fee_percent_bps, $order->fee_fixed_minor);
            $priced[] = [$held['price_minor'], $quantity];
            $lineTotal = $held['price_minor'] * $quantity;
            $lines[] = [
                'order_id' => $order->id,
                'ticket_type_id' => $held['id'],
                'ticket_type_name' => $held['name'],
                'unit_price_minor' => $held['price_minor'],
                'quantity' => $quantity,
                'line_total_minor' => $lineTotal,
                'unit_fee_minor' => $unitFee,
                'line_fee_minor' => $unitFee * $quantity,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        OrderItem::query()->insert($lines);

        // Lock order: the promo code row comes after the ticket types. One conditional UPDATE decides; the
        // discount is priced from the row version it returns, so an admin edit in between cannot skew it.
        $redeemed = null;
        if ($promo !== null) {
            $redeemed = PromoRedemption::redeem($promo->id, $event->id, $now)
                ?? throw $this->promoFailure(PromoCode::query()->find($promo->id), $event, $now);
        }

        $totals = FeeCalculator::totals($priced, $order->fee_percent_bps, $order->fee_fixed_minor, $redeemed['type'] ?? null, $redeemed['value'] ?? 0);
        $order->forceFill([
            'subtotal_minor' => $totals->subtotalMinor,
            'discount_minor' => $totals->discountMinor,
            'fee_minor' => $totals->feeMinor,
            'total_minor' => $totals->totalMinor,
            'promo_code_id' => $redeemed['id'] ?? null,
            'promo_code' => $redeemed['code'] ?? null,
        ])->save();

        $order->load('items')->setRelation('event', $event);

        // Nothing to pay: confirm straight away, through the same code as a real payment.
        if ($totals->totalMinor === 0) {
            $this->markPaid->execute($order);
        }

        return $order;
    }

    private function findPromo(string $code, Event $event, CarbonInterface $now): PromoCode
    {
        $promo = PromoCode::query()->where('code', $code)->first();

        throw_if(($error = $this->diagnosePromo($promo, $event, $now)) !== null, $error);

        /** @var PromoCode $promo */
        return $promo;
    }

    /** The atomic redemption failed although the pre-check passed: someone took the last use, or the code changed meanwhile. */
    private function promoFailure(?PromoCode $promo, Event $event, CarbonInterface $now): BusinessRuleException
    {
        return $this->diagnosePromo($promo, $event, $now) ?? $this->promoExhausted();
    }

    /** Why a code is not usable (read-only, for the message), or null when it looks usable. */
    private function diagnosePromo(?PromoCode $promo, Event $event, CarbonInterface $now): ?BusinessRuleException
    {
        if ($promo === null || ! $promo->is_active || ($promo->event_id !== null && $promo->event_id !== $event->id)) {
            return new BusinessRuleException('promo_code_invalid', 'This promo code is not valid for this event.', 422);
        }
        if ($promo->starts_at !== null && $now->lt($promo->starts_at)) {
            return new BusinessRuleException('promo_code_not_started', 'This promo code is not active yet.', 422);
        }
        if ($promo->ends_at !== null && $now->gte($promo->ends_at)) {
            return new BusinessRuleException('promo_code_expired', 'This promo code has expired.', 422);
        }
        if ($promo->max_uses !== null && $promo->used >= $promo->max_uses) {
            return $this->promoExhausted();
        }

        return null;
    }

    private function promoExhausted(): BusinessRuleException
    {
        return new BusinessRuleException('promo_code_exhausted', 'This promo code has been used up.', 409);
    }

    /**
     * The hold failed. Work out why (read-only, only to give a precise error; the decision was already made by
     * the conditional UPDATE) and abort the transaction, which releases every earlier hold of this checkout.
     */
    private function explainFailure(int $ticketTypeId, int $eventId, int $quantity, CarbonInterface $now): never
    {
        /** @var TicketType|null $tier */
        $tier = TicketType::query()->with(['event:id,ends_at,capacity,reserved', 'unlocksAfter'])->find($ticketTypeId);

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

        if ($tier->isLocked($now)) {
            throw new BusinessRuleException('tier_locked', "\"{$tier->name}\" opens when \"{$tier->unlocksAfter?->name}\" has sold out or its sales have ended.");
        }

        $eventLeft = $tier->event->capacityRemaining();
        if ($eventLeft !== null && $quantity > $eventLeft) {
            throw new BusinessRuleException(
                'event_sold_out',
                $eventLeft === 0 ? 'This event is sold out.' : "Only {$eventLeft} tickets are left for this event.",
            );
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
