<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AiModelResource;
use App\Models\AiModel;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AiModelController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $models = AiModel::query()
            ->where('is_active', true)
            ->whereHas('provider', fn ($q) => $q->where('is_active', true))
            ->with('provider')
            ->orderBy('name')
            ->get();

        return AiModelResource::collection($models);
    }
}
