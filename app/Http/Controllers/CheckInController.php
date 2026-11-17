<?php

namespace App\Http\Controllers;

use App\Actions\Tickets\CheckInTicket;
use App\Http\Requests\CheckInRequest;
use App\Http\Resources\CheckInResource;
use App\Models\Tenant\User;

class CheckInController extends Controller
{
    public function store(CheckInRequest $request, CheckInTicket $checkIn): CheckInResource
    {
        /** @var User $staff */
        $staff = $request->user();
        $eventId = $request->validated('event_id');

        return new CheckInResource($checkIn->execute($staff, $request->validated('code'), $eventId === null ? null : (int) $eventId));
    }
}
