<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\PayKginicis\Concerns\ValidatesTimestampFreshness;
use Plugins\Sirsoft\PayKginicis\Http\Requests\SignatureRequest;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;

class PaymentSignatureController
{
    use ValidatesTimestampFreshness;

    public function __construct(
        private readonly KgInicisApiService $apiService,
    ) {}

    /**
     * generate
     *
     * @param  SignatureRequest  $request
     * @return JsonResponse
     */
    public function generate(SignatureRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $oid = $validated['oid'];
        $price = (int) $validated['price'];
        $timestamp = $validated['timestamp'];

        if (! $this->isTimestampFresh($timestamp)) {
            return response()->json([
                'success' => false,
                'message' => 'Timestamp is stale or invalid (signature replay protection).',
            ], 422);
        }

        $signature = $this->apiService->generateSignature($oid, $price, $timestamp);
        $verification = $this->apiService->generateVerification($oid, $price, $timestamp);
        $mKey = $this->apiService->getMKey();

        return response()->json([
            'data' => [
                'signature' => $signature,
                'verification' => $verification,
                'mKey' => $mKey,
            ],
        ]);
    }
}
