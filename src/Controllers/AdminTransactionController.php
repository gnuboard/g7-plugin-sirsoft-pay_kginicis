<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;

class AdminTransactionController extends AdminBaseController
{
    public function __construct(
        private readonly KgInicisApiService $apiService,
    ) {
        parent::__construct();
    }

/**

 * query

 *

 * @param  Request  $request

 * @return JsonResponse

 */

    public function query(Request $request): JsonResponse
    {
        $tid = trim((string) $request->input('tid', ''));

        if ($tid === '') {
            return ResponseHelper::error('messages.failed', 422, ['tid' => ['TID를 입력하세요.']]);
        }

        return $this->queryByTid($tid);
    }

/**

 * queryByOrder

 *

 * @param  string  $orderNumber

 * @return JsonResponse

 */

    public function queryByOrder(string $orderNumber): JsonResponse
    {
        $payment = DB::table('ecommerce_order_payments')
            ->join('ecommerce_orders', 'ecommerce_orders.id', '=', 'ecommerce_order_payments.order_id')
            ->where('ecommerce_orders.order_number', $orderNumber)
            ->whereNotNull('ecommerce_order_payments.transaction_id')
            ->where('ecommerce_order_payments.transaction_id', '!=', '')
            ->where('ecommerce_order_payments.pg_provider', 'kginicis')
            ->select(['ecommerce_order_payments.transaction_id', 'ecommerce_order_payments.payment_meta'])
            ->first();

        if (! $payment) {
            return ResponseHelper::success('messages.success', null);
        }

        return $this->queryByTid($payment->transaction_id);
    }

    private function queryByTid(string $tid): JsonResponse
    {
        try {
            $localPayment = DB::table('ecommerce_order_payments')
                ->where('transaction_id', $tid)
                ->select(['is_escrow', 'payment_meta'])
                ->first();

            $this->apiService->useEscrowCredentials((bool) ($localPayment?->is_escrow ?? false));

            $result = $this->apiService->queryTransaction($tid);

            $result['_is_test_mode'] = $this->apiService->isTestMode();
            $result['_local_is_escrow'] = (bool) ($localPayment?->is_escrow ?? false);

            if ($localPayment?->payment_meta) {
                $meta = json_decode($localPayment->payment_meta, true);
                $rawResponse = $meta['pg_raw_response'] ?? [];
                $result['_auth_code'] = $rawResponse['applNum'] ?? $rawResponse['authCode'] ?? null;
                $result['_pay_method'] = $rawResponse['payMethod'] ?? null;
                $result['_auth_date'] = $rawResponse['applDate'] ?? null;
            }

            return ResponseHelper::success('messages.success', $result);
        } catch (\Exception $e) {
            Log::error('KG Inicis queryTransaction failed', [
                'tid'   => $tid,
                'error' => $e->getMessage(),
            ]);

            return ResponseHelper::error('messages.failed', 502, null);
        }
    }
}
