<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use App\Services\PluginSettingsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\PaymentAmountMismatchException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayKginicis\Http\Requests\MobileCallbackRequest;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;

/**
 * KG 이니시스 모바일 결제 콜백 컨트롤러
 *
 * 모바일 결제 흐름:
 *  1단계: 프론트엔드가 https://mobile.inicis.com/smart/payment/ 로 폼 제출 (페이지 이동)
 *  2단계: KG 이니시스 인증 후 P_NEXT_URL(이 컨트롤러)로 POST 콜백 (manual.inicis.com/pay/stdpay_m.html)
 *  3단계: 서버가 P_REQ_URL로 서버 승인 요청 (P_MID + P_TID)
 *  4단계: 승인 응답(URL-encoded) 파싱 후 주문 완료 처리 (가상계좌는 발급 정보만 저장)
 */
class MobileCallbackController
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
     * @param  MobileCallbackRequest  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function handle(MobileCallbackRequest $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validated();

        $pStatus   = $validated['P_STATUS'];
        $moid      = $validated['P_OID'] ?? null;
        $pAmt      = $validated['P_AMT'] ?? null;

        Log::info('KG Inicis mobile: callback received', [
            'P_OID'     => $moid,
            'P_STATUS'  => $pStatus,
            'idc_name'  => $validated['idc_name'] ?? null,
            'P_REQ_URL' => $validated['P_REQ_URL'] ?? null,
        ]);

        if (! $moid) {
            Log::error('KG Inicis mobile: P_OID missing from callback');

            return redirect('/');
        }

        // 인증 실패
        if ($pStatus !== '00') {
            Log::warning('KG Inicis mobile: auth failed', [
                'P_OID'    => $moid,
                'P_STATUS' => $pStatus,
                'P_RMESG1' => $validated['P_RMESG1'] ?? '',
            ]);

            return redirect($this->resolveFailUrl([
                'error'   => $pStatus,
                'message' => $validated['P_RMESG1'] ?? '',
                'orderId' => $moid,
            ]));
        }

        $pTid    = $validated['P_TID'] ?? null;
        $idcName = $validated['idc_name'] ?? null;
        $reqUrl  = $validated['P_REQ_URL'] ?? null;

        if (! $pTid || ! $idcName || ! $reqUrl) {
            Log::error('KG Inicis mobile: missing required fields', [
                'P_OID'    => $moid,
                'idc_name' => $idcName,
                'P_TID'    => $pTid,
                'P_REQ_URL' => $reqUrl,
            ]);

            return redirect($this->resolveFailUrl(['error' => 'missing_fields', 'orderId' => $moid]));
        }

        // P_REQ_URL 화이트리스트 검증 (모바일 IDC URL, SSRF 방어)
        if (! $this->apiService->isValidIdcAuthUrl($idcName, $reqUrl)) {
            Log::error('KG Inicis mobile: P_REQ_URL not in whitelist (possible SSRF attempt)', [
                'P_OID'    => $moid,
                'idc_name' => $idcName,
                'received' => $reqUrl,
            ]);

            return redirect($this->resolveFailUrl(['error' => 'req_url_invalid', 'orderId' => $moid]));
        }

        try {
            $order = $this->orderService->findByOrderNumber($moid);

            if (! $order) {
                Log::error('KG Inicis mobile: order not found', ['P_OID' => $moid]);

                return redirect($this->resolveFailUrl(['error' => 'order_not_found', 'orderId' => $moid]));
            }

            // 서버 승인 요청: POST P_REQ_URL with P_MID + P_TID
            $result = $this->apiService->authorizeMobilePayment($reqUrl, $pTid);

            $resultStatus = $result['P_STATUS'] ?? '';

            if ($resultStatus !== '00') {
                Log::warning('KG Inicis mobile: server approve failed', [
                    'P_OID'    => $moid,
                    'P_STATUS' => $resultStatus,
                    'P_RMESG1' => $result['P_RMESG1'] ?? '',
                ]);

                $this->orderService->failPayment($order, $resultStatus, $result['P_RMESG1'] ?? '');

                return redirect($this->resolveFailUrl([
                    'error'   => $resultStatus,
                    'message' => $result['P_RMESG1'] ?? '',
                    'orderId' => $moid,
                ]));
            }

            $tid      = $result['P_TID'] ?? $pTid;
            $totPrice = (int) ($result['P_AMT'] ?? $pAmt ?? 0);
            $payType  = (string) ($result['P_TYPE'] ?? '');

            // 가상계좌: completePayment 없이 발급 정보만 저장 (입금 통보 시점에 completePayment)
            if (strcasecmp($payType, 'VBank') === 0) {
                $this->handleVbankIssued($order, $result, $tid);

                return redirect($this->resolveSuccessUrl($moid));
            }

            $this->orderService->completePayment($order, [
                'transaction_id'          => $tid,
                'card_approval_number'    => $result['P_APPL_NUM'] ?? null,
                'card_number_masked'      => $result['P_CARD_NUM'] ?? null,
                'card_name'               => $result['P_CARD_ISSUER_NAME'] ?? null,
                'card_installment_months' => (int) ($result['P_CARD_QUOTA'] ?? 0),
                'is_interest_free'        => false,
                'embedded_pg_provider'    => null,
                'receipt_url'             => null,
                'payment_meta'            => [
                    'result_code'    => $resultStatus,
                    'pay_method'     => $payType ?: null,
                    'auth_date'      => $result['P_AUTH_DT'] ?? null,
                    'pg_raw_response' => $result,
                ],
                'payment_device'          => 'mobile',
            ], $totPrice);

            $order->payment()->update(['pg_provider' => 'kginicis']);

            return redirect($this->resolveSuccessUrl($moid));

        } catch (PaymentAmountMismatchException $e) {
            Log::error('KG Inicis mobile: amount mismatch', [
                'P_OID'    => $moid,
                'expected' => $e->getExpectedAmount(),
                'actual'   => $e->getActualAmount(),
            ]);

            return redirect($this->resolveFailUrl(['error' => 'amount_mismatch', 'orderId' => $moid]));

        } catch (\Exception $e) {
            Log::error('KG Inicis mobile: approve exception', [
                'P_OID' => $moid,
                'error' => $e->getMessage(),
            ]);

            return redirect($this->resolveFailUrl([
                'error'   => 'approve_failed',
                'message' => $e->getMessage(),
                'orderId' => $moid,
            ]));
        }
    }

    /**
     * 모바일 가상계좌 발급 처리 (completePayment 없이 계좌 정보만 저장)
     *
     * KG 이니시스 모바일 승인 응답에서 P_TYPE=VBank 로 판별된 경우 호출.
     * 실제 결제 완료(completePayment)는 입금 통보(mobileVbankNotify) 시점에 처리.
     * 응답 필드는 manual.inicis.com/pay/stdpay_m.html 표준에 따른 P_VACT_* 형식.
     */
    private function handleVbankIssued(Order $order, array $result, string $tid): void
    {
        $vactDate = $result['P_VACT_DATE'] ?? null;
        $vactTime = $result['P_VACT_TIME'] ?? '235959';
        $vbankDueAt = null;

        if ($vactDate && strlen((string) $vactDate) === 8) {
            try {
                $vbankDueAt = Carbon::createFromFormat('YmdHis', $vactDate . $vactTime);
            } catch (\Exception) {
                $vbankDueAt = null;
            }
        }

        $order->payment()->update(array_filter([
            'pg_provider'     => 'kginicis',
            'payment_status'  => PaymentStatusEnum::WAITING_DEPOSIT,
            'transaction_id'  => $tid ?: null,
            'vbank_name'      => $result['P_VACT_BANK_NAME'] ?? $result['P_FN_NM'] ?? null,
            'vbank_number'    => $result['P_VACT_NUM'] ?? null,
            'vbank_holder'    => $result['P_VACT_NAME'] ?? $result['P_RVACTNM'] ?? null,
            'vbank_due_at'    => $vbankDueAt,
            'vbank_issued_at' => now(),
            'payment_device'  => 'mobile',
            'payment_meta'    => [
                'result_code'     => $result['P_STATUS'] ?? '00',
                'pay_method'      => 'VBank',
                'auth_date'       => $result['P_AUTH_DT'] ?? null,
                'pg_raw_response' => $result,
            ],
        ], fn ($v) => $v !== null));

        Log::info('KG Inicis mobile: vbank account issued', [
            'P_OID'        => $order->order_number,
            'P_TID'        => $tid,
            'vbank_name'   => $result['P_VACT_BANK_NAME'] ?? $result['P_FN_NM'] ?? null,
            'vbank_number' => $result['P_VACT_NUM'] ?? null,
            'vbank_due_at' => $vbankDueAt?->toDateTimeString(),
        ]);
    }

    private function resolveSuccessUrl(string $orderId): string
    {
        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $urlTemplate = $settings['redirect_success_url'] ?? '/shop/orders/{orderId}/complete';

        return $this->absolutize(str_replace('{orderId}', $orderId, $urlTemplate));
    }

    private function resolveFailUrl(array $queryParams = []): string
    {
        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $baseUrl = $this->absolutize($settings['redirect_fail_url'] ?? '/shop/checkout');

        if (empty($queryParams)) {
            return $baseUrl;
        }

        $query = http_build_query(array_filter($queryParams, fn ($v) => $v !== null && $v !== ''));
        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        return $baseUrl . $separator . $query;
    }

    /**
     * 상대 경로면 APP_URL 기준으로 절대 URL 화.
     *
     * PG가 브라우저 POST 로 콜백을 보내는 동안 Apache 가 ProxyPreserveHost Off 등
     * 으로 Host 헤더를 localhost 로 바꿔서 PHP 에 전달하는 경우, Laravel 의
     * redirect('/path') 가 http://localhost/path 를 생성해버린다. config('app.url')
     * (.env 의 APP_URL)을 명시적 base 로 사용하여 도메인을 보존한다.
     */
    private function absolutize(string $url): string
    {
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        $base = rtrim((string) config('app.url'), '/');
        $path = $url === '' ? '/' : ($url[0] === '/' ? $url : '/' . $url);

        return $base . $path;
    }
}
