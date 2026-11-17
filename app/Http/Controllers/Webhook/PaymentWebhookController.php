<?php

namespace App\Http\Controllers\Webhook;

use App\Actions\Payments\ProcessPaymentWebhook;
use App\Http\Controllers\Controller;
use App\Http\Requests\Webhook\PaymentWebhookRequest;
use Illuminate\Http\JsonResponse;

class PaymentWebhookController extends Controller
{
    /**
     * Always answers 200 for a notification we understood (even a duplicate or an unknown order), so the provider
     * stops retrying. 401 = bad signature, 422 = malformed body or an amount that does not match the order.
     */
    public function handle(PaymentWebhookRequest $request, ProcessPaymentWebhook $process): JsonResponse
    {
        $result = $process->execute($request->payload());

        if ($result->httpStatus >= 400) {
            return response()->json(['error' => $result->outcome, 'message' => 'The payment does not match the order.', 'order' => $result->orderReference], $result->httpStatus);
        }

        return response()->json(['status' => $result->outcome, 'order' => $result->orderReference]);
    }
}
