<?php

namespace App\Http\Requests\Admin;

use App\Models\Tenant\TicketType;
use App\Support\TenantTime;
use Illuminate\Foundation\Http\FormRequest;

class StoreTicketTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', TicketType::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            // Integer minor units (e.g. 5000 = RM 50.00). `integer` rejects 50.5 and "50.00" alike.
            'price_minor' => ['required', 'integer', 'min:0', 'max:9999999999'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'max_per_order' => ['nullable', 'integer', 'min:1', 'max:'.config('ticketing.max_per_order_cap')],
            'sales_start_at' => ['nullable', 'date'],
            'sales_end_at' => ['nullable', 'date'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /** @return array<string, mixed> */
    public function ticketTypeAttributes(): array
    {
        return self::convertDates($this->validated(), ['name', 'description', 'price_minor', 'quantity', 'max_per_order', 'sales_start_at', 'sales_end_at', 'sort_order']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    public static function convertDates(array $data, array $keys): array
    {
        $out = array_intersect_key($data, array_flip($keys));

        foreach (['sales_start_at', 'sales_end_at'] as $field) {
            if (array_key_exists($field, $out) && $out[$field] !== null) {
                $out[$field] = TenantTime::parse($out[$field]);
            }
        }

        return $out;
    }
}
