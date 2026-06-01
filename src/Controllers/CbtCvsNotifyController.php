<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayKginicis\Concerns\PreventsReplayCallback;
use Plugins\Sirsoft\PayKginicis\Concerns\SanitizesPgResponse;
use Plugins\Sirsoft\PayKginicis\Http\Requests\CbtCvsNotifyRequest;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;

/**
 * KG 이니시스 CBT 편의점 입금 NOTI 수신 컨트롤러.
 */
class CbtCvsNotifyController
{
    use PreventsReplayCallback;
    use SanitizesPgResponse;

    private const NOTIFY_RESPONSE_KEYS = [
        'tid',
        'mid',
        'applDt',
        'applTm',
        'status',
        'payNm',
        'orderId',
        'applNo',
        'sid',
        'convenience',
        'confNo',
        'receiptNo',
        'paymentTerm',
        'amount',
        'currencyCd',
    ];

    public function __construct(
        private readonly OrderProcessingService $orderService,
        private readonly KgInicisApiService $apiService,
    ) {}

    /**
     * KG 이니시스 CBT 편의점 입금 통보를 수신한다.
     *
     * @param  CbtCvsNotifyRequest  $request
     * @return Response
     */
    public function handle(CbtCvsNotifyRequest $request): Response
    {
        $payload = $request->all();
        $tid = trim((string) ($payload['tid'] ?? ''));
        $orderId = trim((string) ($payload['orderId'] ?? ''));
        $mid = trim((string) ($payload['mid'] ?? ''));
        $status = preg_replace('/\s+/', '', (string) ($payload['status'] ?? ''));
        $amount = (int) ($payload['amount'] ?? 0);

        if ($tid === '' || $orderId === '' || $mid === '' || $amount <= 0) {
            Log::warning('KG Inicis CBT CVS: invalid notify payload', [
                'tid' => $tid,
                'order_id' => $orderId,
                'mid' => $mid,
                'amount' => $payload['amount'] ?? null,
                'keys' => array_keys($payload),
            ]);

            return $this->plain('FAIL');
        }

        if ($status !== '00') {
            Log::info('KG Inicis CBT CVS: non-success notify ignored', [
                'tid' => $tid,
                'order_id' => $orderId,
                'status' => $status,
            ]);

            return $this->plain('OK');
        }

        try {
            $order = $this->orderService->findByOrderNumber($orderId);
            if (! $order) {
                Log::error('KG Inicis CBT CVS: order not found', ['order_id' => $orderId, 'tid' => $tid]);

                return $this->plain('FAIL');
            }

            if ($this->wasAlreadyPaid($tid)) {
                $this->logReplayDetected($tid, $orderId, 'CBT CVS notify');

                return $this->plain('OK');
            }

            $payment = $order->payment()->first();
            $existingMeta = is_array($payment?->payment_meta) ? $payment->payment_meta : [];
            $expectedMid = (string) ($existingMeta['cbt_mid'] ?? $this->apiService->getJapanMid());

            if (! $payment) {
                Log::warning('KG Inicis CBT CVS: payment row not found', [
                    'order_id' => $orderId,
                    'tid' => $tid,
                ]);

                return $this->plain('FAIL');
            }

            if (! $this->paymentStatusEquals($payment->payment_status, PaymentStatusEnum::WAITING_DEPOSIT)) {
                Log::warning('KG Inicis CBT CVS: payment status is not waiting_deposit', [
                    'order_id' => $orderId,
                    'tid' => $tid,
                    'payment_status' => $this->paymentStatusValue($payment->payment_status),
                ]);

                return $this->plain('FAIL');
            }

            $expectedPayMethod = strtoupper((string) ($existingMeta['pay_method'] ?? ''));
            if ($expectedPayMethod !== 'CVS') {
                Log::warning('KG Inicis CBT CVS: existing payment method is not CVS', [
                    'order_id' => $orderId,
                    'tid' => $tid,
                    'pay_method' => $existingMeta['pay_method'] ?? null,
                ]);

                return $this->plain('FAIL');
            }

            $expectedSid = trim((string) ($existingMeta['cbt_sid'] ?? ''));
            $receivedSid = trim((string) ($payload['sid'] ?? ''));
            if ($expectedSid !== '' && $receivedSid !== $expectedSid) {
                Log::warning('KG Inicis CBT CVS: sid mismatch', [
                    'order_id' => $orderId,
                    'tid' => $tid,
                    'received_sid' => $receivedSid,
                    'expected_sid' => $expectedSid,
                ]);

                return $this->plain('FAIL');
            }

            $currency = strtoupper(trim((string) ($payload['currencyCd'] ?? '')));
            if ($currency !== 'JPY') {
                Log::warning('KG Inicis CBT CVS: currency mismatch', [
                    'order_id' => $orderId,
                    'tid' => $tid,
                    'currency' => $payload['currencyCd'] ?? null,
                ]);

                return $this->plain('FAIL');
            }

            $expectedAmount = $this->resolveExpectedCvsAmount($order, $existingMeta);
            if ($expectedAmount > 0 && $amount !== $expectedAmount) {
                Log::warning('KG Inicis CBT CVS: amount mismatch', [
                    'order_id' => $orderId,
                    'tid' => $tid,
                    'received_amount' => $amount,
                    'expected_amount' => $expectedAmount,
                ]);

                return $this->plain('FAIL');
            }

            if ($expectedMid !== '' && $mid !== $expectedMid) {
                Log::warning('KG Inicis CBT CVS: MID mismatch', [
                    'order_id' => $orderId,
                    'tid' => $tid,
                    'received_mid' => $mid,
                    'expected_mid' => $expectedMid,
                ]);

                return $this->plain('FAIL');
            }

            $sanitized = $this->sanitizePgResponse($payload, self::NOTIFY_RESPONSE_KEYS);

            $this->orderService->completePayment($order, [
                'transaction_id' => $tid,
                'payment_meta' => array_merge($existingMeta, [
                    'result_code' => $status,
                    'pay_method' => 'CVS',
                    'auth_date' => ($payload['applDt'] ?? '') . ($payload['applTm'] ?? ''),
                    'mid' => $mid,
                    'currency' => $payload['currencyCd'] ?? 'JPY',
                    'is_cbt' => true,
                    'cbt_type' => 'JPPG',
                    'cbt_mid' => $mid,
                    'cbt_sid' => $payload['sid'] ?? ($existingMeta['cbt_sid'] ?? null),
                    'is_test_mode' => $existingMeta['is_test_mode'] ?? $this->apiService->isTestMode(),
                    'pg_response_sanitized' => true,
                    'pg_cvs_notify_response' => $sanitized,
                    'pg_raw_response' => $sanitized,
                    'cvs_status' => 'paid',
                    'cvs_convenience' => $payload['convenience'] ?? null,
                    'cvs_conf_no' => $payload['confNo'] ?? null,
                    'cvs_receipt_no' => $payload['receiptNo'] ?? null,
                    'cvs_payment_term' => $payload['paymentTerm'] ?? null,
                ]),
            ], $amount);

            $order->payment()->update(['pg_provider' => 'kginicis']);

            Log::info('KG Inicis CBT CVS: deposit confirmed', [
                'order_id' => $orderId,
                'tid' => $tid,
                'amount' => $amount,
            ]);

            return $this->plain('OK');
        } catch (\Throwable $e) {
            Log::error('KG Inicis CBT CVS: notify failed', [
                'order_id' => $orderId,
                'tid' => $tid,
                'error' => $e->getMessage(),
            ]);

            return $this->plain('FAIL');
        }
    }

    private function plain(string $body): Response
    {
        return response($body, 200)->header('Content-Type', 'text/plain');
    }

    private function resolveExpectedCvsAmount(Order $order, array $paymentMeta): int
    {
        $metaAmount = (int) ($paymentMeta['cvs_amount'] ?? 0);
        if ($metaAmount > 0) {
            return $metaAmount;
        }

        return (int) round((float) $order->total_due_amount);
    }

    private function paymentStatusEquals(mixed $status, PaymentStatusEnum $expected): bool
    {
        return $this->paymentStatusValue($status) === $expected->value;
    }

    private function paymentStatusValue(mixed $status): string
    {
        if ($status instanceof PaymentStatusEnum) {
            return $status->value;
        }

        return (string) $status;
    }
}
