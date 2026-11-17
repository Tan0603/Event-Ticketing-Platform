<?php

namespace App\Http\Requests\Admin;

use App\Support\TenantTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SalesReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('access-admin') ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            [$from, $to] = $this->range();
            if ($from->diffInDays($to) >= (int) config('ticketing.reports.max_days')) {
                $validator->errors()->add('to', 'The range cannot be longer than '.config('ticketing.reports.max_days').' days.');
            }
        }];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} defaults to the last N days up to today (tenant time) */
    public function range(): array
    {
        $today = CarbonImmutable::now(TenantTime::timezone())->startOfDay();
        $to = $this->filled('to') ? CarbonImmutable::parse($this->string('to')->toString()) : $today;
        $from = $this->filled('from') ? CarbonImmutable::parse($this->string('from')->toString()) : $to->subDays((int) config('ticketing.reports.default_days') - 1);

        return [$from->startOfDay(), $to->startOfDay()];
    }
}
