<?php

namespace App\Actions\Exports;

use App\Enums\ExportStatus;
use App\Mail\AttendeeExportReadyMail;
use App\Models\Tenant\AttendeeExport;
use App\Support\TenantStorage;
use App\Support\TenantTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class GenerateAttendeeExport
{
    public const HEADERS = ['ticket_code', 'ticket_type', 'status', 'checked_in_at', 'order_reference', 'buyer_name', 'buyer_email'];

    public function __construct(private readonly SignedExportUrl $signedUrl) {}

    /**
     * Build the CSV of one export, store it, mark it ready and e-mail the requester a temporary signed link.
     * Runs inside the tenant. Only one worker can claim a pending export (conditional UPDATE), so a duplicate or
     * retried job does nothing and the e-mail goes out once. Any failure is recorded on the row.
     */
    public function execute(int $exportId): void
    {
        $claimed = AttendeeExport::query()->whereKey($exportId)->where('status', ExportStatus::Pending)
            ->toBase()->update(['status' => ExportStatus::Processing->value, 'updated_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $path = null;

        try {
            /** @var AttendeeExport $export */
            $export = AttendeeExport::query()->with(['event:id,title', 'requester:id,name,email'])->findOrFail($exportId);
            [$path, $rows] = $this->writeCsv($export);

            AttendeeExport::query()->whereKey($exportId)->toBase()->update([
                'status' => ExportStatus::Ready->value, 'path' => $path, 'row_count' => $rows, 'error' => null,
                'completed_at' => now(), 'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
            $this->markFailed($exportId, $e);
            if ($path !== null) {
                Storage::disk($this->disk())->delete($path);
            }

            return;
        }

        $this->notify($export, $rows);
    }

    /** Record a failure the queue itself reports (timeout, killed worker); a ready export is left alone. */
    public function markFailed(int $exportId, Throwable $e): void
    {
        AttendeeExport::query()->whereKey($exportId)->where('status', '!=', ExportStatus::Ready)
            ->toBase()->update(['status' => ExportStatus::Failed->value, 'error' => mb_substr($e->getMessage(), 0, 1000), 'updated_at' => now()]);
    }

    /**
     * Stream the event's tickets into a buffer (keyset pagination, one chunk in memory at a time) and copy it to
     * the disk. Returns the stored path and the number of data rows.
     *
     * @return array{0: string, 1: int}
     */
    private function writeCsv(AttendeeExport $export): array
    {
        $buffer = fopen('php://temp/maxmemory:'.(int) config('ticketing.exports.memory_bytes'), 'w+b');
        if ($buffer === false) {
            throw new RuntimeException('Cannot open a buffer for the export.');
        }

        fputcsv($buffer, self::HEADERS, ',', '"', '');

        $rows = 0;
        DB::table('tickets')
            ->join('ticket_types', 'ticket_types.id', '=', 'tickets.ticket_type_id')
            ->join('orders', 'orders.id', '=', 'tickets.order_id')
            ->where('tickets.event_id', $export->event_id)
            ->select([
                'tickets.id as id', 'tickets.code', 'ticket_types.name as ticket_type', 'tickets.status', 'tickets.checked_in_at',
                'orders.reference as order_reference', 'orders.buyer_name', 'orders.buyer_email',
            ])
            ->lazyById((int) config('ticketing.exports.chunk_size'), 'tickets.id', 'id')
            ->each(function (object $row) use ($buffer, &$rows): void {
                fputcsv($buffer, array_map(self::safe(...), [
                    $row->code,
                    $row->ticket_type,
                    $row->status,
                    $row->checked_in_at === null ? '' : TenantTime::iso(CarbonImmutable::parse($row->checked_in_at)),
                    $row->order_reference,
                    $row->buyer_name,
                    $row->buyer_email,
                ]), ',', '"', '');
                $rows++;
            });

        rewind($buffer);
        $path = TenantStorage::exportPath(Str::uuid()->toString().'.csv');

        if (! Storage::disk($this->disk())->writeStream($path, $buffer)) {
            throw new RuntimeException('Could not store the export file.');
        }
        fclose($buffer);

        return [$path, $rows];
    }

    /** A cell that starts with = + - @ would run as a formula in a spreadsheet; a leading quote makes it plain text. */
    private static function safe(?string $value): string
    {
        $value ??= '';

        return $value !== '' && str_contains("=+-@\t\r", $value[0]) ? "'".$value : $value;
    }

    private function notify(AttendeeExport $export, int $rows): void
    {
        $requester = $export->requester;
        if ($requester === null) {
            return; // the admin who asked no longer exists: the file is still downloadable through the status endpoint of any admin
        }

        Mail::to($requester->email, $requester->name)->queue(new AttendeeExportReadyMail(
            tenantName: (string) tenant('name'),
            adminName: $requester->name,
            eventTitle: $export->event->title,
            rowCount: $rows,
            downloadUrl: $this->signedUrl->for($export->id),
            validMinutes: (int) config('ticketing.exports.link_minutes'),
        ));
    }

    private function disk(): string
    {
        return (string) config('ticketing.exports.disk');
    }
}
