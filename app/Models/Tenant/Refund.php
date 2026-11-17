<?php

namespace App\Models\Tenant;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What an order's buyer got back. Created only by RefundOrder, together with the order's move to refunded.
 *
 * @property int $id
 * @property int $order_id
 * @property int $amount_minor
 * @property string $currency
 * @property string|null $reason
 * @property int|null $refunded_by
 * @property CarbonInterface $created_at
 */
class Refund extends Model
{
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
