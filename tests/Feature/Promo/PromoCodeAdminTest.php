<?php

use App\Enums\UserRole;
use App\Models\Central\Tenant;
use App\Models\Tenant\Event;
use App\Models\Tenant\PromoCode;
use App\Models\Tenant\TicketType;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2027-01-01 00:00:00');
    $this->tenant = provisionTenant('acme-events');
    $this->admin = tenantHeaders($this->tenant);
    $this->event = $this->tenant->run(fn () => Event::factory()->published()->create(['slug' => 'sunset', 'starts_at' => '2027-03-01 11:00:00', 'ends_at' => '2027-03-01 15:00:00']));
});

describe('managing codes', function () {
    it('creates a percent code, stored upper-case, and shows it', function () {
        $id = $this->postJson('/api/admin/promo-codes', [
            'code' => 'early-bird', 'discount_type' => 'percent', 'percent_bps' => 1500, 'max_uses' => 50,
            'starts_at' => '2027-01-02 09:00:00', 'ends_at' => '2027-01-09 09:00:00',
        ], $this->admin)->assertCreated()
            ->assertJsonPath('data.code', 'EARLY-BIRD')
            ->assertJsonPath('data.percent_bps', 1500)
            ->assertJsonPath('data.amount_minor', null)
            ->assertJsonPath('data.used', 0)
            ->assertJsonPath('data.remaining', 50)
            ->assertJsonPath('data.starts_at', '2027-01-02T09:00:00+08:00')
            ->json('data.id');

        $this->getJson("/api/admin/promo-codes/{$id}", $this->admin)->assertOk()->assertJsonPath('data.is_active', true);
    });

    it('creates a fixed code for one event', function () {
        $this->postJson('/api/admin/promo-codes', ['code' => 'RM10', 'discount_type' => 'fixed', 'amount_minor' => 1000, 'event_id' => $this->event->id], $this->admin)
            ->assertCreated()->assertJsonPath('data.event_id', $this->event->id);
    });

    it('validates the type/value pairing, ranges, uniqueness and the window', function () {
        $this->tenant->run(fn () => PromoCode::factory()->create(['code' => 'TAKEN']));

        $this->postJson('/api/admin/promo-codes', ['code' => 'ab', 'discount_type' => 'percent'], $this->admin)->assertStatus(422)->assertJsonValidationErrors(['code', 'percent_bps']);
        $this->postJson('/api/admin/promo-codes', ['code' => 'TOOBIG', 'discount_type' => 'percent', 'percent_bps' => 10001], $this->admin)->assertStatus(422)->assertJsonValidationErrors(['percent_bps']);
        $this->postJson('/api/admin/promo-codes', ['code' => 'MIXED', 'discount_type' => 'fixed', 'amount_minor' => 100, 'percent_bps' => 10], $this->admin)->assertStatus(422)->assertJsonValidationErrors(['percent_bps']);
        $this->postJson('/api/admin/promo-codes', ['code' => 'FREE', 'discount_type' => 'fixed', 'amount_minor' => 0], $this->admin)->assertStatus(422)->assertJsonValidationErrors(['amount_minor']);
        $this->postJson('/api/admin/promo-codes', ['code' => 'taken', 'discount_type' => 'fixed', 'amount_minor' => 100], $this->admin)->assertStatus(422)->assertJsonValidationErrors(['code']);
        $this->postJson('/api/admin/promo-codes', ['code' => 'NOEVENT', 'discount_type' => 'fixed', 'amount_minor' => 100, 'event_id' => 999], $this->admin)->assertStatus(422)->assertJsonValidationErrors(['event_id']);
        $this->postJson('/api/admin/promo-codes', ['code' => 'BACKWARDS', 'discount_type' => 'fixed', 'amount_minor' => 100, 'starts_at' => '2027-02-02', 'ends_at' => '2027-02-01'], $this->admin)->assertStatus(422)->assertJsonValidationErrors(['ends_at']);
    });

    it('lists codes paginated with filters', function () {
        $this->tenant->run(function () {
            PromoCode::factory()->count(3)->create();
            PromoCode::factory()->create(['code' => 'OLD', 'is_active' => false, 'event_id' => $this->event->id]);
        });

        $this->getJson('/api/admin/promo-codes?per_page=2', $this->admin)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 4);
        $this->getJson('/api/admin/promo-codes?is_active=0', $this->admin)->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'OLD');
        $this->getJson("/api/admin/promo-codes?event_id={$this->event->id}", $this->admin)->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/promo-codes?q=ol', $this->admin)->assertJsonCount(1, 'data');
    });

    it('updates a code, switching the type and refusing max_uses below used', function () {
        $promo = $this->tenant->run(fn () => PromoCode::factory()->percent(1000)->create(['code' => 'SAVE']));
        $this->tenant->run(fn () => PromoCode::query()->whereKey($promo->id)->update(['used' => 3, 'max_uses' => 10]));

        $this->patchJson("/api/admin/promo-codes/{$promo->id}", ['discount_type' => 'fixed'], $this->admin)->assertStatus(422)->assertJsonValidationErrors(['amount_minor']);
        $this->patchJson("/api/admin/promo-codes/{$promo->id}", ['discount_type' => 'fixed', 'amount_minor' => 500], $this->admin)->assertOk()
            ->assertJsonPath('data.amount_minor', 500)->assertJsonPath('data.percent_bps', null);
        $this->patchJson("/api/admin/promo-codes/{$promo->id}", ['max_uses' => 2], $this->admin)->assertStatus(409)->assertJsonPath('error', 'max_uses_below_used');
        $this->patchJson("/api/admin/promo-codes/{$promo->id}", ['max_uses' => 3, 'is_active' => false, 'code' => 'renamed'], $this->admin)->assertOk()
            ->assertJsonPath('data.is_active', false)->assertJsonPath('data.code', 'RENAMED')->assertJsonPath('data.remaining', 0);
    });

    it('deletes an unused code but answers promo_code_in_use for a used one', function () {
        [$unused, $used] = $this->tenant->run(fn () => [PromoCode::factory()->create(['code' => 'A1']), PromoCode::factory()->create(['code' => 'B1'])]);
        $this->tenant->run(fn () => PromoCode::query()->whereKey($used->id)->update(['used' => 1]));

        $this->deleteJson("/api/admin/promo-codes/{$unused->id}", [], $this->admin)->assertNoContent();
        $this->deleteJson("/api/admin/promo-codes/{$used->id}", [], $this->admin)->assertStatus(409)->assertJsonPath('error', 'promo_code_in_use');
        $this->getJson("/api/admin/promo-codes/{$unused->id}", $this->admin)->assertNotFound();
    });

    it('is for admins only', function () {
        $door = tenantHeaders($this->tenant, UserRole::DoorStaff);

        $this->getJson('/api/admin/promo-codes', $door)->assertForbidden();
        $this->postJson('/api/admin/promo-codes', ['code' => 'NOPE', 'discount_type' => 'fixed', 'amount_minor' => 100], $door)->assertForbidden();
        $this->getJson('/api/admin/promo-codes', ['X-Tenant-ID' => 'acme-events'])->assertStatus(401);
    });
});

describe('reports', function () {
    it('shows promo discounts in the event report while revenue stays the discounted total', function () {
        $this->tenant->run(function () {
            TicketType::factory()->for($this->event)->create(['price_minor' => 5000, 'quantity' => 10]);
            PromoCode::factory()->percent(1000)->create(['code' => 'SAVE']);
        });
        $tier = $this->tenant->run(fn () => TicketType::query()->sole()->id);
        $reference = $this->postJson('/api/events/sunset/checkout', [
            'buyer_name' => 'A', 'buyer_email' => 'a@example.com', 'promo_code' => 'save', 'items' => [['ticket_type_id' => $tier, 'quantity' => 2]],
        ], ['X-Tenant-ID' => 'acme-events'])->assertCreated()->json('data.reference');
        $body = json_encode(['id' => 'whk_1', 'type' => 'payment.succeeded', 'payment_reference' => paymentRef($reference), 'amount' => 9600, 'currency' => 'MYR']);
        $this->call('POST', '/api/webhooks/payments/acme-events', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, Tenant::query()->find('acme-events')->webhook_secret),
        ], $body)->assertOk();

        $this->getJson("/api/admin/reports/events/{$this->event->id}", $this->admin)->assertOk()
            ->assertJsonPath('data.totals.discounts_minor', 1000)->assertJsonPath('data.totals.fees_minor', 600);
        $this->getJson('/api/admin/reports/sales?from=2027-01-01&to=2027-01-01', $this->admin)->assertOk()
            ->assertJsonPath('data.totals.gross_minor', 9600)->assertJsonPath('data.totals.discounts_minor', 1000);
        $this->getJson("/api/admin/orders/{$reference}", $this->admin)->assertJsonPath('data.discount_minor', 1000)->assertJsonPath('data.promo_code', 'SAVE');
    });
});
