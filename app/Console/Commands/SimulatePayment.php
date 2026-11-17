<?php

namespace App\Console\Commands;

use App\Actions\Payments\ProcessPaymentWebhook;
use App\Models\Central\Tenant;
use App\Models\Tenant\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Plays the payment provider: builds a notification for an order, signs it with the tenant's webhook secret and
 * posts it to the real webhook endpoint, so the whole path (signature, validation, dedupe, state machine) runs.
 */
class SimulatePayment extends Command
{
    protected $signature = 'payments:simulate
        {tenant : Tenant id}
        {order_reference : Order reference, e.g. ORD-ABCDEF123456}
        {outcome : succeeded or failed}
        {--event-id= : Provider event id (reuse one to see duplicate handling)}
        {--amount= : Amount in minor units (default: the order total; set another to see the mismatch guard)}
        {--url=http://nginx : Base URL of the running API (from inside the app container)}';

    protected $description = 'Send a signed payment webhook for an order, like the payment provider would';

    public function handle(): int
    {
        $outcome = (string) $this->argument('outcome');
        if (! in_array($outcome, ['succeeded', 'failed'], true)) {
            $this->error('Outcome must be "succeeded" or "failed".');

            return self::INVALID;
        }

        $tenant = Tenant::query()->find($this->argument('tenant'));
        if ($tenant === null) {
            $this->error('Unknown tenant.');

            return self::INVALID;
        }

        $order = $tenant->run(fn (): ?Order => Order::query()->where('reference', $this->argument('order_reference'))->first());
        if ($order === null) {
            $this->error('Unknown order reference for this tenant.');

            return self::INVALID;
        }

        $payload = [
            'id' => $this->option('event-id') ?: 'evt_sim_'.Str::lower(Str::random(16)),
            'type' => $outcome === 'succeeded' ? ProcessPaymentWebhook::SUCCEEDED : ProcessPaymentWebhook::FAILED,
            'payment_reference' => $order->payment_reference,
            'amount' => $this->option('amount') !== null ? (int) $this->option('amount') : $order->total_minor,
            'currency' => $order->currency,
            'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];

        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $body, (string) $tenant->webhook_secret);
        $url = rtrim((string) $this->option('url'), '/')."/api/webhooks/payments/{$tenant->id}";

        $this->line("POST {$url}");
        $this->line("event {$payload['id']} · {$payload['type']} · {$order->reference} · {$payload['amount']} {$order->currency}");

        $response = Http::withBody($body, 'application/json')->withHeaders(['X-Signature' => $signature, 'Accept' => 'application/json'])->post($url);

        $this->line('-> HTTP '.$response->status().' '.$response->body());

        return $response->successful() ? self::SUCCESS : self::FAILURE;
    }
}
