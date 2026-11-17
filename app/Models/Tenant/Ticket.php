<?php

namespace App\Models\Tenant;

use App\Enums\TicketStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One admission. Created only by IssueTickets; checked in only by the single conditional UPDATE in CheckInTicket.
 *
 * @property int $id
 * @property int $order_id
 * @property int $order_item_id
 * @property int $ticket_type_id
 * @property int $event_id
 * @property int $seq
 * @property string $code
 * @property TicketStatus $status
 * @property CarbonInterface|null $checked_in_at
 * @property int|null $checked_in_by
 * @property-read Order $order
 * @property-read TicketType $ticketType
 */
class Ticket extends Model
{
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'seq' => 'integer',
            'checked_in_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<TicketType, $this> */
    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
