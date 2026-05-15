<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\PayKginicis\Concerns\ValidatesTimestampFreshness;
use Plugins\Sirsoft\PayKginicis\Http\Requests\MobileSignatureRequest;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;

class MobileSignatureController
{
    use ValidatesTimestampFreshness;

    public function __construct(
        private readonly KgInicisApiService $apiService,
    ) {}

    /**
     * generate
     *
     * @param  MobileSignatureRequest  $request
     * @return JsonResponse
     */
    public function generate(MobileSignatureRequest $request): JsonResponse
    {
        $validated = $request->validated();

        if (! $this->isTimestampFresh((string) $validated['timestamp'])) {
            return response()->json([
                'success' => false,
                'message' => 'Timestamp is stale or invalid (signature replay protection).',
            ], 422);
        }

        $chkfake = $this->apiService->generateMobileChkfake(
            $validated['oid'],
            (int) $validated['price'],
            $validated['timestamp'],
        );

        return response()->json([
            'data' => [
                'chkfake' => $chkfake,
                'mobile_payment_url' => $this->apiService->getMobilePaymentUrl(),
            ],
        ]);
    }
}
