<?php

namespace App\Models\Tenant;

use App\Enums\SalesStatus;
use Carbon\CarbonInterface;
use Database\Factories\TicketTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `sold` and `held` are never assigned through Eloquent: only atomic conditional UPDATEs change them.
 *
 * @property int $id
 * @property int $event_id
 * @property string $name
 * @property string|null $description
 * @property int $price_minor
 * @property int $quantity
 * @property int $sold
 * @property int $held
 * @property int $max_per_order
 * @property CarbonInterface|null $sales_start_at
 * @property CarbonInterface|null $sales_end_at
 * @property int $sort_order
 * @property-read Event $event
 */
class TicketType extends Model
{
    /** @use HasFactory<TicketTypeFactory> */
    use HasFactory;

    /** Mirror the column defaults so a freshly created model reports them without a refresh. */
    protected $attributes = ['sold' => 0, 'held' => 0];

    protected $fillable = [
        'name', 'description', 'price_minor', 'quantity', 'max_per_order',
        'sales_start_at', 'sales_end_at', 'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'quantity' => 'integer',
            'sold' => 'integer',
            'held' => 'integer',
            'max_per_order' => 'integer',
            'sort_order' => 'integer',
            'sales_start_at' => 'immutable_datetime',
            'sales_end_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function available(): int
    {
        return max(0, $this->quantity - $this->sold - $this->held);
    }

    /** Sales stop at the tier's own end, or when the event ends if none is set. Requires `event` loaded. */
    public function effectiveSalesEnd(): CarbonInterface
    {
        return $this->sales_end_at ?? $this->event->ends_at;
    }

    public function salesStatus(?CarbonInterface $now = null): SalesStatus
    {
        return SalesStatus::resolve(
            $this->event->status,
            $this->sales_start_at,
            $this->effectiveSalesEnd(),
            $this->available(),
            $now ?? now(),
        );
    }

    protected static function newFactory(): TicketTypeFactory
    {
        return TicketTypeFactory::new();
    }
}
