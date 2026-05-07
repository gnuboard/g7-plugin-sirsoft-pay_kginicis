<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use App\Extension\HookManager;
use App\Services\PluginSettingsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Exceptions\PaymentAmountMismatchException;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayKginicis\Http\Requests\AuthCallbackRequest;
use Plugins\Sirsoft\PayKginicis\Http\Requests\MobileVbankNotifyRequest;
use Plugins\Sirsoft\PayKginicis\Http\Requests\VbankNotifyRequest;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;

/**
 * KG 이니시스 결제 콜백 컨트롤러
 *
 * KG 이니시스 표준결제창은 2단계 인증 방식입니다:
 *  1단계: 브라우저가 POST 콜백으로 authToken + authUrl 전달 → authCallback()
 *  2단계: 서버가 authUrl로 최종 승인 요청 → KgInicisApiService::authorizePayment()
 */
class PaymentCallbackController
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_kginicis';

    public function __construct(
        private readonly OrderProcessingService $orderService,
        private readonly PluginSettingsService $pluginSettingsService,
        private readonly KgInicisApiService $apiService,
    ) {}

    /**
     * authCallback
     *
     * @param  AuthCallbackRequest  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function authCallback(AuthCallbackRequest $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validated();

        $resultCode = $validated['resultCode'];

        // 주문번호: 구버전 MOID, 신버전 orderNumber 모두 지원
        $moid = $validated['MOID'] ?? $validated['orderNumber'] ?? null;

        // 결제금액: 콜백에 없을 수 있음 → 서버 승인 후 PG 응답에서 가져옴
        $totPrice = isset($validated['TotPrice']) ? (int) $validated['TotPrice'] : null;

        Log::info('KG Inicis: callback received', [
            'moid'        => $moid,
            'result_code' => $resultCode,
            'idc_name'    => $validated['idc_name'] ?? null,
            'auth_url'    => $validated['authUrl'] ?? null,
            'all_fields'  => array_keys($request->all()),
        ]);

        if (! $moid) {
            Log::error('KG Inicis: order number missing from callback', ['input' => array_keys($request->all())]);

            return redirect('/');
        }

        // 결제 실패인 경우: authToken/authUrl 없이 올 수 있으므로 먼저 처리
        if ($resultCode !== '0000') {
            Log::warning('KG Inicis: auth result failed', [
                'moid'        => $moid,
                'result_code' => $resultCode,
                'result_msg'  => $validated['resultMsg'] ?? '',
            ]);

            return redirect($this->resolveFailUrl([
                'error'   => $resultCode,
                'message' => $validated['resultMsg'] ?? '',
                'orderId' => $moid,
            ]));
        }

        // 결제 성공(0000) 이후: authToken, authUrl, idc_name 필수
        $authToken = $validated['authToken'] ?? null;
        $idcName = $validated['idc_name'] ?? null;
        // authUrl 또는 checkAckUrl (버전에 따라 다름)
        $receivedAuthUrl = $validated['authUrl'] ?? $validated['checkAckUrl'] ?? null;
        $receivedNetCancelUrl = $validated['netCancelUrl'] ?? null;

        if (! $authToken || ! $idcName || ! $receivedAuthUrl) {
            Log::error('KG Inicis: missing required fields on success callback', [
                'moid'      => $moid,
                'idc_name'  => $idcName,
                'auth_url'  => $receivedAuthUrl,
                'has_token' => (bool) $authToken,
            ]);

            return redirect($this->resolveFailUrl(['error' => 'missing_fields', 'orderId' => $moid]));
        }

        // idc_name + authUrl 화이트리스트 검증 (PC/모바일 URL 모두 허용, SSRF 방어)
        if (! $this->apiService->isValidIdcAuthUrl($idcName, $receivedAuthUrl)) {
            Log::error('KG Inicis: authUrl not in whitelist (possible SSRF attempt)', [
                'moid'     => $moid,
                'idc_name' => $idcName,
                'received' => $receivedAuthUrl,
            ]);

            return redirect($this->resolveFailUrl(['error' => 'auth_url_invalid', 'orderId' => $moid]));
        }

        // 화이트리스트 검증 통과 → 수신된 URL을 그대로 사용 (PC/모바일 자동 대응)
        $authUrl = $receivedAuthUrl;
        $netCancelUrl = $this->apiService->resolveIdcNetCancelUrl($idcName);

        try {
            $order = $this->orderService->findByOrderNumber($moid);

            if (! $order) {
                Log::error('KG Inicis: order not found', ['moid' => $moid]);

                return redirect($this->resolveFailUrl(['error' => 'order_not_found', 'orderId' => $moid]));
            }

            HookManager::doAction('sirsoft-pay_kginicis.payment.before_authorize', $order, $validated);

            $pgResponse = $this->apiService->authorizePayment($authUrl, $authToken);

            HookManager::doAction('sirsoft-pay_kginicis.payment.after_authorize', $order, $pgResponse);

            $pgResultCode = $pgResponse['resultCode'] ?? '';

            if ($pgResultCode !== '0000') {
                Log::warning('KG Inicis: authorize failed', [
                    'moid' => $moid,
                    'result_code' => $pgResultCode,
                    'result_msg' => $pgResponse['resultMsg'] ?? '',
                ]);

                $this->orderService->failPayment($order, $pgResultCode, $pgResponse['resultMsg'] ?? '');

                return redirect($this->resolveFailUrl([
                    'error' => $pgResultCode,
                    'message' => $pgResponse['resultMsg'] ?? '',
                    'orderId' => $moid,
                ]));
            }

            $tid = $pgResponse['tid'] ?? '';

            // TotPrice 가 콜백에 없으면 PG 승인 응답의 TotPrice 사용
            if ($totPrice === null) {
                $totPrice = (int) ($pgResponse['TotPrice'] ?? $pgResponse['totPrice'] ?? 0);
            }

            $this->orderService->completePayment($order, [
                'transaction_id' => $tid,
                'card_approval_number' => $pgResponse['applNum'] ?? null,
                'card_number_masked' => $pgResponse['cardNum'] ?? $pgResponse['vbankNum'] ?? null,
                'card_name' => $pgResponse['cardName'] ?? $pgResponse['vbankName'] ?? null,
                'card_installment_months' => (int) ($pgResponse['cardQuota'] ?? 0),
                'is_interest_free' => false,
                'embedded_pg_provider' => null,
                'receipt_url' => null,
                'payment_meta' => [
                    'result_code' => $pgResultCode,
                    'pay_method' => $pgResponse['payMethod'] ?? null,
                    'auth_date' => $pgResponse['applDate'] ?? null,
                    'vbank_num' => $pgResponse['vbankNum'] ?? null,
                    'vbank_name' => $pgResponse['vbankName'] ?? null,
                    'vbank_exp_date' => $pgResponse['vbankExpDate'] ?? null,
                    'pg_raw_response' => $pgResponse,
                ],
                'payment_device' => $this->detectDevice($request),
            ], $totPrice);

            return redirect($this->resolveSuccessUrl($moid));

        } catch (PaymentAmountMismatchException $e) {
            Log::error('KG Inicis: amount mismatch', [
                'moid' => $moid,
                'expected' => $e->getExpectedAmount(),
                'actual' => $e->getActualAmount(),
            ]);

            $this->apiService->sendNetCancel($netCancelUrl, $authToken);

            return redirect($this->resolveFailUrl(['error' => 'amount_mismatch', 'orderId' => $moid]));

        } catch (\Exception $e) {
            Log::error('KG Inicis: authorize exception', [
                'moid' => $moid,
                'error' => $e->getMessage(),
            ]);

            $this->apiService->sendNetCancel($netCancelUrl, $authToken);

            return redirect($this->resolveFailUrl([
                'error' => 'authorize_failed',
                'message' => $e->getMessage(),
                'orderId' => $moid,
            ]));
        }
    }

    /**
     * vbankNotify
     *
     * @param  VbankNotifyRequest  $request
     * @return Response
     */
    public function vbankNotify(VbankNotifyRequest $request): Response
    {
        $validated = $request->validated();

        $tid  = (string) $validated['no_tid'];
        $moid = (string) $validated['no_oid'];
        $amt  = (int) $validated['amt_input'];

        Log::info('KG Inicis: PC vbank deposit notify received', [
            'tid'      => $tid,
            'moid'     => $moid,
            'amt'      => $amt,
            'bank'     => $validated['nm_inputbank'] ?? null,
            'depositor' => $validated['nm_input'] ?? null,
        ]);

        try {
            $order = $this->orderService->findByOrderNumber($moid);

            if (! $order) {
                Log::error('KG Inicis: PC vbank notify - order not found', ['moid' => $moid, 'tid' => $tid]);

                return response('FAIL', 200)->header('Content-Type', 'text/plain');
            }

            $this->orderService->completePayment($order, [
                'transaction_id' => $tid,
                'payment_meta'   => [
                    'vbank_num'       => $validated['no_vacct'] ?? null,
                    'vbank_name'      => $validated['nm_inputbank'] ?? null,
                    'depositor_name'  => $validated['nm_input'] ?? null,
                    'deposit_date'    => ($validated['dt_trans'] ?? '') . ($validated['tm_trans'] ?? ''),
                    'bank_code'       => $validated['cd_bank'] ?? null,
                    'pg_raw_response' => $validated,
                ],
            ], $amt);

            Log::info('KG Inicis: PC vbank deposit confirmed', ['tid' => $tid, 'moid' => $moid, 'amt' => $amt]);

            return response('OK', 200)->header('Content-Type', 'text/plain');

        } catch (\Exception $e) {
            Log::error('KG Inicis: PC vbank notify failed', [
                'tid'   => $tid,
                'moid'  => $moid,
                'error' => $e->getMessage(),
            ]);

            return response('FAIL', 200)->header('Content-Type', 'text/plain');
        }
    }

    /**
     * mobileVbankNotify
     *
     * @param  MobileVbankNotifyRequest  $request
     * @return Response
     */
    public function mobileVbankNotify(MobileVbankNotifyRequest $request): Response
    {
        $validated = $request->validated();

        $pStatus = (string) $validated['P_STATUS'];
        $pType   = (string) $validated['P_TYPE'];
        $tid     = (string) $validated['P_TID'];
        $moid    = (string) $validated['P_OID'];
        $amt     = (int) $validated['P_AMT'];

        // P_STATUS == "02" (입금통보) + P_TYPE == "VBANK" 만 처리
        if ($pStatus !== '02' || $pType !== 'VBANK') {
            Log::info('KG Inicis: mobile vbank notify - not a deposit, ignored', [
                'tid'      => $tid,
                'P_STATUS' => $pStatus,
                'P_TYPE'   => $pType,
            ]);

            return response('OK', 200)->header('Content-Type', 'text/plain');
        }

        Log::info('KG Inicis: mobile vbank deposit notify received', [
            'tid'      => $tid,
            'moid'     => $moid,
            'amt'      => $amt,
            'bank'     => $validated['P_FN_NM'] ?? null,
            'depositor' => $validated['P_UNAME'] ?? null,
        ]);

        try {
            $order = $this->orderService->findByOrderNumber($moid);

            if (! $order) {
                Log::error('KG Inicis: mobile vbank notify - order not found', ['moid' => $moid, 'tid' => $tid]);

                return response('FAIL', 200)->header('Content-Type', 'text/plain');
            }

            $this->orderService->completePayment($order, [
                'transaction_id' => $tid,
                'payment_meta'   => [
                    'vbank_name'      => $validated['P_FN_NM'] ?? null,
                    'depositor_name'  => $validated['P_UNAME'] ?? null,
                    'deposit_date'    => $validated['P_AUTH_DT'] ?? null,
                    'bank_code'       => $validated['P_FN_CD1'] ?? null,
                    'pg_raw_response' => $validated,
                ],
                'payment_device' => 'mobile',
            ], $amt);

            Log::info('KG Inicis: mobile vbank deposit confirmed', ['tid' => $tid, 'moid' => $moid, 'amt' => $amt]);

            return response('OK', 200)->header('Content-Type', 'text/plain');

        } catch (\Exception $e) {
            Log::error('KG Inicis: mobile vbank notify failed', [
                'tid'   => $tid,
                'moid'  => $moid,
                'error' => $e->getMessage(),
            ]);

            return response('FAIL', 200)->header('Content-Type', 'text/plain');
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

    private function detectDevice(Request $request): string
    {
        $userAgent = $request->userAgent() ?? '';
        $mobileKeywords = ['Mobile', 'Android', 'iPhone', 'iPad', 'iPod'];

        foreach ($mobileKeywords as $keyword) {
            if (stripos($userAgent, $keyword) !== false) {
                return 'mobile';
            }
        }

        return 'pc';
    }
}
