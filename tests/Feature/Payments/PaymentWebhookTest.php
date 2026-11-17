<?php

use App\Actions\Orders\QueueOrderEmail;
use App\Actions\Orders\ReleaseOrder;
use App\Actions\Payments\MarkOrderPaid;
use App\Actions\Tickets\IssueTickets;
use App\Enums\OrderStatus;
use App\Models\Central\Tenant;
use App\Models\Tenant\Event;
use App\Models\Tenant\Order;
use App\Models\Tenant\TicketType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/** Post a webhook: the body is sent byte for byte, signed (or not) the way the provider would. */
function sendWebhook(string $tenantId, array|string $payload, ?string $secret = null, ?string $signature = null): TestResponse
{
    $body = is_string($payload) ? $payload : json_encode($payload);
    $secret ??= Tenant::query()->findOrFail($tenantId)->webhook_secret;
    $signature ??= hash_hmac('sha256', $body, (string) $secret);

    return test()->call('POST', "/api/webhooks/{$tenantId}/payments", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SIGNATURE' => $signature,
    ], $body);
}

function paymentEvent(string $id, string $type, string $reference, ?int $amount = null, string $currency = 'MYR'): array
{
    return ['id' => $id, 'type' => $type, 'data' => array_filter(['order_reference' => $reference, 'amount_minor' => $amount, 'currency' => $currency], fn ($v) => $v !== null)];
}

beforeEach(function () {
    Carbon::setTestNow('2027-01-01 00:00:00');
    $this->tenant = provisionTenant('acme-events');
    $this->pub = ['X-Tenant-ID' => 'acme-events'];

    [$this->event, $this->tier] = $this->tenant->run(function () {
        $event = Event::factory()->published()->create(['slug' => 'sunset', 'starts_at' => '2027-03-01 11:00:00', 'ends_at' => '2027-03-01 15:00:00']);

        return [$event, TicketType::factory()->for($event)->create(['name' => 'General', 'price_minor' => 5000, 'quantity' => 10, 'max_per_order' => 5])];
    });

    // A pending order for 2 tickets: subtotal 10000, fee 550, total 10550.
    $this->placeOrder = function (int $qty = 2, array $headers = []): array {
        $response = $this->postJson('/api/events/sunset/checkout', [
            'buyer_name' => 'Aisha', 'buyer_email' => 'aisha@example.com',
            'items' => [['ticket_type_id' => $this->tier->id, 'quantity' => $qty]],
        ], $this->pub + $headers)->assertCreated();

        return [$response->json('data.reference'), $response->json('data.total_minor')];
    };
    $this->stock = fn () => $this->tenant->run(fn () => TicketType::query()->find($this->tier->id)->only(['sold', 'held']));
    $this->orderStatus = fn (string $reference) => $this->tenant->run(fn () => Order::query()->where('reference', $reference)->first()->status);
});

describe('payment succeeded', function () {
    it('marks the order paid and turns the hold into a sale', function () {
        [$reference, $total] = ($this->placeOrder)();

        sendWebhook('acme-events', paymentEvent('evt_1', 'payment.succeeded', $reference, $total))
            ->assertOk()->assertJson(['status' => 'paid', 'order' => $reference]);

        expect(($this->orderStatus)($reference))->toBe(OrderStatus::Paid)
            ->and(($this->stock)())->toBe(['sold' => 2, 'held' => 0]);
        $this->getJson("/api/orders/{$reference}", $this->pub)->assertJsonPath('data.status', 'paid')->assertJsonPath('data.paid_at', fn ($v) => $v !== null);
    });

    it('treats a payment after expires_at, before the expiry job ran, as a normal payment', function () {
        [$reference, $total] = ($this->placeOrder)();
        Carbon::setTestNow('2027-01-01 00:11:00'); // one minute past the 10 minute hold

        sendWebhook('acme-events', paymentEvent('evt_1', 'payment.succeeded', $reference, $total))->assertOk()->assertJson(['status' => 'paid']);

        expect(($this->orderStatus)($reference))->toBe(OrderStatus::Paid)->and(($this->stock)())->toBe(['sold' => 2, 'held' => 0]);
    });

    it('is exactly once: a duplicate event id does nothing', function () {
        [$reference, $total] = ($this->placeOrder)();
        $event = paymentEvent('evt_1', 'payment.succeeded', $reference, $total);

        sendWebhook('acme-events', $event)->assertOk()->assertJson(['status' => 'paid']);
        sendWebhook('acme-events', $event)->assertOk()->assertJson(['status' => 'duplicate']);

        expect(($this->stock)())->toBe(['sold' => 2, 'held' => 0])
            ->and($this->tenant->run(fn () => DB::table('webhook_events')->count()))->toBe(1);
    });

    it('does not pay twice when the provider sends a second success under a new id', function () {
        [$reference, $total] = ($this->placeOrder)();

        sendWebhook('acme-events', paymentEvent('evt_1', 'payment.succeeded', $reference, $total))->assertJson(['status' => 'paid']);
        sendWebhook('acme-events', paymentEvent('evt_2', 'payment.succeeded', $reference, $total))->assertOk()->assertJson(['status' => 'already_paid']);

        expect(($this->stock)())->toBe(['sold' => 2, 'held' => 0]);
    });

    it('refuses to pay an order when the amount or currency differs', function (int $delta, string $currency, string $why) {
        [$reference, $total] = ($this->placeOrder)();

        sendWebhook('acme-events', paymentEvent('evt_1', 'payment.succeeded', $reference, $total + $delta, $currency))
            ->assertStatus(422)->assertJsonPath('error', 'amount_mismatch');

        expect(($this->orderStatus)($reference))->toBe(OrderStatus::Pending)->and(($this->stock)())->toBe(['sold' => 0, 'held' => 2]);
    })->with([
        'too little' => [-1, 'MYR', 'amount'],
        'too much' => [1, 'MYR', 'amount'],
        'wrong currency' => [0, 'USD', 'currency'],
    ]);

    it('ignores a success for a reference it does not know, without a retry storm', function () {
        sendWebhook('acme-events', paymentEvent('evt_1', 'payment.succeeded', 'ORD-NOSUCHORDER1', 100))->assertOk()->assertJson(['status' => 'unknown_order']);
    });
});

describe('payment failed', function () {
    it('fails the order and gives the held tickets back', function () {
        [$reference] = ($this->placeOrder)(3);
        expect(($this->stock)())->toBe(['sold' => 0, 'held' => 3]);

        sendWebhook('acme-events', paymentEvent('evt_1', 'payment.failed', $reference))->assertOk()->assertJson(['status' => 'failed']);

        expect(($this->orderStatus)($reference))->toBe(OrderStatus::Failed)->and(($this->stock)())->toBe(['sold' => 0, 'held' => 0]);
        // the freed tickets can be bought again
        ($this->placeOrder)(5);
    });

    it('never undoes a paid order or revives a failed one', function () {
        [$paidRef, $paidTotal] = ($this->placeOrder)(1);
        sendWebhook('acme-events', paymentEvent('evt_1', 'payment.succeeded', $paidRef, $paidTotal))->assertJson(['status' => 'paid']);
        sendWebhook('acme-events', paymentEvent('evt_2', 'payment.failed', $paidRef))->assertOk()->assertJson(['status' => 'ignored']);

        [$failedRef, $failedTotal] = ($this->placeOrder)(1);
        sendWebhook('acme-events', paymentEvent('evt_3', 'payment.failed', $failedRef))->assertJson(['status' => 'failed']);
        sendWebhook('acme-events', paymentEvent('evt_4', 'payment.succeeded', $failedRef, $failedTotal))->assertOk()->assertJson(['status' => 'ignored']);

        expect(($this->orderStatus)($paidRef))->toBe(OrderStatus::Paid)
            ->and(($this->orderStatus)($failedRef))->toBe(OrderStatus::Failed)
            ->and(($this->stock)())->toBe(['sold' => 1, 'held' => 0]);
    });
});

describe('late payments (the hold already expired)', function () {
    beforeEach(function () {
        $this->expire = function (string $reference) {
            $this->tenant->run(function () use ($reference) {
                DB::transaction(function () use ($reference) {
                    $order = Order::query()->where('reference', $reference)->with('items')->first();
                    app(ReleaseOrder::class)->execute($order, OrderStatus::Expired);
                });
            });
        };
    });

    it('honours the payment when the tickets are still free', function () {
        [$reference, $total] = ($this->placeOrder)(2);
        ($this->expire)($reference);
        expect(($this->stock)())->toBe(['sold' => 0, 'held' => 0]);

        sendWebhook('acme-events', paymentEvent('evt_1', 'payment.succeeded', $reference, $total))->assertOk()->assertJson(['status' => 'paid_late']);

        expect(($this->orderStatus)($reference))->toBe(OrderStatus::Paid)->and(($this->stock)())->toBe(['sold' => 2, 'held' => 0]);
    });

    it('does not oversell when the tickets went to someone else; the order stays expired', function () {
        [$reference, $total] = ($this->placeOrder)(5);
        ($this->expire)($reference);
        $this->tenant->run(fn () => TicketType::query()->whereKey($this->tier->id)->update(['sold' => 8])); // only 2 left, order wants 5

        sendWebhook('acme-events', paymentEvent('evt_1', 'payment.succeeded', $reference, $total))->assertOk()->assertJson(['status' => 'late_payment_no_stock']);

        expect(($this->orderStatus)($reference))->toBe(OrderStatus::Expired)->and(($this->stock)())->toBe(['sold' => 8, 'held' => 0]);
        expect($this->tenant->run(fn () => DB::table('webhook_events')->value('outcome')))->toBe('late_payment_no_stock'); // recorded for a refund
    });
});

describe('authenticity', function () {
    it('rejects a missing, wrong or foreign signature and changes nothing', function (string $case) {
        [$reference, $total] = ($this->placeOrder)();
        $other = provisionTenant('other-org', 'admin@other.test');
        $event = paymentEvent('evt_1', 'payment.succeeded', $reference, $total);
        $body = json_encode($event);

        $response = match ($case) {
            'wrong secret' => sendWebhook('acme-events', $body, 'not-the-secret'),
            'another tenants secret' => sendWebhook('acme-events', $body, $other->webhook_secret),
            'tampered body' => sendWebhook('acme-events', str_replace((string) $total, '1', $body), null, hash_hmac('sha256', $body, Tenant::query()->find('acme-events')->webhook_secret)),
            'empty signature' => sendWebhook('acme-events', $body, null, ''),
            'garbage signature' => sendWebhook('acme-events', $body, null, 'zzzz'),
        };

        $response->assertStatus(401)->assertJsonPath('error', 'invalid_signature');
        expect(($this->orderStatus)($reference))->toBe(OrderStatus::Pending)
            ->and($this->tenant->run(fn () => DB::table('webhook_events')->count()))->toBe(0);
    })->with(['wrong secret', 'another tenants secret', 'tampered body', 'empty signature', 'garbage signature']);

    it('accepts the sha256= prefix form', function () {
        [$reference, $total] = ($this->placeOrder)();
        $body = json_encode(paymentEvent('evt_1', 'payment.succeeded', $reference, $total));

        sendWebhook('acme-events', $body, null, 'sha256='.hash_hmac('sha256', $body, Tenant::query()->find('acme-events')->webhook_secret))
            ->assertOk()->assertJson(['status' => 'paid']);
    });

    it('checks the signature before it looks at the body', function () {
        // not JSON at all: a forged request must be told 401, never 422/400 (no information about the parser)
        sendWebhook('acme-events', '{not json', 'wrong-secret')->assertStatus(401);
        // properly signed but malformed: now it is a validation problem
        sendWebhook('acme-events', '{"id":"x"}')->assertStatus(422)->assertJsonPath('error', 'validation_failed');
        sendWebhook('acme-events', '{not json')->assertStatus(422);
    });

    it('uses the secret of the tenant in the URL, never one from the payload', function () {
        [$reference, $total] = ($this->placeOrder)();
        $other = provisionTenant('other-org', 'admin@other.test');

        // Tenant B signs a notification for tenant A's order and posts it to B's own URL: unknown order there.
        sendWebhook('other-org', paymentEvent('evt_1', 'payment.succeeded', $reference, $total))->assertOk()->assertJson(['status' => 'unknown_order']);
        // ... and the same message posted to A's URL with B's signature is forged.
        sendWebhook('acme-events', paymentEvent('evt_2', 'payment.succeeded', $reference, $total), $other->webhook_secret)->assertStatus(401);

        expect(($this->orderStatus)($reference))->toBe(OrderStatus::Pending);
    });

    it('answers like every other tenant route for an unknown or unready tenant', function () {
        sendWebhook('no-such-org', paymentEvent('evt_1', 'payment.succeeded', 'ORD-X', 1), 'whatever')->assertNotFound()->assertJsonPath('error', 'tenant_not_found');
    });

    it('throttles forged traffic per tenant and IP', function () {
        config(['ticketing.rate_limits.webhook_per_minute' => 3]);

        foreach (range(1, 3) as $_) {
            sendWebhook('acme-events', '{}', 'bad')->assertStatus(401);
        }
        sendWebhook('acme-events', '{}', 'bad')->assertStatus(429);
    });
});

describe('failure safety', function () {
    it('rolls the dedupe record back with the effect, so the provider retry is processed', function () {
        [$reference, $total] = ($this->placeOrder)();
        $event = paymentEvent('evt_1', 'payment.succeeded', $reference, $total);

        $state = new stdClass;
        $state->broken = true;
        $this->app->bind(MarkOrderPaid::class, fn () => $state->broken
            ? new class extends MarkOrderPaid
            {
                public function execute(Order $order): void
                {
                    throw new RuntimeException('database went away');
                }
            }
            : new MarkOrderPaid(app(IssueTickets::class), app(QueueOrderEmail::class)));

        sendWebhook('acme-events', $event)->assertStatus(500);
        expect(($this->orderStatus)($reference))->toBe(OrderStatus::Pending)
            ->and($this->tenant->run(fn () => DB::table('webhook_events')->count()))->toBe(0);

        $state->broken = false; // the provider retries
        sendWebhook('acme-events', $event)->assertOk()->assertJson(['status' => 'paid']);
        expect(($this->orderStatus)($reference))->toBe(OrderStatus::Paid);
    });
});

describe('free orders', function () {
    it('are paid at once, through the same code, and sell the tickets', function () {
        $free = $this->tenant->run(fn () => TicketType::factory()->for($this->event)->create(['name' => 'Free entry', 'price_minor' => 0, 'quantity' => 5]));

        $response = $this->postJson('/api/events/sunset/checkout', [
            'buyer_name' => 'Aisha', 'buyer_email' => 'aisha@example.com',
            'items' => [['ticket_type_id' => $free->id, 'quantity' => 2]],
        ], $this->pub)->assertCreated()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.total_minor', 0)->assertJsonPath('data.fee_minor', 0);

        expect($this->tenant->run(fn () => TicketType::query()->find($free->id)->only(['sold', 'held'])))->toBe(['sold' => 2, 'held' => 0]);
    });
});

describe('payments:simulate', function () {
    it('signs the notification with the tenant secret and posts it to the webhook URL', function () {
        [$reference, $total] = ($this->placeOrder)();
        Http::fake(['*' => Http::response(['status' => 'paid'], 200)]);

        $this->artisan('payments:simulate', ['tenant' => 'acme-events', 'order_reference' => $reference, 'outcome' => 'succeeded', '--url' => 'http://api.test'])
            ->assertSuccessful();

        Http::assertSent(function ($request) use ($reference, $total) {
            $secret = Tenant::query()->find('acme-events')->webhook_secret;

            return $request->url() === 'http://api.test/api/webhooks/acme-events/payments'
                && hash_equals(hash_hmac('sha256', $request->body(), $secret), $request->header('X-Signature')[0])
                && json_decode($request->body(), true)['data'] === ['order_reference' => $reference, 'amount_minor' => $total, 'currency' => 'MYR'];
        });
    });

    it('refuses an unknown order or outcome', function () {
        $this->artisan('payments:simulate', ['tenant' => 'acme-events', 'order_reference' => 'ORD-NOPE', 'outcome' => 'succeeded'])->assertExitCode(2);
        $this->artisan('payments:simulate', ['tenant' => 'acme-events', 'order_reference' => 'ORD-NOPE', 'outcome' => 'maybe'])->assertExitCode(2);
    });
});
