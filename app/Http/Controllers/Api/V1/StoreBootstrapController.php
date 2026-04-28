<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\StoreBootstrapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreBootstrapController extends Controller
{
    public function __construct(private readonly StoreBootstrapService $service)
    {
    }

    public function show(Request $request, string $store): JsonResponse
    {
        $result = $this->service->bootstrap($request, $store);

        return response()->json($result['body'], $result['status']);
    }
}
