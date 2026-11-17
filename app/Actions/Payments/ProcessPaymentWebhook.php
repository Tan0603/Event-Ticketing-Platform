<?php

namespace App\Actions\Payments;

use App\Actions\Orders\ReleaseOrder;
use App\Enums\OrderStatus;
use App\Models\Tenant\Event;
use App\Models\Tenant\Order;
use Illuminate\Support\Facades\DB;

class ProcessPaymentWebhook
{
    public const SUCCEEDED = 'payment.succeeded';

    public const FAILED = 'payment.failed';

    public function __construct(
        private readonly MarkOrderPaid $markPaid,
        private readonly ReleaseOrder $release,
    ) {}

    /**
     * Apply one payment notification exactly once.
     *
     * The webhook_events row (UNIQUE event_id) is inserted first, in the same transaction as the effect: a
     * duplicate (the provider retried, or sent twice at the same moment) finds the row and does nothing, and if
     * anything fails the row is rolled back with the effect so the provider's retry is processed normally.
     *
     * @param  array<string, mixed>  $payload  the already verified and validated notification
     */
    public function execute(array $payload): WebhookResult
    {
        return DB::transaction(function () use ($payload): WebhookResult {
            $inserted = DB::table('webhook_events')->insertOrIgnore([
                'event_id' => $payload['id'],
                'type' => $payload['type'],
                'outcome' => 'received',
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 0) {
                return new WebhookResult('duplicate');
            }

            $result = $this->apply($payload);

            DB::table('webhook_events')->where('event_id', $payload['id'])->update([
                'outcome' => $result->outcome,
                'order_id' => $result->orderId,
                'updated_at' => now(),
            ]);

            return $result;
        });
    }

    /** @param array<string, mixed> $payload */
    private function apply(array $payload): WebhookResult
    {
        $reference = (string) $payload['data']['order_reference'];

        $known = Order::query()->where('reference', $reference)->first(['id', 'event_id']);
        if ($known === null) {
            return new WebhookResult('unknown_order', $reference);
        }

        // Lock order: event (shared) -> order -> ticket types (inside the actions below).
        Event::query()->sharedLock()->findOrFail($known->event_id);
        /** @var Order $order */
        $order = Order::query()->lockForUpdate()->with('items')->findOrFail($known->id);

        $outcome = $payload['type'] === self::SUCCEEDED
            ? $this->paymentSucceeded($order, $payload['data'])
            : $this->paymentFailed($order);

        return new WebhookResult($outcome->outcome, $reference, $outcome->httpStatus, $order->id);
    }

    /** @param array<string, mixed> $data */
    private function paymentSucceeded(Order $order, array $data): WebhookResult
    {
        // The amount comes from the provider, the truth from our snapshot: never mark an order paid on a mismatch.
        if ((int) $data['amount_minor'] !== $order->total_minor || (isset($data['currency']) && strtoupper((string) $data['currency']) !== $order->currency)) {
            return new WebhookResult('amount_mismatch', null, 422);
        }

        return match ($order->status) {
            // Paid after expires_at but before the expiry job ran is still just a pending order: a normal payment.
            OrderStatus::Pending => $this->pay($order),
            OrderStatus::Expired => $this->payLate($order),
            OrderStatus::Paid => new WebhookResult('already_paid'),
            OrderStatus::Failed, OrderStatus::Refunded => new WebhookResult('ignored'),
        };
    }

    private function paymentFailed(Order $order): WebhookResult
    {
        if ($order->status !== OrderStatus::Pending) {
            return new WebhookResult('ignored'); // a late failure never undoes a paid, expired or refunded order
        }

        $this->release->execute($order, OrderStatus::Failed);

        return new WebhookResult('failed');
    }

    private function pay(Order $order): WebhookResult
    {
        $this->markPaid->execute($order);

        return new WebhookResult('paid');
    }

    private function payLate(Order $order): WebhookResult
    {
        // All or nothing: if one line is gone, the lines already sold are given back.
        $paid = $this->markPaid->executeLate($order);

        return new WebhookResult($paid ? 'paid_late' : 'late_payment_no_stock');
    }
}
