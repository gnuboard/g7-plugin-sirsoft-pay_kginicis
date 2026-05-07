<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use App\Services\PluginSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;

/**
 * KG 이니시스 CBT (Cross Border Trade) 일본 결제 콜백 컨트롤러
 *
 * CBT 결제 흐름:
 *  1. 브라우저가 /cbtauth 로 POST 폼 전송
 *  2. KG 이니시스가 returnUrl 로 sid 를 붙여 리다이렉트 → 이 컨트롤러
 *  3. 서버가 /cbtapprove 로 mid + sid 전송하여 최종 승인
 *  4. 성공 시 주문 완료 처리 후 결제 완료 페이지로 리다이렉트
 */
class CbtCallbackController
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_kginicis';

    public function __construct(
        private readonly OrderProcessingService $orderService,
        private readonly PluginSettingsService $pluginSettingsService,
        private readonly KgInicisApiService $apiService,
    ) {}

    /**
     * handle
     *
     * @param  Request  $request
     * @return RedirectResponse
     */
    public function handle(Request $request): RedirectResponse
    {
        $sid = (string) $request->query('sid', '');
        $oid = (string) $request->query('oid', '');
        $amount = (int) $request->query('amount', 0);

        if ($sid === '' || $oid === '') {
            Log::warning('KG Inicis CBT: missing sid or oid', ['oid' => $oid, 'sid' => $sid]);

            return redirect($this->resolveFailUrl(['error' => 'invalid_params', 'orderId' => $oid]));
        }

        try {
            $order = $this->orderService->findByOrderNumber($oid);

            if (! $order) {
                Log::error('KG Inicis CBT: order not found', ['oid' => $oid]);

                return redirect($this->resolveFailUrl(['error' => 'order_not_found', 'orderId' => $oid]));
            }

            $pgResponse = $this->apiService->approveCbtPayment($sid);

            $resultCode = $pgResponse['resultCode'] ?? ($pgResponse['code'] ?? '');

            if (! in_array($resultCode, ['0000', '00'], true)) {
                $resultMsg = $pgResponse['resultMsg'] ?? ($pgResponse['message'] ?? 'CBT approve failed');
                Log::warning('KG Inicis CBT: approve failed', [
                    'oid' => $oid,
                    'result_code' => $resultCode,
                    'result_msg' => $resultMsg,
                ]);

                $this->orderService->failPayment($order, $resultCode, $resultMsg);

                return redirect($this->resolveFailUrl([
                    'error' => $resultCode,
                    'message' => $resultMsg,
                    'orderId' => $oid,
                ]));
            }

            $tid = $pgResponse['tid'] ?? ($pgResponse['transactionId'] ?? '');

            $this->orderService->completePayment($order, [
                'transaction_id' => $tid,
                'payment_meta' => [
                    'result_code' => $resultCode,
                    'pay_method' => 'CBT',
                    'cbt_type' => $pgResponse['cbtType'] ?? null,
                    'pg_raw_response' => $pgResponse,
                ],
            ], $amount > 0 ? $amount : null);

            Log::info('KG Inicis CBT: payment completed', ['oid' => $oid, 'tid' => $tid]);

            return redirect($this->resolveSuccessUrl($oid));

        } catch (\Exception $e) {
            Log::error('KG Inicis CBT: callback exception', [
                'oid' => $oid,
                'error' => $e->getMessage(),
            ]);

            return redirect($this->resolveFailUrl(['error' => 'cbt_failed', 'orderId' => $oid]));
        }
    }

    private function resolveSuccessUrl(string $orderId): string
    {
        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $urlTemplate = $settings['redirect_success_url'] ?? '/shop/orders/{orderId}/complete';

        return str_replace('{orderId}', $orderId, $urlTemplate);
    }

    private function resolveFailUrl(array $queryParams = []): string
    {
        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $baseUrl = $settings['redirect_fail_url'] ?? '/shop/checkout';

        if (empty($queryParams)) {
            return $baseUrl;
        }

        $query = http_build_query(array_filter($queryParams));
        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        return $baseUrl . $separator . $query;
    }
}
