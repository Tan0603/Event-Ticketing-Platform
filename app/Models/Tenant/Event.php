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
 * @property string $venue
 * @property CarbonInterface $starts_at
 * @property CarbonInterface $ends_at
 * @property EventStatus $status
 * @property CarbonInterface|null $published_at
 * @property CarbonInterface|null $cancelled_at
 */
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    protected $fillable = ['title', 'slug', 'description', 'venue', 'starts_at', 'ends_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => EventStatus::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<TicketType, $this> */
    public function ticketTypes(): HasMany
    {
        return $this->hasMany(TicketType::class)->chaperone()->orderBy('sort_order')->orderBy('id');
    }

    public function timing(?CarbonInterface $now = null): EventTiming
    {
        $now ??= now();

        return match (true) {
            $now->gte($this->ends_at) => EventTiming::Ended,
            $now->gte($this->starts_at) => EventTiming::Ongoing,
            default => EventTiming::Upcoming,
        };
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
