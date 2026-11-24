<?php

use App\Actions\Exports\GenerateAttendeeExport;
use App\Actions\Tickets\IssueTickets;
use App\Enums\ExportStatus;
use App\Enums\UserRole;
use App\Jobs\GenerateAttendeeExportJob;
use App\Mail\AttendeeExportReadyMail;
use App\Models\Tenant\AttendeeExport;
use App\Models\Tenant\Event;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\Tenant\TicketType;
use App\Models\Tenant\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/** A paid order with `$quantity` issued tickets of `$tier` for the buyer. */
function paidOrderWithTickets(Event $event, TicketType $tier, string $buyer, string $email, int $quantity): Order
{
    $order = Order::factory()->paid()->create(['event_id' => $event->id, 'buyer_name' => $buyer, 'buyer_email' => $email]);
    OrderItem::query()->insert([[
        'order_id' => $order->id, 'ticket_type_id' => $tier->id, 'ticket_type_name' => $tier->name, 'unit_price_minor' => 5000, 'quantity' => $quantity,
        'line_total_minor' => 5000 * $quantity, 'unit_fee_minor' => 0, 'line_fee_minor' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]]);
    app(IssueTickets::class)->execute($order->load('items'));

    return $order;
}

beforeEach(function () {
    Carbon::setTestNow('2027-01-01 00:00:00');
    Storage::fake('exports');
    $this->tenant = provisionTenant('acme-events');
    $this->admin = tenantHeaders($this->tenant);

    [$this->event, $this->vip, $this->general] = $this->tenant->run(function () {
        $event = Event::factory()->published()->create(['slug' => 'sunset', 'starts_at' => '2027-03-01 11:00:00', 'ends_at' => '2027-03-01 15:00:00']);
        $vip = TicketType::factory()->for($event)->create(['name' => 'VIP']);
        $general = TicketType::factory()->for($event)->create(['name' => 'General']);
        paidOrderWithTickets($event, $vip, 'Aisha Rahman', 'aisha@example.com', 1);
        paidOrderWithTickets($event, $general, '=HYPERLINK("x")', 'evil@example.com', 2);
        // another event's tickets must not leak into this export
        $other = Event::factory()->published()->create();
        paidOrderWithTickets($other, TicketType::factory()->for($other)->create(), 'Someone Else', 'else@example.com', 1);

        return [$event, $vip, $general];
    });
    $this->tenant->run(fn () => DB::table('tickets')->where('event_id', $this->event->id)->orderBy('id')->limit(1)->update(['status' => 'checked_in', 'checked_in_at' => '2027-01-01 00:00:00']));

    $this->request = fn (?array $headers = null) => $this->postJson("/api/admin/events/{$this->event->id}/attendee-exports", [], $headers ?? $this->admin);
    $this->run = fn (int $id) => $this->tenant->run(fn () => (new GenerateAttendeeExportJob('acme-events', $id))->handle(app(GenerateAttendeeExport::class)));
    $this->export = fn (int $id) => $this->tenant->run(fn () => AttendeeExport::query()->find($id));
});

describe('requesting', function () {
    it('answers 202 and queues a tenant job that carries ids only', function () {
        Queue::fake();

        $id = ($this->request)()->assertStatus(202)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.download_url', null)
            ->assertJsonPath('data.event_id', $this->event->id)
            ->json('data.id');

        Queue::assertPushed(GenerateAttendeeExportJob::class, fn (GenerateAttendeeExportJob $job) => $job->tenantId === 'acme-events' && $job->exportId === $id);
        Queue::assertPushed(GenerateAttendeeExportJob::class, 1);
    });

    it('is for admins only', function () {
        Queue::fake();
        $id = ($this->request)()->json('data.id');
        $door = tenantHeaders($this->tenant, UserRole::DoorStaff);

        ($this->request)($door)->assertForbidden();
        $this->getJson("/api/admin/attendee-exports/{$id}", $door)->assertForbidden();
        ($this->request)(['X-Tenant-ID' => 'acme-events'])->assertStatus(401);
        Queue::assertPushed(GenerateAttendeeExportJob::class, 1); // only the admin's request
    });

    it('answers 404 for an unknown event', function () {
        $this->postJson('/api/admin/events/9999/attendee-exports', [], $this->admin)->assertNotFound();
    });
});

describe('building the file', function () {
    it('writes one row per ticket of that event only, then marks the export ready and queues one e-mail', function () {
        Mail::fake();
        $id = ($this->request)()->assertStatus(202)->json('data.id'); // the sync queue ran the job

        $export = ($this->export)($id);
        expect($export->status)->toBe(ExportStatus::Ready)
            ->and($export->row_count)->toBe(3)
            ->and($export->path)->toStartWith('exports/acme-events/')->toEndWith('.csv')
            ->and($export->completed_at)->not->toBeNull();

        $lines = array_map('str_getcsv', explode("\n", trim(Storage::disk('exports')->get($export->path))));
        expect($lines[0])->toBe(['ticket_code', 'ticket_type', 'status', 'checked_in_at', 'order_reference', 'buyer_name', 'buyer_email'])
            ->and($lines)->toHaveCount(4);
        $rows = collect(array_slice($lines, 1));
        expect($rows->pluck(1)->all())->toBe(['VIP', 'General', 'General'])
            ->and($rows[0][2])->toBe('checked_in')->and($rows[0][3])->toBe('2027-01-01T08:00:00+08:00')
            ->and($rows[1][2])->toBe('valid')->and($rows[1][3])->toBe('')
            ->and($rows[0][5])->toBe('Aisha Rahman')->and($rows[0][6])->toBe('aisha@example.com')
            ->and($rows[1][5])->toBe("'=HYPERLINK(\"x\")")  // a formula is neutralised
            ->and($rows[0][4])->toStartWith('ORD-')
            ->and($rows->flatten()->implode(','))->not->toContain('else@example.com');

        Mail::assertQueued(AttendeeExportReadyMail::class, 1);
        Mail::assertQueued(AttendeeExportReadyMail::class, fn (AttendeeExportReadyMail $mail) => $mail->hasTo('admin@acme.test') && str_contains($mail->downloadUrl, '/api/public/acme-events/attendee-exports/'.$id.'/download?expires='));
    });

    it('renders the mail with the link', function () {
        $mail = new AttendeeExportReadyMail('Acme Events', 'Acme Admin', 'Sunset', 3, 'https://example.test/dl?signature=x', 30);

        $mail->assertSeeInHtml('https://example.test/dl?signature=x');
        $mail->assertSeeInHtml('30 minutes');
    });

    it('sends the e-mail once even if the job runs twice', function () {
        Mail::fake();
        Queue::fake();
        $id = ($this->request)()->json('data.id');

        ($this->run)($id);
        ($this->run)($id); // a duplicate delivery finds the export no longer pending

        Mail::assertQueued(AttendeeExportReadyMail::class, 1);
        expect(Storage::disk('exports')->allFiles())->toHaveCount(1);
    });

    it('records the error when it fails and writes no half file', function () {
        Mail::fake();
        Queue::fake();
        $id = ($this->request)()->json('data.id');
        config(['ticketing.exports.disk' => 'no-such-disk']);

        ($this->run)($id);

        $export = ($this->export)($id);
        expect($export->status)->toBe(ExportStatus::Failed)->and($export->error)->toContain('no-such-disk')->and($export->path)->toBeNull();
        Mail::assertNothingQueued();
        $this->getJson("/api/admin/attendee-exports/{$id}", $this->admin)->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.download_url', null);
    });

    it('streams in chunks instead of loading every ticket', function () {
        Mail::fake();
        config(['ticketing.exports.chunk_size' => 1]);
        $id = ($this->request)()->json('data.id');

        expect(($this->export)($id)->row_count)->toBe(3); // three chunks of one ticket
    });
});

describe('status and download', function () {
    it('shows a temporary signed link once ready, and the link downloads the CSV', function () {
        Mail::fake();
        $id = ($this->request)()->json('data.id');

        $url = $this->getJson("/api/admin/attendee-exports/{$id}", $this->admin)->assertOk()
            ->assertJsonPath('data.status', 'ready')->assertJsonPath('data.row_count', 3)->assertJsonPath('data.download_url_valid_minutes', 30)
            ->json('data.download_url');

        $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        expect($response->headers->get('Content-Disposition'))->toContain("attendees-sunset-{$id}.csv")
            ->and($response->streamedContent())->toContain('ticket_code,ticket_type,status')->toContain('aisha@example.com');
    });

    it('refuses a missing, tampered or expired signature with invalid_signature', function () {
        Mail::fake();
        $id = ($this->request)()->json('data.id');
        $url = $this->getJson("/api/admin/attendee-exports/{$id}", $this->admin)->json('data.download_url');

        $this->getJson("/api/public/acme-events/attendee-exports/{$id}/download")->assertForbidden()->assertJsonPath('error', 'invalid_signature');
        $this->getJson(str_replace("attendee-exports/{$id}", 'attendee-exports/'.($id + 1), $url))->assertForbidden()->assertJsonPath('error', 'invalid_signature');
        $this->getJson(preg_replace('/signature=[^&]+/', 'signature=abc', $url))->assertForbidden();

        Carbon::setTestNow('2027-01-01 00:31:00'); // 31 minutes later, the link lived 30
        $this->getJson($url)->assertForbidden()->assertJsonPath('error', 'invalid_signature');
    });

    it('answers export_not_ready for an export that is still waiting', function () {
        Queue::fake();
        $id = ($this->request)()->json('data.id');
        $url = URL::temporarySignedRoute('public.attendee-exports.download', now()->addMinutes(5), ['tenant' => 'acme-events', 'export' => $id]);

        $this->getJson($url)->assertStatus(409)->assertJsonPath('error', 'export_not_ready');
    });
});

describe('tenant isolation', function () {
    it('keeps tenant B away from tenant A\'s export, by status endpoint and by link', function () {
        Mail::fake();
        $id = ($this->request)()->json('data.id');
        $url = $this->getJson("/api/admin/attendee-exports/{$id}", $this->admin)->json('data.download_url');
        $other = provisionTenant('other-org', 'admin@other.test');

        $this->getJson("/api/admin/attendee-exports/{$id}", tenantHeaders($other))->assertNotFound();
        $this->postJson("/api/admin/events/{$this->event->id}/attendee-exports", [], tenantHeaders($other))->assertNotFound();
        // A's signed link with the tenant swapped breaks the signature; a link properly signed for B finds no such export there
        $this->getJson(str_replace('acme-events', 'other-org', $url))->assertForbidden();
        $foreign = $other->run(fn () => URL::temporarySignedRoute('public.attendee-exports.download', now()->addMinutes(5), ['tenant' => 'other-org', 'export' => $id]));
        $this->getJson($foreign)->assertNotFound();
    });

    it('stores files under the tenant id and serves nothing outside it', function () {
        Mail::fake();
        $id = ($this->request)()->json('data.id');
        $path = ($this->export)($id)->path;
        expect($path)->toStartWith('exports/acme-events/');

        // a tampered row pointing at another tenant's folder is refused
        Storage::disk('exports')->put('exports/other-org/secret.csv', 'secret');
        $this->tenant->run(fn () => DB::table('attendee_exports')->where('id', $id)->update(['path' => 'exports/other-org/secret.csv']));
        $url = URL::temporarySignedRoute('public.attendee-exports.download', now()->addMinutes(5), ['tenant' => 'acme-events', 'export' => $id]);

        $this->getJson($url)->assertNotFound();
    });

    it('needs a user that exists in the tenant (the token cannot cross tenants)', function () {
        $other = provisionTenant('other-org', 'admin@other.test');
        $headers = tenantHeaders($other);

        $this->postJson("/api/admin/events/{$this->event->id}/attendee-exports", [], ['X-Tenant-ID' => 'acme-events'] + $headers)->assertStatus(401);
        expect($this->tenant->run(fn () => User::query()->count()))->toBe(1);
    });
});
