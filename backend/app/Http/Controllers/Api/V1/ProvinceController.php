<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Province;
use Illuminate\Http\JsonResponse;

class ProvinceController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Province::query()->whereNull('tenant_id')->orderBy('sort_order')->get()->map(fn (Province $province) => ['id' => $province->id, 'name_ar' => $province->name_ar, 'name_en' => $province->name_en, 'name_ku' => $province->name_ku])]);
    }
}
