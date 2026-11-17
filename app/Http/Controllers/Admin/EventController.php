<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Events\CancelEvent;
use App\Actions\Events\CreateEvent;
use App\Actions\Events\DeleteEvent;
use App\Actions\Events\ListEvents;
use App\Actions\Events\PublishEvent;
use App\Actions\Events\UpdateEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListEventsRequest;
use App\Http\Requests\Admin\StoreEventRequest;
use App\Http\Requests\Admin\UpdateEventRequest;
use App\Http\Resources\Admin\EventResource;
use App\Models\Tenant\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class EventController extends Controller
{
    public function index(ListEventsRequest $request, ListEvents $list): AnonymousResourceCollection
    {
        return EventResource::collection($list->execute($request->validated()));
    }

    public function store(StoreEventRequest $request, CreateEvent $create): EventResource
    {
        return new EventResource($create->execute($request->toData()));
    }

    public function show(Request $request, Event $event): EventResource
    {
        $this->authorize('view', $event);

        return new EventResource($event->load('ticketTypes'));
    }

    public function update(UpdateEventRequest $request, Event $event, UpdateEvent $update): EventResource
    {
        return new EventResource($update->execute($event, $request->eventAttributes())->load('ticketTypes'));
    }

    public function destroy(Request $request, Event $event, DeleteEvent $delete): Response
    {
        $this->authorize('delete', $event);
        $delete->execute($event);

        return response()->noContent();
    }

    public function publish(Request $request, Event $event, PublishEvent $publish): EventResource
    {
        $this->authorize('publish', $event);

        return new EventResource($publish->execute($event)->load('ticketTypes'));
    }

    public function cancel(Request $request, Event $event, CancelEvent $cancel): EventResource
    {
        $this->authorize('cancel', $event);

        return new EventResource($cancel->execute($event)->load('ticketTypes'));
    }
}
