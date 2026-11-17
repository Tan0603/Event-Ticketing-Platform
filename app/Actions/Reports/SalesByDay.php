<?php

namespace App\Actions\Reports;

use App\Support\StorefrontCache;
use App\Support\TenantTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SalesByDay
{
    /**
     * Sales and refunds per calendar day (in the tenant's timezone), both ends included.
     *
     * An order is a sale on the day it was paid; a refund is a refund on the day it happened (decision 6), so an
     * order paid on Monday and refunded on Wednesday adds to Monday's gross and Wednesday's refunds. One UNION ALL
     * query does the grouping in SQL; PHP only fills days without activity so the series has no holes.
     *
     * @return array{from: string, to: string, days: array<int, array<string, mixed>>, totals: array<string, int>}
     */
    public function execute(string $from, string $to): array
    {
        $key = 'sales:'.$from.':'.$to;

        return StorefrontCache::remember($key, (int) config('ticketing.cache.dashboard_ttl'), fn (): array => $this->compute($from, $to));
    }

    /** @return array{from: string, to: string, days: array<int, array<string, mixed>>, totals: array<string, int>} */
    private function compute(string $from, string $to): array
    {
        $tz = TenantTime::timezone();
        $start = CarbonImmutable::parse($from, $tz)->startOfDay();
        $end = CarbonImmutable::parse($to, $tz)->addDay()->startOfDay(); // exclusive
        $lo = $start->utc()->toIso8601String();
        $hi = $end->utc()->toIso8601String();

        $rows = DB::select(
            'SELECT day,
                    SUM(orders_paid) AS orders_paid, SUM(gross_minor) AS gross_minor, SUM(fees_minor) AS fees_minor,
                    SUM(refunds) AS refunds, SUM(refunded_minor) AS refunded_minor
               FROM (
                    SELECT (paid_at AT TIME ZONE ?)::date AS day, 1 AS orders_paid, total_minor AS gross_minor, fee_minor AS fees_minor,
                           0 AS refunds, 0 AS refunded_minor
                      FROM orders WHERE paid_at >= ? AND paid_at < ?
                    UNION ALL
                    SELECT (created_at AT TIME ZONE ?)::date, 0, 0, 0, 1, amount_minor
                      FROM refunds WHERE created_at >= ? AND created_at < ?
               ) activity
              GROUP BY day ORDER BY day',
            [$tz, $lo, $hi, $tz, $lo, $hi],
        );
        $byDay = collect($rows)->keyBy('day');

        $days = [];
        $totals = ['orders_paid' => 0, 'gross_minor' => 0, 'fees_minor' => 0, 'refunds' => 0, 'refunded_minor' => 0, 'net_minor' => 0];

        for ($day = $start; $day < $end; $day = $day->addDay()) {
            $row = $byDay->get($day->toDateString());
            $entry = [
                'date' => $day->toDateString(),
                'orders_paid' => (int) ($row->orders_paid ?? 0),
                'gross_minor' => (int) ($row->gross_minor ?? 0),
                'fees_minor' => (int) ($row->fees_minor ?? 0),
                'refunds' => (int) ($row->refunds ?? 0),
                'refunded_minor' => (int) ($row->refunded_minor ?? 0),
            ];
            $entry['net_minor'] = $entry['gross_minor'] - $entry['refunded_minor'];

            foreach ($totals as $field => $_) {
                $totals[$field] += $entry[$field];
            }
            $days[] = $entry;
        }

        return ['from' => $start->toDateString(), 'to' => $end->subDay()->toDateString(), 'days' => $days, 'totals' => $totals];
    }
}
