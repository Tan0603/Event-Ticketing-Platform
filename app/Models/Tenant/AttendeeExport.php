<?php

namespace App\Models\Tenant;

use App\Enums\ExportStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One queued CSV export of an event's attendees. Created `pending` by RequestAttendeeExport and moved forward
 * only by GenerateAttendeeExport (pending -> processing -> ready | failed).
 *
 * @property int $id
 * @property int $event_id
 * @property int|null $requested_by
 * @property ExportStatus $status
 * @property string|null $path
 * @property int|null $row_count
 * @property string|null $error
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface $created_at
 * @property-read Event $event
 * @property-read User|null $requester
 */
class AttendeeExport extends Model
{
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ExportStatus::class,
            'row_count' => 'integer',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
