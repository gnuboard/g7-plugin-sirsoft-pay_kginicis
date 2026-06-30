<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;

class PaymentRefundListener implements HookListenerInterface
{
    private const PG_PROVIDER_ID = 'kginicis';

/**

 * getSubscribedHooks

 *

 * @return array

 */

    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.payment.refund' => [
                'method' => 'processRefund',
                'type' => 'filter',
                'priority' => 10,
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용 — 개별 메서드에서 처리)
     *
     * @param  mixed  ...$args  훅 인수
     */
    public function handle(...$args): void {}

/**

 * processRefund

 *

 * @param  array  $result

 * @param  Order  $order

 * @param  OrderPayment  $payment

 * @param  float  $refundAmount

 * @param  ?string  $reason

 * @return array

 */

    public function processRefund(
        array $result,
        Order $order,
        OrderPayment $payment,
        float $refundAmount,
        ?string $reason = null,
    ): array {
        if ($payment->pg_provider !== self::PG_PROVIDER_ID) {
            return $result;
        }

        $tid = $payment->transaction_id;
        if (! $tid) {
            return [
                'success' => false,
                'error_code' => 'MISSING_TID',
                'error_message' => __('sirsoft-pay_kginicis::messages.refund.missing_tid'),
                'transaction_id' => null,
            ];
        }

        try {
            $apiService = app(KgInicisApiService::class);

            $cancelMsg = $reason ?? __('sirsoft-pay_kginicis::messages.refund.default_reason');
            $cancelAmt = (int) $refundAmount;
            $payMethod = $payment->payment_meta['pay_method'] ?? 'Card';

            $paidAmount = (int) $payment->paid_amount_local;
            $previousCancelled = max(0, (int) $payment->cancelled_amount - $cancelAmt);
            $totalAmount = max($cancelAmt, $paidAmount - $previousCancelled);
            $isPartial = $previousCancelled > 0 || $cancelAmt < $paidAmount;

            $isCbt = $this->isCbtPayment($payment);
            if ($isCbt) {
                $this->useStoredCbtCredentials($apiService, $payment);
            } else {
                $this->useStoredStandardCredentials($apiService, $payment, $tid);
            }

            $response = $isCbt
                ? $apiService->refundCbtPayment(
                    $tid,
                    $isPartial ? $cancelAmt : null,
                    $cancelMsg,
                    $isPartial ? $totalAmount : null,
                )
                : $apiService->cancelPayment(
                    $tid,
                    $payMethod,
                    $isPartial ? $cancelAmt : null,
                    $cancelMsg,
                    $isPartial ? $totalAmount : null,
                );

            Log::info('KG Inicis: refund success', [
                'order_id' => $order->id,
                'tid' => $tid,
                'cancel_amt' => $cancelAmt,
            ]);

            return [
                'success' => true,
                'error_code' => null,
                'error_message' => null,
                'transaction_id' => $response['refundTid'] ?? ($response['tid'] ?? $tid),
            ];
        } catch (\Exception $e) {
            Log::error('KG Inicis: refund failed', [
                'order_id' => $order->id,
                'tid' => $tid,
                'cancel_amt' => (int) $refundAmount,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error_code' => 'PG_API_ERROR',
                'error_message' => $e->getMessage(),
                'transaction_id' => null,
            ];
        }
    }

    private function isCbtPayment(OrderPayment $payment): bool
    {
        $meta = $payment->payment_meta ?? [];

        return (bool) ($meta['is_cbt'] ?? false)
            || ($meta['cbt_type'] ?? null) !== null
            || strtoupper((string) ($meta['pay_method'] ?? '')) === 'CBT';
    }

    private function useStoredStandardCredentials(KgInicisApiService $apiService, OrderPayment $payment, string $tid): void
    {
        $meta = $payment->payment_meta ?? [];
        $raw = is_array($meta['pg_raw_response'] ?? null) ? $meta['pg_raw_response'] : [];
        $mid = $this->resolvePaymentMid($meta, $raw, $tid);

        if ($mid === null) {
            return;
        }

        $isTestMode = $meta['is_test_mode'] ?? ! str_starts_with($mid, 'SIR');

        $apiService->useStoredCredentials((bool) $isTestMode, $mid);
    }

    /**
     * 결제 시점에 사용된 표준 MID를 payment_meta/raw/TID 순서로 복원한다.
     */
    private function resolvePaymentMid(array $meta, array $raw, string $tid): ?string
    {
        if (! empty($meta['mid']) && is_string($meta['mid'])) {
            return $meta['mid'];
        }

        foreach (['mid', 'MID'] as $key) {
            if (! empty($raw[$key]) && is_string($raw[$key])) {
                return $raw[$key];
            }
        }

        if (strlen($tid) >= 20) {
            $candidate = substr($tid, 10, 10);
            if (preg_match('/^[A-Za-z0-9]{10}$/', $candidate) === 1) {
                return $candidate;
            }
        }

        return null;
    }

    private function useStoredCbtCredentials(KgInicisApiService $apiService, OrderPayment $payment): void
    {
        $meta = $payment->payment_meta ?? [];

        $apiService->useStoredCbtCredentials(
            (bool) ($meta['is_test_mode'] ?? true),
            (string) ($meta['cbt_mid'] ?? ($meta['mid'] ?? '')),
        );
    }
}
