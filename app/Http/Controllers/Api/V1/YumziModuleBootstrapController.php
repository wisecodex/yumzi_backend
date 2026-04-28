<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\YumziModuleBootstrapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class YumziModuleBootstrapController extends Controller
{
    public function __construct(private readonly YumziModuleBootstrapService $service)
    {
    }

    public function show(Request $request, string $module): JsonResponse
    {
        $result = $this->service->bootstrap($request, $module);

        return response()->json($result['body'], $result['status']);
    }
}
