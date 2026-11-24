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
 * @property int|null $unlocks_after_ticket_type_id
 * @property-read TicketType|null $unlocksAfter
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
        'sales_start_at', 'sales_end_at', 'sort_order', 'unlocks_after_ticket_type_id',
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
            'unlocks_after_ticket_type_id' => 'integer',
            'sales_start_at' => 'immutable_datetime',
            'sales_end_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<TicketType, $this> the tier that must be done before this one opens */
    public function unlocksAfter(): BelongsTo
    {
        return $this->belongsTo(self::class, 'unlocks_after_ticket_type_id');
    }

    /**
     * Whether this tier still waits for its predecessor (display only: the atomic hold decides). The predecessor
     * is done when its stock is gone or its sales window ended. Needs `unlocksAfter` and `event` loaded.
     */
    public function isLocked(CarbonInterface $now): bool
    {
        $predecessor = $this->unlocksAfter;

        if ($predecessor === null) {
            return false;
        }

        $stockGone = $predecessor->sold + $predecessor->held >= $predecessor->quantity;
        $windowEnded = $now->gte($predecessor->sales_end_at ?? $this->event->ends_at);

        return ! ($stockGone || $windowEnded);
    }

    public function available(): int
    {
        return max(0, $this->quantity - $this->sold - $this->held);
    }

    /** What a buyer could still get: the tier's own stock, capped by what is left of the event's shared capacity. */
    public function sellable(): int
    {
        $remaining = $this->event->capacityRemaining();

        return $remaining === null ? $this->available() : min($this->available(), $remaining);
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
            $this->sellable(),
            $now ??= now(),
            $this->isLocked($now),
        );
    }

    protected static function newFactory(): TicketTypeFactory
    {
        return TicketTypeFactory::new();
    }
}
