<?php

namespace App\Http\Controllers\Admin;

use App\Actions\TicketTypes\CreateTicketType;
use App\Actions\TicketTypes\DeleteTicketType;
use App\Actions\TicketTypes\UpdateTicketType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTicketTypeRequest;
use App\Http\Requests\Admin\UpdateTicketTypeRequest;
use App\Http\Resources\Admin\TicketTypeResource;
use App\Models\Tenant\Event;
use App\Models\Tenant\TicketType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class TicketTypeController extends Controller
{
    /** A single event has a handful of tiers, so this list is bounded by design (not paginated). */
    public function index(Request $request, Event $event): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TicketType::class);

        return TicketTypeResource::collection($event->ticketTypes()->get());
    }

    public function store(StoreTicketTypeRequest $request, Event $event, CreateTicketType $create): TicketTypeResource
    {
        return new TicketTypeResource($create->execute($event, $request->ticketTypeAttributes()));
    }

    public function show(Request $request, TicketType $ticketType): TicketTypeResource
    {
        $this->authorize('view', $ticketType);

        return new TicketTypeResource($ticketType->load('event'));
    }

    public function update(UpdateTicketTypeRequest $request, TicketType $ticketType, UpdateTicketType $update): TicketTypeResource
    {
        return new TicketTypeResource($update->execute($ticketType, $request->ticketTypeAttributes()));
    }

    public function destroy(Request $request, TicketType $ticketType, DeleteTicketType $delete): Response
    {
        $this->authorize('delete', $ticketType);
        $delete->execute($ticketType);

        return response()->noContent();
    }
}
