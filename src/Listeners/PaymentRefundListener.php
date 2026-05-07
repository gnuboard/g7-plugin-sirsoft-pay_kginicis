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

            $isPartial = $cancelAmt < (int) $payment->amount;
            $response = $apiService->cancelPayment(
                $tid,
                $payMethod,
                $isPartial ? $cancelAmt : null,
                $cancelMsg,
                $isPartial ? (int) $payment->amount : null,
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
                'transaction_id' => $response['tid'] ?? $tid,
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
}
