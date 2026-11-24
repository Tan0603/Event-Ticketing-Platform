<?php

namespace App\Actions\Exports;

use App\Enums\ExportStatus;
use App\Jobs\GenerateAttendeeExportJob;
use App\Models\Tenant\AttendeeExport;
use App\Models\Tenant\Event;
use App\Models\Tenant\User;

class RequestAttendeeExport
{
    /** Record the request and queue the work. The job gets ids only and re-enters the tenant itself. */
    public function execute(Event $event, User $requester): AttendeeExport
    {
        $export = new AttendeeExport;
        $export->forceFill([
            'event_id' => $event->id,
            'requested_by' => $requester->id,
            'status' => ExportStatus::Pending,
        ])->save();

        GenerateAttendeeExportJob::dispatch((string) tenant('id'), $export->id)->afterCommit();

        return $export->setRelation('event', $event);
    }
}
