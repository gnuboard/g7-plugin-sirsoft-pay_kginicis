<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\PayKginicis\Http\Requests\MobileSignatureRequest;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;

class MobileSignatureController
{
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
