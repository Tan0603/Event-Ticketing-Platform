<?php

namespace App\Http\Controllers;

use App\Actions\CheckIn\GetCheckInStats;
use App\Actions\Tickets\CheckInTicket;
use App\Http\Requests\CheckInRequest;
use App\Http\Resources\CheckInResource;
use App\Models\Tenant\Event;
use App\Models\Tenant\User;
use Illuminate\Http\JsonResponse;

class CheckInController extends Controller
{
    public function store(CheckInRequest $request, Event $event, CheckInTicket $checkIn): CheckInResource
    {
        /** @var User $staff */
        $staff = $request->user();

        return new CheckInResource($checkIn->execute($staff, $event, $request->validated('code'), $request->validated('gate_name')));
    }

    /** Route middleware `can:check-in` (admin and door staff). */
    public function stats(Event $event, GetCheckInStats $stats): JsonResponse
    {
        return response()->json(['data' => $stats->execute($event)]);
    }
}
