<?php

namespace App\Actions\Orders;

use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Jobs\SendOrderEmailJob;
use App\Models\Tenant\Order;
use Illuminate\Support\Facades\DB;

class QueueOrderEmail
{
    /**
     * Claim the right to send one e-mail, then queue it.
     *
     * The claim is an INSERT into order_notifications with UNIQUE (order_id, type): of any number of racing
     * callers (duplicate webhook, retried job, scheduler overlap) exactly one inserts the row and queues the job.
     * The job is dispatched `afterCommit`, so it never runs before the order data it needs is visible.
     *
     * @return bool true when this call claimed the e-mail, false when someone already had
     */
    public function execute(Order $order, NotificationType $type): bool
    {
        $claimed = DB::table('order_notifications')->insertOrIgnore([
            'order_id' => $order->id,
            'type' => $type->value,
            'status' => NotificationStatus::Pending->value,
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($claimed === 0) {
            return false;
        }

        SendOrderEmailJob::dispatch((string) tenant('id'), $order->id, $type)->afterCommit();

        return true;
    }
}
