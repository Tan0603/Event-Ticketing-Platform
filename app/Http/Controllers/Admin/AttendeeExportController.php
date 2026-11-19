<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Exports\RequestAttendeeExport;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AttendeeExportResource;
use App\Models\Tenant\AttendeeExport;
use App\Models\Tenant\Event;
use App\Models\Tenant\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendeeExportController extends Controller
{
    /** Queue the export: the answer is 202 and the status endpoint tells when it is ready. */
    public function store(Request $request, Event $event, RequestAttendeeExport $requestExport): JsonResponse
    {
        $this->authorize('create', [AttendeeExport::class, $event]);
        /** @var User $admin */
        $admin = $request->user();

        return (new AttendeeExportResource($requestExport->execute($event, $admin)))->response()->setStatusCode(202);
    }

    public function show(Request $request, AttendeeExport $attendeeExport): AttendeeExportResource
    {
        $this->authorize('view', $attendeeExport);

        return new AttendeeExportResource($attendeeExport);
    }
}
