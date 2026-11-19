<?php

namespace App\Models\Tenant;

use App\Enums\EventStatus;
use App\Enums\EventTiming;
use Carbon\CarbonInterface;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property string|null $description
 * @property string $venue_name
 * @property string|null $venue_address
 * @property CarbonInterface $starts_at
 * @property CarbonInterface $ends_at
 * @property EventStatus $status
 * @property CarbonInterface|null $published_at
 * @property CarbonInterface|null $cancelled_at
 * @property int|null $capacity shared by all ticket types; null = no event limit
 * @property int $reserved sold + held of all ticket types (maintained only while capacity is set)
 */
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    protected $fillable = ['title', 'slug', 'description', 'venue_name', 'venue_address', 'starts_at', 'ends_at', 'capacity'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => EventStatus::class,
            'capacity' => 'integer',
            'reserved' => 'integer',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<TicketType, $this> */
    public function ticketTypes(): HasMany
    {
        return $this->hasMany(TicketType::class)->chaperone()->with('unlocksAfter')->orderBy('sort_order')->orderBy('id');
    }

    /** Room left in the shared event capacity, or null when the event has no limit. */
    public function capacityRemaining(): ?int
    {
        return $this->capacity === null ? null : max(0, $this->capacity - $this->reserved);
    }

    public function timing(?CarbonInterface $now = null): EventTiming
    {
        return EventTiming::resolve($now ?? now(), $this->starts_at, $this->ends_at);
    }

    /**
     * Whether any ticket was sold or is on hold. Phase 6 widens this to "has any orders" once orders exist.
     */
    public function hasSales(): bool
    {
        return $this->ticketTypes()->whereRaw('sold + held > 0')->exists();
    }

    protected static function newFactory(): EventFactory
    {
        return EventFactory::new();
    }
}
