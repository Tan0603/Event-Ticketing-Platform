<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A snapshot of what was bought: name and price are copied at purchase time, never recomputed.
 *
 * @property int $id
 * @property int $order_id
 * @property int $ticket_type_id
 * @property string $ticket_type_name
 * @property int $unit_price_minor
 * @property int $quantity
 * @property int $line_total_minor
 * @property int $unit_fee_minor
 * @property int $line_fee_minor
 */
class OrderItem extends Model
{
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['unit_price_minor' => 'integer', 'quantity' => 'integer', 'line_total_minor' => 'integer', 'unit_fee_minor' => 'integer', 'line_fee_minor' => 'integer'];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
