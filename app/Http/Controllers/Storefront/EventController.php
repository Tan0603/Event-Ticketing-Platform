<?php

namespace App\Http\Controllers\Storefront;

use App\Actions\Storefront\ListPublicEvents;
use App\Actions\Storefront\ShowPublicEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\ListPublicEventsRequest;
use App\Http\Resources\Storefront\PublicEventListResource;
use App\Http\Resources\Storefront\PublicEventResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EventController extends Controller
{
    public function index(ListPublicEventsRequest $request, ListPublicEvents $list): AnonymousResourceCollection
    {
        return PublicEventListResource::collection($list->execute($request->validated()));
    }

    public function show(string $slug, ShowPublicEvent $show): PublicEventResource
    {
        return new PublicEventResource($show->execute($slug));
    }
}
