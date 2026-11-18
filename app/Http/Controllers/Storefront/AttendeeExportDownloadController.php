<?php

namespace App\Http\Controllers\Storefront;

use App\Enums\ExportStatus;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Tenant\AttendeeExport;
use App\Support\TenantStorage;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The link in the admin's e-mail: signed, expiring, addressed by tenant path (a link carries no header). */
class AttendeeExportDownloadController extends Controller
{
    public function __invoke(int $export): StreamedResponse
    {
        $record = AttendeeExport::query()->with('event:id,slug')->findOrFail($export);

        if ($record->status !== ExportStatus::Ready || $record->path === null) {
            throw new BusinessRuleException('export_not_ready', 'This export is not ready.');
        }

        $disk = Storage::disk((string) config('ticketing.exports.disk'));

        // Defence in depth on top of the per-tenant database: a path outside this tenant's folder is never served.
        abort_unless(TenantStorage::owns($record->path) && $disk->exists($record->path), 404, 'Export file not found.');

        return $disk->download($record->path, "attendees-{$record->event->slug}-{$record->id}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
