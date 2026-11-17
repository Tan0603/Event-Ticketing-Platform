<?php

namespace App\Http\Requests\Webhook;

use App\Actions\Payments\ProcessPaymentWebhook;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validates the notification body. Authentication already happened in VerifyWebhookSignature. */
class PaymentWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $succeeded = $this->input('type') === ProcessPaymentWebhook::SUCCEEDED;

        return [
            'id' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in([ProcessPaymentWebhook::SUCCEEDED, ProcessPaymentWebhook::FAILED])],
            'data' => ['required', 'array'],
            'data.order_reference' => ['required', 'string', 'max:20'],
            'data.amount_minor' => [$succeeded ? 'required' : 'nullable', 'integer', 'min:0'],
            'data.currency' => ['nullable', 'string', 'size:3'],
        ];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return $validated;
    }
}
