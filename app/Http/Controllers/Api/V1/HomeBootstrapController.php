<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\HomeBootstrapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HomeBootstrapController extends Controller
{
    public function __construct(private readonly HomeBootstrapService $service)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $result = $this->service->bootstrap($request);

        return response()->json($result['body'], $result['status']);
    }
}
