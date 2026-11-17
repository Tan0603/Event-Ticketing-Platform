<?php

namespace App\Http\Requests\Storefront;

use App\Actions\Checkout\CheckoutData;
use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // public endpoint
    }

    protected function prepareForValidation(): void
    {
        // The key travels in a header (Stripe-style); validate it like any other input.
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'buyer_name' => ['required', 'string', 'max:255'],
            'buyer_email' => ['required', 'string', 'email:rfc', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.ticket_type_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:'.config('ticketing.max_per_order_cap')],
            'idempotency_key' => ['nullable', 'string', 'min:8', 'max:100', 'regex:/^[A-Za-z0-9_\-:.]+$/'],
        ];
    }

    public function toData(string $eventSlug): CheckoutData
    {
        $items = [];
        foreach ($this->validated('items') as $item) {
            $items[(int) $item['ticket_type_id']] = (int) $item['quantity'];
        }

        return new CheckoutData(
            eventSlug: $eventSlug,
            buyerName: trim($this->validated('buyer_name')),
            buyerEmail: strtolower(trim($this->validated('buyer_email'))),
            items: $items,
            idempotencyKey: $this->validated('idempotency_key'),
        );
    }
}
