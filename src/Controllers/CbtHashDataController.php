<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayKginicis\Concerns\ValidatesTimestampFreshness;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;

class CbtHashDataController
{
    use ValidatesTimestampFreshness;

    public function __construct(
        private readonly KgInicisApiService $apiService,
        private readonly OrderProcessingService $orderService,
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

        $rateLimitKey = $this->rateLimitKey($request, $oid);
        if (RateLimiter::tooManyAttempts($rateLimitKey, 10)) {
            return response()->json([
                'success' => false,
                'message' => 'Too many CBT hash requests. Please try again later.',
            ], 429);
        }
        RateLimiter::hit($rateLimitKey, 60);

        if (! $this->isTimestampFresh($timestamp)) {
            return response()->json([
                'success' => false,
                'message' => 'Timestamp is stale or invalid (signature replay protection).',
            ], 422);
        }

        if (! $this->apiService->isJapanEnabled()) {
            return response()->json([
                'success' => false,
                'message' => 'Japan CBT payment is disabled.',
            ], 422);
        }

        if (! $this->apiService->isJapanConfigured()) {
            return response()->json([
                'success' => false,
                'message' => 'Japan CBT payment is not configured.',
            ], 422);
        }

        $order = $this->orderService->findByOrderNumber($oid);
        if (! $order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        if (! $order->order_status->isBeforePayment()) {
            return response()->json([
                'success' => false,
                'message' => 'Order is not payable.',
            ], 422);
        }

        if ((string) $order->currency !== 'JPY') {
            return response()->json([
                'success' => false,
                'message' => 'CBT payment is only available for JPY orders.',
            ], 422);
        }

        if (! $this->requestMatchesOrderBuyer($request, $order)) {
            return response()->json([
                'success' => false,
                'message' => 'Order buyer verification failed.',
            ], 403);
        }

        $expectedPrice = (int) round((float) $order->total_due_amount);
        if ($price !== $expectedPrice) {
            return response()->json([
                'success' => false,
                'message' => 'Payment amount does not match the order amount.',
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

    private function rateLimitKey(Request $request, string $oid): string
    {
        return 'sirsoft-pay_kginicis:cbt-hash:' . sha1($request->ip() . '|' . $oid);
    }

    private function requestMatchesOrderBuyer(Request $request, Order $order): bool
    {
        /** @var OrderAddress|null $address */
        $address = $order->shippingAddress;
        if (! $address) {
            return true;
        }

        $expectedEmail = strtolower(trim((string) $address->orderer_email));
        if ($expectedEmail !== '') {
            $receivedEmail = strtolower(trim((string) $request->input('buyer_email', '')));
            if ($receivedEmail === '' || $receivedEmail !== $expectedEmail) {
                return false;
            }
        }

        $expectedPhone = $this->digitsOnly((string) $address->orderer_phone);
        if ($expectedPhone !== '') {
            $receivedPhone = $this->digitsOnly((string) $request->input('buyer_phone', ''));
            if ($receivedPhone === '' || $receivedPhone !== $expectedPhone) {
                return false;
            }
        }

        return true;
    }

    private function digitsOnly(string $value): string
    {
        return preg_replace('/[^0-9]/', '', $value) ?? '';
    }
}
