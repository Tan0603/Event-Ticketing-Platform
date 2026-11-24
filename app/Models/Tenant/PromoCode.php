<?php

namespace App\Models\Tenant;

use App\Enums\PromoDiscountType;
use Carbon\CarbonInterface;
use Database\Factories\PromoCodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `used` is never assigned through Eloquent: only the atomic conditional UPDATEs of PromoRedemption change it.
 *
 * @property int $id
 * @property string $code
 * @property PromoDiscountType $discount_type
 * @property int|null $percent_bps
 * @property int|null $amount_minor
 * @property int|null $event_id
 * @property int|null $max_uses
 * @property int $used
 * @property CarbonInterface|null $starts_at
 * @property CarbonInterface|null $ends_at
 * @property bool $is_active
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class PromoCode extends Model
{
    /** @use HasFactory<PromoCodeFactory> */
    use HasFactory;

    protected $attributes = ['used' => 0, 'is_active' => true];

    protected $fillable = [
        'code', 'discount_type', 'percent_bps', 'amount_minor', 'event_id', 'max_uses', 'starts_at', 'ends_at', 'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'discount_type' => PromoDiscountType::class,
            'percent_bps' => 'integer',
            'amount_minor' => 'integer',
            'event_id' => 'integer',
            'max_uses' => 'integer',
            'used' => 'integer',
            'is_active' => 'boolean',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function remaining(): ?int
    {
        return $this->max_uses === null ? null : max(0, $this->max_uses - $this->used);
    }

    public static function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    protected static function newFactory(): PromoCodeFactory
    {
        return PromoCodeFactory::new();
    }
}
