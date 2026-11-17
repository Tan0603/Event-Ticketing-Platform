<?php

namespace App\Models\Central;

use App\Enums\TenantStatus;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * An event organizer. Lives in the central database; owns one tenant database.
 *
 * @property string $id
 * @property string $name
 * @property TenantStatus $status
 * @property string $currency
 * @property string $timezone
 * @property int $fee_percent_bps
 * @property int $fee_fixed_minor
 * @property string|null $webhook_secret
 * @property string|null $failure_reason
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase;

    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Real columns. Anything not listed here is packed into the `data` JSON column by stancl.
     *
     * @return array<int, string>
     */
    public static function getCustomColumns(): array
    {
        return [
            'id', 'name', 'status', 'currency', 'timezone', 'fee_percent_bps',
            'fee_fixed_minor', 'webhook_secret', 'failure_reason', 'data',
            'created_at', 'updated_at',
        ];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'fee_percent_bps' => 'integer',
            'fee_fixed_minor' => 'integer',
            'webhook_secret' => 'encrypted',
        ];
    }

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }
}
