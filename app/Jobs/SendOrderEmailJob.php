<?php

namespace App\Jobs;

use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Jobs\Concerns\RunsInTenant;
use App\Mail\EventReminderMail;
use App\Mail\OrderConfirmationMail;
use App\Mail\OrderRefundedMail;
use App\Models\Tenant\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends one order e-mail. Takes ids, not models, and runs inside the tenant that owns the order.
 * The order_notifications row was claimed by QueueOrderEmail; this job only delivers it (and retries on failure).
 */
class SendOrderEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInTenant, SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly string $tenantId,
        public readonly int $orderId,
        public readonly NotificationType $type,
    ) {
        $this->onQueue('mail');
    }

    /** @return array<int, int> seconds between attempts */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(): void
    {
        $this->inTenant(function (): void {
            // Count the attempt; a notification that is already sent is never sent again (retry after a late failure).
            $open = DB::table('order_notifications')
                ->where('order_id', $this->orderId)->where('type', $this->type->value)
                ->where('status', '!=', NotificationStatus::Sent->value)
                ->update(['attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);

            if ($open === 0) {
                return;
            }

            $order = Order::query()->with(['items', 'event', 'refund'])->findOrFail($this->orderId);

            Mail::to($order->buyer_email, $order->buyer_name)->send($this->mailable($order));

            DB::table('order_notifications')
                ->where('order_id', $this->orderId)->where('type', $this->type->value)
                ->update(['status' => NotificationStatus::Sent->value, 'sent_at' => now(), 'last_error' => null, 'updated_at' => now()]);
        });
    }

    /** Called by the queue when every attempt failed. */
    public function failed(Throwable $e): void
    {
        $this->inTenantOnFailure(function () use ($e): void {
            DB::table('order_notifications')
                ->where('order_id', $this->orderId)->where('type', $this->type->value)
                ->where('status', '!=', NotificationStatus::Sent->value)
                ->update(['status' => NotificationStatus::Failed->value, 'last_error' => mb_substr($e->getMessage(), 0, 1000), 'updated_at' => now()]);
        });
    }

    private function mailable(Order $order): Mailable
    {
        return match ($this->type) {
            NotificationType::Confirmation => new OrderConfirmationMail($order),
            NotificationType::Reminder => new EventReminderMail($order),
            NotificationType::Refund => new OrderRefundedMail($order),
            // The remaining type is added with the feature that sends it.
            default => throw new \LogicException("No e-mail template for {$this->type->value}."),
        };
    }
}
