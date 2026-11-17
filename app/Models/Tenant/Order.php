<?php

namespace App\Models\Tenant;

use App\Enums\OrderStatus;
use Carbon\CarbonInterface;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * `status` is written only by App\Domain\Orders\OrderStateMachine (it is not fillable, and saving a changed
 * status through the model is refused).
 *
 * @property int $id
 * @property string $reference
 * @property int $event_id
 * @property string $buyer_name
 * @property string $buyer_email
 * @property OrderStatus $status
 * @property string $currency
 * @property int $subtotal_minor
 * @property int $fee_minor
 * @property int $total_minor
 * @property int $fee_percent_bps
 * @property int $fee_fixed_minor
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $paid_at
 * @property CarbonInterface|null $failed_at
 * @property CarbonInterface|null $expired_at
 * @property CarbonInterface|null $refunded_at
 * @property string|null $idempotency_key
 * @property string|null $request_fingerprint
 * @property-read Event $event
 * @property-read Refund|null $refund
 * @property-read Collection<int, OrderItem> $items
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $fillable = ['buyer_name', 'buyer_email'];

    protected static function booted(): void
    {
        static::updating(function (Order $order): void {
            if ($order->isDirty('status')) {
                throw new LogicException('Order status can only change through OrderStateMachine::transition().');
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'subtotal_minor' => 'integer',
            'fee_minor' => 'integer',
            'total_minor' => 'integer',
            'fee_percent_bps' => 'integer',
            'fee_fixed_minor' => 'integer',
            'expires_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'expired_at' => 'immutable_datetime',
            'refunded_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class)->orderBy('id');
    }

    /** @return HasOne<Refund, $this> */
    public function refund(): HasOne
    {
        return $this->hasOne(Refund::class);
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->chaperone()->orderBy('id');
    }

    protected static function newFactory(): OrderFactory
    {
        return OrderFactory::new();
    }
}
