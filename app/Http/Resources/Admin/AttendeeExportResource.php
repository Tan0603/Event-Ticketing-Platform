<?php

namespace App\Http\Resources\Admin;

use App\Actions\Exports\SignedExportUrl;
use App\Enums\ExportStatus;
use App\Models\Tenant\AttendeeExport;
use App\Support\TenantTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AttendeeExport */
class AttendeeExportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $ready = $this->status === ExportStatus::Ready;

        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'status' => $this->status->value,
            'row_count' => $this->row_count,
            'error' => $this->error,
            // A fresh temporary signed link on every read; only while the file exists.
            'download_url' => $ready ? app(SignedExportUrl::class)->for($this->id) : null,
            'download_url_valid_minutes' => $ready ? (int) config('ticketing.exports.link_minutes') : null,
            'requested_by' => $this->requested_by,
            'created_at' => TenantTime::iso($this->created_at),
            'completed_at' => TenantTime::iso($this->completed_at),
        ];
    }
}
