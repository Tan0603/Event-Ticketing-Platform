<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Reports\ListAttendees;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAttendeesRequest;
use App\Http\Resources\Admin\AttendeeResource;
use App\Models\Tenant\Event;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AttendeeController extends Controller
{
    public function index(ListAttendeesRequest $request, Event $event, ListAttendees $attendees): AnonymousResourceCollection
    {
        return AttendeeResource::collection($attendees->execute($event, $request->ticketTypeId(), $request->status(), $request->perPage()));
    }
}
