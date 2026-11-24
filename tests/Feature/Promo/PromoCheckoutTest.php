<?php

use App\Actions\Orders\ExpirePendingOrders;
use App\Domain\Promo\PromoRedemption;
use App\Models\Central\Tenant;
use App\Models\Tenant\Event;
use App\Models\Tenant\Order;
use App\Models\Tenant\PromoCode;
use App\Models\Tenant\TicketType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow('2027-01-01 00:00:00');
    $this->tenant = provisionTenant('acme-events'); // fee 5 % + 50
    $this->pub = ['X-Tenant-ID' => 'acme-events'];

    [$this->event, $this->tier, $this->otherEvent] = $this->tenant->run(function () {
        $event = Event::factory()->published()->create(['slug' => 'sunset', 'starts_at' => '2027-03-01 11:00:00', 'ends_at' => '2027-03-01 15:00:00']);
        $tier = TicketType::factory()->for($event)->create(['name' => 'General', 'price_minor' => 5000, 'quantity' => 100, 'max_per_order' => 10]);
        $other = Event::factory()->published()->create(['slug' => 'other', 'starts_at' => '2027-03-02 11:00:00', 'ends_at' => '2027-03-02 15:00:00']);

        return [$event, $tier, $other];
    });

    $this->promo = fn (array $state = [], string $factory = 'percent', int $value = 1000) => $this->tenant->run(
        fn () => PromoCode::factory()->{$factory}($value)->create($state + ['code' => 'SAVE']),
    );
    $this->buy = fn (?string $promo, int $quantity = 2, array $headers = []) => $this->postJson('/api/events/sunset/checkout', array_filter([
        'buyer_name' => 'Aisha', 'buyer_email' => 'a@example.com', 'promo_code' => $promo,
        'items' => [['ticket_type_id' => $this->tier->id, 'quantity' => $quantity]],
    ], fn ($v) => $v !== null), $this->pub + $headers);
    $this->row = fn (PromoCode $promo) => $this->tenant->run(fn () => PromoCode::query()->find($promo->id));
});

describe('applying a code at checkout', function () {
    it('takes a percentage off the ticket subtotal and leaves the fee alone', function () {
        ($this->promo)();

        // 2 x 50.00 = 10000, 10 % = 1000, fee 2 x (250 + 50) = 600 -> 10000 - 1000 + 600
        ($this->buy)('save')->assertCreated()
            ->assertJsonPath('data.subtotal_minor', 10000)
            ->assertJsonPath('data.discount_minor', 1000)
            ->assertJsonPath('data.promo_code', 'SAVE')
            ->assertJsonPath('data.fee_minor', 600)
            ->assertJsonPath('data.total_minor', 9600);
    });

    it('takes a fixed amount off', function () {
        ($this->promo)([], 'fixed', 3000);

        ($this->buy)(' Save ')->assertCreated()
            ->assertJsonPath('data.discount_minor', 3000)
            ->assertJsonPath('data.total_minor', 7600);
    });

    it('never discounts more than the ticket subtotal', function () {
        ($this->promo)([], 'fixed', 999999);

        ($this->buy)('SAVE')->assertCreated()
            ->assertJsonPath('data.discount_minor', 10000)
            ->assertJsonPath('data.total_minor', 600); // only the fees are left to pay
    });

    it('changes nothing without a code', function () {
        ($this->buy)(null)->assertCreated()->assertJsonPath('data.discount_minor', 0)->assertJsonPath('data.promo_code', null)->assertJsonPath('data.total_minor', 10600);
    });

    it('counts one use per order and snapshots the discount on the order', function () {
        $promo = ($this->promo)();
        $reference = ($this->buy)('SAVE')->json('data.reference');

        $this->tenant->run(fn () => expect(Order::query()->where('reference', $reference)->value('promo_code_id'))->toBe($promo->id));
        expect(($this->row)($promo)->used)->toBe(1);

        // editing the code afterwards does not touch the order
        $this->tenant->run(fn () => PromoCode::query()->whereKey($promo->id)->update(['percent_bps' => 5000]));
        $this->getJson("/api/orders/{$reference}", $this->pub)->assertJsonPath('data.total_minor', 9600)->assertJsonPath('data.discount_minor', 1000);
    });

    it('accepts the discounted total from the payment webhook and refuses the undiscounted one', function () {
        ($this->promo)();
        $reference = ($this->buy)('SAVE')->json('data.reference');
        $send = function (int $amount, string $id) use ($reference) {
            $body = json_encode(['id' => $id, 'type' => 'payment.succeeded', 'payment_reference' => paymentRef($reference), 'amount' => $amount, 'currency' => 'MYR']);

            return $this->call('POST', '/api/webhooks/payments/acme-events', [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, Tenant::query()->find('acme-events')->webhook_secret),
            ], $body);
        };

        $send(10600, 'whk_1')->assertStatus(422);
        $send(9600, 'whk_2')->assertOk();
        expect($this->tenant->run(fn () => Order::query()->where('reference', $reference)->value('status')->value))->toBe('paid');
    });
});

describe('refusing a code', function () {
    it('answers promo_code_invalid for an unknown, inactive or other-event code', function () {
        ($this->promo)(['code' => 'OFF', 'is_active' => false]);
        ($this->promo)(['code' => 'ELSEWHERE', 'event_id' => $this->otherEvent->id]);

        foreach (['NOPE', 'OFF', 'ELSEWHERE'] as $code) {
            ($this->buy)($code)->assertStatus(422)->assertJsonPath('error', 'promo_code_invalid');
        }
        expect($this->tenant->run(fn () => [Order::query()->count(), TicketType::query()->sole()->held]))->toBe([0, 0]); // nothing held
    });

    it('answers promo_code_not_started and promo_code_expired outside its window', function () {
        ($this->promo)(['code' => 'LATER', 'starts_at' => '2027-02-01 00:00:00']);
        ($this->promo)(['code' => 'GONE', 'ends_at' => '2026-12-31 23:59:59']);

        ($this->buy)('LATER')->assertStatus(422)->assertJsonPath('error', 'promo_code_not_started');
        ($this->buy)('GONE')->assertStatus(422)->assertJsonPath('error', 'promo_code_expired');

        Carbon::setTestNow('2027-02-01 00:00:00'); // the window opens
        ($this->buy)('LATER')->assertCreated();
    });

    it('answers promo_code_exhausted once max_uses orders hold it, and rolls the stock hold back', function () {
        $promo = ($this->promo)(['max_uses' => 1]);

        ($this->buy)('SAVE')->assertCreated();
        ($this->buy)('SAVE')->assertStatus(409)->assertJsonPath('error', 'promo_code_exhausted');

        expect(($this->row)($promo)->used)->toBe(1)
            ->and($this->tenant->run(fn () => [Order::query()->count(), TicketType::query()->sole()->held]))->toBe([1, 2]);
    });

    it('decides the last use with one conditional UPDATE, so a lost race fails cleanly', function () {
        $promo = ($this->promo)(['max_uses' => 1]);

        $this->tenant->run(function () use ($promo) {
            $first = PromoRedemption::redeem($promo->id, $this->event->id, now());
            $second = PromoRedemption::redeem($promo->id, $this->event->id, now());

            expect($first['code'])->toBe('SAVE')->and($second)->toBeNull();
            expect(fn () => DB::statement('UPDATE promo_codes SET used = 2 WHERE id = ?', [$promo->id]))->toThrow(QueryException::class); // CHECK used <= max_uses
        });
    });
});

describe('giving the use back', function () {
    it('returns the use when the hold expires, and the code can be used again', function () {
        $promo = ($this->promo)(['max_uses' => 1]);
        $reference = ($this->buy)('SAVE')->json('data.reference');
        expect(($this->row)($promo)->used)->toBe(1);

        Carbon::setTestNow('2027-01-01 00:11:00');
        expect($this->tenant->run(fn () => app(ExpirePendingOrders::class)->execute()))->toBe(1)
            ->and(($this->row)($promo)->used)->toBe(0);

        ($this->buy)('SAVE')->assertCreated();
        expect(($this->row)($promo)->used)->toBe(1);
        expect($this->tenant->run(fn () => Order::query()->where('reference', $reference)->value('status')->value))->toBe('expired');
    });

    it('takes the use again when an expired order is paid late', function () {
        $promo = ($this->promo)(['max_uses' => 1]);
        $reference = ($this->buy)('SAVE')->json('data.reference');
        Carbon::setTestNow('2027-01-01 00:11:00');
        $this->tenant->run(fn () => app(ExpirePendingOrders::class)->execute());
        expect(($this->row)($promo)->used)->toBe(0);

        $body = json_encode(['id' => 'whk_late', 'type' => 'payment.succeeded', 'payment_reference' => paymentRef($reference), 'amount' => 9600, 'currency' => 'MYR']);
        $this->call('POST', '/api/webhooks/payments/acme-events', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, Tenant::query()->find('acme-events')->webhook_secret),
        ], $body)->assertOk()->assertJsonPath('status', 'paid_late');

        expect(($this->row)($promo)->used)->toBe(1);
    });

    it('does not give the use back on a refund', function () {
        $promo = ($this->promo)();
        $reference = ($this->buy)('SAVE')->json('data.reference');
        $body = json_encode(['id' => 'whk_1', 'type' => 'payment.succeeded', 'payment_reference' => paymentRef($reference), 'amount' => 9600, 'currency' => 'MYR']);
        $this->call('POST', '/api/webhooks/payments/acme-events', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, Tenant::query()->find('acme-events')->webhook_secret),
        ], $body)->assertOk();

        $this->postJson("/api/admin/orders/{$reference}/refund", [], tenantHeaders($this->tenant))->assertOk()
            ->assertJsonPath('data.refund.amount_minor', 9600); // what the buyer actually paid
        expect(($this->row)($promo)->used)->toBe(1);
    });
});

describe('idempotency', function () {
    it('replays the same order for the same key and code, and refuses the same key with another code', function () {
        ($this->promo)();
        ($this->promo)(['code' => 'OTHER']);

        $first = ($this->buy)('SAVE', 2, ['Idempotency-Key' => 'attempt-0001'])->assertCreated();
        $replay = ($this->buy)('save', 2, ['Idempotency-Key' => 'attempt-0001'])->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        expect($replay->json('data.reference'))->toBe($first->json('data.reference'));

        ($this->buy)('OTHER', 2, ['Idempotency-Key' => 'attempt-0001'])->assertStatus(422)->assertJsonPath('error', 'idempotency_key_reused');
        ($this->buy)(null, 2, ['Idempotency-Key' => 'attempt-0001'])->assertStatus(422)->assertJsonPath('error', 'idempotency_key_reused');
        expect($this->tenant->run(fn () => PromoCode::query()->where('code', 'SAVE')->value('used')))->toBe(1);
    });
});

describe('tenant isolation', function () {
    it('lets tenant B neither use nor see tenant A\'s code', function () {
        ($this->promo)();
        $other = provisionTenant('other-org', 'admin@other.test');
        $other->run(function () {
            $event = Event::factory()->published()->create(['slug' => 'sunset', 'starts_at' => '2027-03-01 11:00:00', 'ends_at' => '2027-03-01 15:00:00']);
            TicketType::factory()->for($event)->create(['price_minor' => 5000, 'quantity' => 10]);
        });
        $tierId = $other->run(fn () => TicketType::query()->sole()->id);

        $this->postJson('/api/events/sunset/checkout', [
            'buyer_name' => 'B', 'buyer_email' => 'b@example.com', 'promo_code' => 'SAVE', 'items' => [['ticket_type_id' => $tierId, 'quantity' => 1]],
        ], ['X-Tenant-ID' => 'other-org'])->assertStatus(422)->assertJsonPath('error', 'promo_code_invalid');

        $this->getJson('/api/admin/promo-codes', tenantHeaders($other))->assertOk()->assertJsonCount(0, 'data');
        $id = $this->tenant->run(fn () => PromoCode::query()->value('id'));
        $this->getJson("/api/admin/promo-codes/{$id}", tenantHeaders($other))->assertNotFound();
        expect($this->tenant->run(fn () => PromoCode::query()->value('used')))->toBe(0);
    });
});
