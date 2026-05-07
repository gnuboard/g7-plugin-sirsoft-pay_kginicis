<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;

class CbtHashDataController
{
    public function __construct(
        private readonly KgInicisApiService $apiService,
    ) {}

    /**
     * generate
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function generate(Request $request): JsonResponse
    {
        $oid = (string) $request->input('oid', '');
        $price = (int) $request->input('price', 0);
        $timestamp = (string) $request->input('timestamp', '');

        if ($oid === '' || $price <= 0 || $timestamp === '') {
            return response()->json([
                'success' => false,
                'message' => 'Missing required parameters: oid, price, timestamp',
            ], 422);
        }

        $mid = $this->apiService->getJapanMid();
        $hashData = $this->apiService->generateCbtHashData($mid, $timestamp, $price, $oid);

        return response()->json([
            'success' => true,
            'data' => [
                'hash_data' => $hashData,
            ],
        ]);
    }
}
