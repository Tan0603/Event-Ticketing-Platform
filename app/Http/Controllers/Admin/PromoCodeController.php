<?php

namespace App\Http\Controllers\Admin;

use App\Actions\PromoCodes\CreatePromoCode;
use App\Actions\PromoCodes\DeletePromoCode;
use App\Actions\PromoCodes\ListPromoCodes;
use App\Actions\PromoCodes\UpdatePromoCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListPromoCodesRequest;
use App\Http\Requests\Admin\StorePromoCodeRequest;
use App\Http\Requests\Admin\UpdatePromoCodeRequest;
use App\Http\Resources\Admin\PromoCodeResource;
use App\Models\Tenant\PromoCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class PromoCodeController extends Controller
{
    public function index(ListPromoCodesRequest $request, ListPromoCodes $list): AnonymousResourceCollection
    {
        return PromoCodeResource::collection($list->execute($request->validated()));
    }

    public function store(StorePromoCodeRequest $request, CreatePromoCode $create): JsonResponse
    {
        return (new PromoCodeResource($create->execute($request->promoAttributes())))->response()->setStatusCode(201);
    }

    public function show(Request $request, PromoCode $promoCode): PromoCodeResource
    {
        $this->authorize('view', $promoCode);

        return new PromoCodeResource($promoCode);
    }

    public function update(UpdatePromoCodeRequest $request, PromoCode $promoCode, UpdatePromoCode $update): PromoCodeResource
    {
        return new PromoCodeResource($update->execute($promoCode, $request->promoAttributes()));
    }

    public function destroy(Request $request, PromoCode $promoCode, DeletePromoCode $delete): Response
    {
        $this->authorize('delete', $promoCode);
        $delete->execute($promoCode);

        return response()->noContent();
    }
}
