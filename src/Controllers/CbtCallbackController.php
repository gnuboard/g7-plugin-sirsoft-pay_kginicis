<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use App\Services\PluginSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayKginicis\Concerns\PreventsReplayCallback;
use Plugins\Sirsoft\PayKginicis\Services\CbtReconciliationService;
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
    use PreventsReplayCallback;

    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_kginicis';

    private const CBT_AUTH_RESPONSE_KEYS = [
        'resultCode',
        'resultMsg',
        'orderID',
        'orderId',
        'oid',
        'mid',
        'sid',
        'paymethod',
    ];

    private const CBT_APPROVE_RESPONSE_KEYS = [
        'resultCode',
        'resultMsg',
        'code',
        'message',
        'tid',
        'transactionId',
        'paymethod',
        'payMethod',
        'amount',
        'price',
        'currency',
        'approve',
        'applDate',
        'applTime',
        'installMonth',
        'cardCode',
        'cardName',
        'mid',
        'oid',
        'orderId',
    ];

    private const CBT_REFUND_RESPONSE_KEYS = [
        'resultCode',
        'resultMsg',
        'tid',
        'cancelDate',
        'cancelTime',
        'prtcCode',
    ];

    public function __construct(
        private readonly OrderProcessingService $orderService,
        private readonly PluginSettingsService $pluginSettingsService,
        private readonly KgInicisApiService $apiService,
        private readonly CbtReconciliationService $reconciliationService,
    ) {}

    /**
     * handle
     *
     * @param  Request  $request
     * @return RedirectResponse
     */
    public function handle(Request $request): RedirectResponse
    {
        $sid = (string) $request->input('sid', '');
        $oid = $this->resolveOrderId($request);
        $authResultCode = (string) $request->input('resultCode', '');
        $authResultMsg = (string) $request->input('resultMsg', '');
        $authMid = (string) $request->input('mid', '');

        if ($sid === '' || $oid === '') {
            Log::warning('KG Inicis CBT: missing sid or oid', ['oid' => $oid, 'sid' => $sid]);

            return redirect($this->resolveFailUrl(['error' => 'invalid_params', 'orderId' => $oid]));
        }

        if ($authResultCode !== '' && $authResultCode !== 'OK') {
            $order = $this->orderService->findByOrderNumber($oid);
            if ($order) {
                $this->orderService->failPayment($order, $authResultCode, $authResultMsg);
            }

            Log::warning('KG Inicis CBT: auth failed', [
                'oid' => $oid,
                'result_code' => $authResultCode,
                'result_msg' => $authResultMsg,
            ]);

            return redirect($this->resolveFailUrl([
                'error' => $authResultCode !== '' ? $authResultCode : 'cbt_auth_failed',
                'message' => $authResultMsg,
                'orderId' => $oid,
            ]));
        }

        if ($authMid !== '' && $authMid !== $this->apiService->getJapanMid()) {
            Log::warning('KG Inicis CBT: callback MID mismatch', [
                'oid' => $oid,
                'received_mid' => $authMid,
                'expected_mid' => $this->apiService->getJapanMid(),
            ]);

            return redirect($this->resolveFailUrl(['error' => 'mid_mismatch', 'orderId' => $oid]));
        }

        // Approve 성공 후 후속 처리 실패 시 PG 자동 cancel 추적 변수.
        $approvedTid = null;
        $approvedAmount = 0;

        try {
            $order = $this->orderService->findByOrderNumber($oid);

            if (! $order) {
                Log::error('KG Inicis CBT: order not found', ['oid' => $oid]);

                return redirect($this->resolveFailUrl(['error' => 'order_not_found', 'orderId' => $oid]));
            }

            $this->assertPayableCbtOrder($order);

            $pgResponse = $this->apiService->approveCbtPayment($sid);

            $resultCode = $pgResponse['resultCode'] ?? ($pgResponse['code'] ?? '');

            if (! $this->isCbtSuccessCode((string) $resultCode)) {
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
            if ($tid === '') {
                throw new \RuntimeException('KG Inicis CBT approve response missing tid.');
            }

            // PG 승인이 확정된 직후부터는 어떤 후속 예외라도 자동 취소 대상이다.
            $approvedTid = $tid;

            // Replay 가드: 동일 tid 가 이미 paid 상태면 중복 처리하지 않고 성공 페이지로 복귀
            if ($this->wasAlreadyPaid($tid)) {
                $this->logReplayDetected($tid, $oid, 'CBT authCallback');

                return redirect($this->resolveSuccessUrl($oid));
            }

            $payMethod = (string) ($pgResponse['paymethod'] ?? $request->input('paymethod', 'CBT'));
            $approvedAmount = $this->resolveApprovedAmount($pgResponse, $order);
            $authResponse = $this->sanitizePgResponse($request->except(['_token']), self::CBT_AUTH_RESPONSE_KEYS);
            $approveResponse = $this->sanitizePgResponse($pgResponse, self::CBT_APPROVE_RESPONSE_KEYS);

            $this->orderService->completePayment($order, [
                'transaction_id' => $tid,
                'card_approval_number' => $pgResponse['approve'] ?? null,
                'card_installment_months' => $this->normalizeInstallmentMonths($pgResponse['installMonth'] ?? null),
                'payment_meta' => [
                    'result_code' => $resultCode,
                    'pay_method' => $payMethod,
                    'cbt_type' => 'JPPG',
                    'cbt_mid' => $this->apiService->getJapanMid(),
                    'cbt_sid' => $sid,
                    'mid' => $this->apiService->getJapanMid(),
                    'currency' => 'JPY',
                    'is_cbt' => true,
                    'is_test_mode' => $this->apiService->isTestMode(),
                    'pg_response_sanitized' => true,
                    'pg_auth_response' => $authResponse,
                    'pg_approve_response' => $approveResponse,
                    'pg_raw_response' => $approveResponse,
                ],
            ], $approvedAmount);

            Log::info('KG Inicis CBT: payment completed', ['oid' => $oid, 'tid' => $tid]);

            return redirect($this->resolveSuccessUrl($oid));

        } catch (\Exception $e) {
            Log::error('KG Inicis CBT: callback exception', [
                'oid' => $oid,
                'error' => $e->getMessage(),
            ]);

            $this->refundApprovedCbtPaymentOrFlagManualReconciliation(
                $approvedTid,
                $oid,
                $approvedAmount,
                $e->getMessage(),
            );

            return redirect($this->resolveFailUrl(['error' => 'cbt_failed', 'orderId' => $oid]));
        }
    }

    private function resolveOrderId(Request $request): string
    {
        return (string) (
            $request->input('orderID')
            ?: $request->input('orderId')
            ?: $request->input('oid')
            ?: ''
        );
    }

    private function isCbtSuccessCode(string $resultCode): bool
    {
        return in_array($resultCode, ['OK', '00', '0000'], true);
    }

    private function assertPayableCbtOrder(Order $order): void
    {
        if (! $order->order_status->isBeforePayment()) {
            throw new \RuntimeException('Order is not payable.');
        }

        if ((string) $order->currency !== 'JPY') {
            throw new \RuntimeException('CBT payment is only available for JPY orders.');
        }
    }

    private function resolveApprovedAmount(array $pgResponse, Order $order): int
    {
        $expectedAmount = (int) round((float) $order->total_due_amount);
        $pgAmount = $pgResponse['amount'] ?? $pgResponse['price'] ?? null;

        if ($pgAmount === null || $pgAmount === '') {
            return $expectedAmount;
        }

        $approvedAmount = (int) $pgAmount;
        if ($approvedAmount !== $expectedAmount) {
            throw new \RuntimeException('KG Inicis CBT approved amount mismatch.');
        }

        return $approvedAmount;
    }

    private function normalizeInstallmentMonths(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function refundApprovedCbtPaymentOrFlagManualReconciliation(
        ?string $tid,
        string $oid,
        int $amount,
        string $reason,
    ): void {
        if ($tid === null || $tid === '') {
            return;
        }

        try {
            $refundResult = $this->apiService->refundCbtPayment(
                $tid,
                null,
                'CBT approved but local payment completion failed: ' . mb_substr($reason, 0, 80),
            );

            $this->recordCbtReconciliationStatus($oid, [
                'status' => CbtReconciliationService::STATUS_AUTO_REFUNDED,
                'manual_action_required' => false,
                'tid' => $tid,
                'amount' => $amount,
                'reason' => $reason,
                'refund_error' => null,
                'refund_result' => $this->sanitizePgResponse($refundResult, self::CBT_REFUND_RESPONSE_KEYS),
                'is_test_mode' => $this->apiService->isTestMode(),
                'cbt_mid' => $this->apiService->getJapanMid(),
                'retry_count' => 0,
            ]);

            Log::warning('KG Inicis CBT: approved payment auto-refunded after local failure', [
                'tid' => $tid,
                'oid' => $oid,
                'amount' => $amount,
                'reason' => $reason,
            ]);

            return;
        } catch (\Throwable $refundException) {
            Log::error('KG Inicis CBT: auto refund after local failure failed', [
                'tid' => $tid,
                'oid' => $oid,
                'amount' => $amount,
                'reason' => $reason,
                'refund_error' => $refundException->getMessage(),
            ]);

            $this->recordCbtReconciliationStatus($oid, [
                'status' => CbtReconciliationService::STATUS_MANUAL_REFUND_REQUIRED,
                'manual_action_required' => true,
                'tid' => $tid,
                'amount' => $amount,
                'reason' => $reason,
                'refund_error' => $refundException->getMessage(),
                'refund_result' => null,
                'is_test_mode' => $this->apiService->isTestMode(),
                'cbt_mid' => $this->apiService->getJapanMid(),
                'retry_count' => 0,
            ]);
        }

        Log::error('KG Inicis CBT: post-approve failure — MANUAL CANCEL REQUIRED on KG Inicis JP merchant admin', [
            'tid'    => $tid,
            'oid'    => $oid,
            'amount' => $amount,
            'reason' => $reason,
        ]);
    }

    private function recordCbtReconciliationStatus(string $oid, array $attributes): void
    {
        $this->reconciliationService->record($oid, $attributes);
    }

    private function sanitizePgResponse(array $response, array $allowedKeys): array
    {
        $allowed = array_flip($allowedKeys);
        $sanitized = [];

        foreach ($response as $key => $value) {
            if (! isset($allowed[$key])) {
                continue;
            }

            $sanitized[$key] = is_scalar($value) || $value === null
                ? $value
                : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $sanitized;
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

        $query = http_build_query(array_filter($queryParams));
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
