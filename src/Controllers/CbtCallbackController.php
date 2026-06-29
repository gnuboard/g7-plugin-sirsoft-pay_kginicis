<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use App\Services\PluginSettingsService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayKginicis\Concerns\IssuesReceiptCookie;
use Plugins\Sirsoft\PayKginicis\Concerns\PreventsReplayCallback;
use Plugins\Sirsoft\PayKginicis\Http\Requests\CbtCallbackRequest;
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
    use IssuesReceiptCookie;
    use PreventsReplayCallback;

    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_kginicis';

    private const CBT_USER_CANCEL_RESULT_CODES = ['2001', '0021', '0022'];

    private const CBT_USER_CANCEL_MESSAGE_PATTERNS = [
        'ユーザーキャンセル',
        'キャンセル',
        '사용자가 결제를 취소',
        '결제를 취소',
        '사용자가 취소',
        '사용자 취소',
        '취소',
    ];

    private const CBT_PAYPAY_PROCESSING_FAILURE_MESSAGE = 'PayPay 결제를 완료하지 못했습니다. 다시 시도하거나 다른 결제수단을 선택해 주세요.';

    private const CBT_AUTH_RESPONSE_KEYS = [
        'resultCode',
        'resultMsg',
        'orderID',
        'orderId',
        'oid',
        'mid',
        'sid',
        'paymethod',
        'selectedPaymentMethod',
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
        'applNo',
        'convenience',
        'confNo',
        'receiptNo',
        'paymentTerm',
        'currencyCd',
        'currencyCode',
        'mid',
        'MID',
        'oid',
        'orderId',
        'orderID',
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
     * CBT(일본 엔화) 결제 인증 콜백을 받아 승인 처리 후 결과 페이지로 리다이렉트합니다.
     *
     * @param  CbtCallbackRequest  $request  CBT 인증 콜백 요청
     * @return RedirectResponse 성공/실패 결과 페이지 리다이렉트
     */
    public function handle(CbtCallbackRequest $request): RedirectResponse
    {
        $sid = (string) $request->input('sid', '');
        $oid = $this->resolveOrderId($request);
        $authResultCode = (string) $request->input('resultCode', '');
        $authResultMsg = (string) $request->input('resultMsg', '');
        $authMid = (string) $request->input('mid', '');

        if ($authResultCode !== '' && $authResultCode !== 'OK' && $this->isCbtAuthUserCancel($authResultCode, $authResultMsg)) {
            Log::info('KG Inicis CBT: auth cancelled by user', [
                'oid' => $oid,
                'result_code' => $authResultCode,
                'result_msg' => $authResultMsg,
            ]);

            return redirect($this->resolveFailUrl());
        }

        if ($sid === '' || $oid === '') {
            Log::warning('KG Inicis CBT: missing sid or oid', ['oid' => $oid, 'sid' => $sid]);

            return redirect($this->resolveFailUrl(['error' => 'invalid_params', 'orderId' => $oid]));
        }

        if ($authResultCode !== '' && $authResultCode !== 'OK') {
            $order = $this->orderService->findByOrderNumber($oid);
            if ($order) {
                $order = $this->orderService->failPayment($order, $authResultCode, $authResultMsg);
                $this->markCbtAuthFailurePayment($order, $request, $authResultCode, $authResultMsg);
            }

            Log::warning('KG Inicis CBT: auth failed', [
                'oid' => $oid,
                'result_code' => $authResultCode,
                'result_msg' => $authResultMsg,
            ]);

            return redirect($this->resolveFailUrl($this->buildCbtFailureRedirectParams(
                $authResultCode !== '' ? $authResultCode : 'cbt_auth_failed',
                $authResultMsg,
                (string) $request->input('paymethod', ''),
                $oid,
            )));
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

                return redirect($this->resolveFailUrl($this->buildCbtFailureRedirectParams(
                    (string) $resultCode,
                    (string) $resultMsg,
                    (string) ($pgResponse['paymethod'] ?? $request->input('paymethod', '')),
                    $oid,
                )));
            }

            $tid = $pgResponse['tid'] ?? ($pgResponse['transactionId'] ?? '');
            if ($tid === '') {
                throw new \RuntimeException('KG Inicis CBT approve response missing tid.');
            }

            // Replay 가드: 동일 tid 가 이미 paid 상태면 중복 처리하지 않고 성공 페이지로 복귀
            if ($this->wasAlreadyPaid($tid)) {
                $this->logReplayDetected($tid, $oid, 'CBT authCallback');
                $this->queueReceiptCookie($oid);

                return redirect($this->resolveSuccessUrl($oid));
            }

            $payMethod = (string) ($pgResponse['paymethod'] ?? $request->input('paymethod', 'CBT'));
            if (! $this->isCbtCvsPayMethod($payMethod)) {
                // PG 승인이 확정된 직후부터는 어떤 후속 예외라도 자동 취소 대상이다.
                $approvedTid = $tid;
            }

            $this->assertCbtApproveResponseMatchesOrder($order, $pgResponse, $request, $payMethod);
            // PG 승인금액이 누락되어 검증 예외가 발생해도 자동환불 대사 레코드에는 결제 통화 청구액을 남긴다
            // (buildPgPaymentData 와 동일 SSoT — base≠결제 통화에서도 PG 청구 통화와 단위 일치).
            $approvedAmount = app(CurrencyConversionService::class)->resolveOrderPaymentChargeAmount($order);
            $approvedAmount = $this->resolveApprovedAmount($pgResponse, $order);
            $authResponse = $this->sanitizePgResponse($request->except(['_token']), self::CBT_AUTH_RESPONSE_KEYS);
            $approveResponse = $this->sanitizePgResponse($pgResponse, self::CBT_APPROVE_RESPONSE_KEYS);
            $selectedPaymentMethod = $this->resolveSelectedCbtPaymentMethod($request, $payMethod);
            $selectionMeta = $this->buildCbtSelectionPaymentMeta($selectedPaymentMethod);

            if ($this->isCbtCvsPayMethod($payMethod)) {
                $this->handleCbtCvsIssued($order, $pgResponse, $tid, $sid, $approvedAmount, $authResponse, $approveResponse, $selectedPaymentMethod);
                $this->queueReceiptCookie($oid);

                Log::info('KG Inicis CBT: CVS payment issued', ['oid' => $oid, 'tid' => $tid]);

                return redirect($this->resolveSuccessUrl($oid));
            }

            $this->orderService->completePayment($order, [
                'transaction_id' => $tid,
                'card_approval_number' => $pgResponse['approve'] ?? null,
                'card_installment_months' => $this->normalizeInstallmentMonths($pgResponse['installMonth'] ?? null),
                'payment_meta' => array_merge([
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
                ], $selectionMeta),
            ], $approvedAmount);

            $this->queueReceiptCookie($oid);

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

    private function resolveOrderId(CbtCallbackRequest $request): string
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

    private function isCbtAuthUserCancel(string $resultCode, string $resultMsg): bool
    {
        if (in_array($resultCode, self::CBT_USER_CANCEL_RESULT_CODES, true)) {
            return true;
        }

        foreach (self::CBT_USER_CANCEL_MESSAGE_PATTERNS as $pattern) {
            if ($resultMsg !== '' && str_contains($resultMsg, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function buildCbtFailureRedirectParams(
        string $resultCode,
        string $resultMsg,
        string $payMethod,
        string $orderId,
    ): array {
        if ($this->isPayPayProcessingFailure($resultCode, $resultMsg, $payMethod)) {
            return [
                'error' => 'paypay_processing_failed',
                'message' => self::CBT_PAYPAY_PROCESSING_FAILURE_MESSAGE,
                'orderId' => $orderId,
            ];
        }

        return [
            'error' => $resultCode,
            'message' => $resultMsg,
            'orderId' => $orderId,
        ];
    }

    private function markCbtAuthFailurePayment(
        Order $order,
        CbtCallbackRequest $request,
        string $resultCode,
        string $resultMsg,
    ): void {
        $payment = $order->payment()->first();

        if (! $payment) {
            return;
        }

        $payMethod = (string) $request->input('paymethod', '');
        $selectedPaymentMethod = $this->resolveSelectedCbtPaymentMethod($request, $payMethod);
        $meta = is_array($payment->payment_meta) ? $payment->payment_meta : [];
        $authResponse = $this->sanitizePgResponse($request->except(['_token']), self::CBT_AUTH_RESPONSE_KEYS);

        $payment->update([
            'payment_status' => PaymentStatusEnum::FAILED,
            'payment_meta' => array_merge($meta, [
                'result_code' => $resultCode,
                'result_msg' => $resultMsg,
                'pay_method' => $payMethod !== '' ? $this->normalizeCbtPayMethod($payMethod) : null,
                'cbt_type' => 'JPPG',
                'cbt_mid' => $this->apiService->getJapanMid(),
                'mid' => $this->apiService->getJapanMid(),
                'currency' => 'JPY',
                'is_cbt' => true,
                'is_test_mode' => $this->apiService->isTestMode(),
                'pg_response_sanitized' => true,
                'pg_auth_response' => $authResponse,
                'cbt_failure_at' => now()->toIso8601String(),
            ], $this->buildCbtSelectionPaymentMeta($selectedPaymentMethod)),
        ]);
    }

    private function isPayPayProcessingFailure(string $resultCode, string $resultMsg, string $payMethod): bool
    {
        $normalizedPayMethod = strtoupper($payMethod);
        $normalizedResultCode = strtolower($resultCode);
        $normalizedResultMsg = strtolower($resultMsg);
        $isPayPay = str_contains($normalizedPayMethod, 'PAYPAY');

        return $isPayPay
            && (
                $normalizedResultCode === 'processing_failure'
                || str_contains($normalizedResultMsg, 'processing failed upstream')
            );
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
        // 결제 청구액 SSoT = 결제 통화(order_currency) 환산액 (buildPgPaymentData 와 동일 기준).
        // CBT 는 order_currency=JPY 강제이나 base 통화는 다를 수 있어(예: base KRW, 결제 JPY)
        // total_due_amount(base) 직접 비교 시 PG 승인액(JPY)과 단위가 어긋난다.
        $expectedAmount = app(CurrencyConversionService::class)->resolveOrderPaymentChargeAmount($order);
        $pgAmount = $pgResponse['amount'] ?? $pgResponse['price'] ?? null;

        if ($pgAmount === null || $pgAmount === '') {
            throw new \RuntimeException('KG Inicis CBT approved amount missing.');
        }

        $approvedAmount = (int) $pgAmount;
        if ($approvedAmount !== $expectedAmount) {
            throw new \RuntimeException('KG Inicis CBT approved amount mismatch.');
        }

        return $approvedAmount;
    }

    private function assertCbtApproveResponseMatchesOrder(
        Order $order,
        array $pgResponse,
        CbtCallbackRequest $request,
        string $approvedPayMethod,
    ): void {
        $receivedOrderId = $this->firstNonEmptyString($pgResponse, ['orderId', 'orderID', 'oid']);
        if ($receivedOrderId !== null && $receivedOrderId !== (string) $order->order_number) {
            throw new \RuntimeException('KG Inicis CBT approved order id mismatch.');
        }

        $receivedMid = $this->firstNonEmptyString($pgResponse, ['mid', 'MID']);
        $expectedMid = $this->apiService->getJapanMid();
        if ($receivedMid !== null && $receivedMid !== $expectedMid) {
            throw new \RuntimeException('KG Inicis CBT approved MID mismatch.');
        }

        $receivedCurrency = strtoupper((string) $this->firstNonEmptyString($pgResponse, ['currencyCd', 'currencyCode', 'currency']));
        if ($receivedCurrency !== '' && $receivedCurrency !== 'JPY') {
            throw new \RuntimeException('KG Inicis CBT approved currency mismatch.');
        }

        $expectedPayMethod = $this->resolveExpectedCbtPayMethod($request);
        $receivedPayMethod = $this->normalizeCbtPayMethod($approvedPayMethod);
        if ($expectedPayMethod !== null && $receivedPayMethod !== '' && $receivedPayMethod !== $expectedPayMethod) {
            throw new \RuntimeException('KG Inicis CBT approved paymethod mismatch.');
        }
    }

    /**
     * @param  array<string,mixed>  $source
     * @param  array<int,string>  $keys
     */
    private function firstNonEmptyString(array $source, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $source)) {
                continue;
            }

            $value = trim((string) $source[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function resolveExpectedCbtPayMethod(CbtCallbackRequest $request): ?string
    {
        $selectedPaymentMethod = (string) $request->input('selectedPaymentMethod', '');
        $expectedBySelectedMethod = [
            'card' => 'CARD',
            'kginicis_japan_paypay' => 'PAYPAY',
            'kginicis_japan_cvs' => 'CVS',
        ];

        if ($selectedPaymentMethod !== '' && isset($expectedBySelectedMethod[$selectedPaymentMethod])) {
            return $expectedBySelectedMethod[$selectedPaymentMethod];
        }

        $authPayMethod = $this->normalizeCbtPayMethod((string) $request->input('paymethod', ''));

        return $authPayMethod !== '' ? $authPayMethod : null;
    }

    private function resolveSelectedCbtPaymentMethod(CbtCallbackRequest $request, string $payMethod): ?string
    {
        $selectedPaymentMethod = (string) $request->input('selectedPaymentMethod', '');
        $allowedSelectedMethods = [
            'card',
            'kginicis_japan_paypay',
            'kginicis_japan_cvs',
        ];

        if (in_array($selectedPaymentMethod, $allowedSelectedMethods, true)) {
            return $selectedPaymentMethod;
        }

        return match ($this->normalizeCbtPayMethod($payMethod)) {
            'CARD' => 'card',
            'PAYPAY' => 'kginicis_japan_paypay',
            'CVS' => 'kginicis_japan_cvs',
            default => null,
        };
    }

    private function buildCbtSelectionPaymentMeta(?string $selectedPaymentMethod): array
    {
        if ($selectedPaymentMethod === null || $selectedPaymentMethod === '') {
            return [];
        }

        return [
            'selected_payment_method' => $selectedPaymentMethod,
        ];
    }

    private function normalizeCbtPayMethod(string $payMethod): string
    {
        return strtoupper(trim($payMethod));
    }

    private function normalizeInstallmentMonths(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function isCbtCvsPayMethod(string $payMethod): bool
    {
        return strtoupper($payMethod) === 'CVS';
    }

    private function handleCbtCvsIssued(
        Order $order,
        array $pgResponse,
        string $tid,
        string $sid,
        int $amount,
        array $authResponse,
        array $approveResponse,
        ?string $selectedPaymentMethod,
    ): void {
        $paymentTerm = (string) ($pgResponse['paymentTerm'] ?? '');
        $dueAt = null;

        if (preg_match('/^\d{14}$/', $paymentTerm) === 1) {
            try {
                $dueAt = Carbon::createFromFormat('YmdHis', $paymentTerm, 'Asia/Tokyo')
                    ->setTimezone(config('app.timezone', 'UTC'));
            } catch (\Throwable) {
                $dueAt = null;
            }
        }

        $order->payment()->update(array_filter([
            'pg_provider' => 'kginicis',
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
            'transaction_id' => $tid,
            'vbank_code' => $pgResponse['convenience'] ?? null,
            'vbank_name' => 'CVS',
            'vbank_number' => $pgResponse['confNo'] ?? ($pgResponse['receiptNo'] ?? null),
            'vbank_due_at' => $dueAt,
            'vbank_issued_at' => now(),
            'payment_meta' => array_merge([
                'result_code' => $pgResponse['resultCode'] ?? 'OK',
                'pay_method' => 'CVS',
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
                'cvs_status' => 'waiting_deposit',
                'cvs_amount' => $amount,
                'cvs_convenience' => $pgResponse['convenience'] ?? null,
                'cvs_conf_no' => $pgResponse['confNo'] ?? null,
                'cvs_receipt_no' => $pgResponse['receiptNo'] ?? null,
                'cvs_payment_term' => $paymentTerm !== '' ? $paymentTerm : null,
            ], $this->buildCbtSelectionPaymentMeta($selectedPaymentMethod)),
        ], fn ($value) => $value !== null));
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
                'CBT approved but local payment completion failed: '.mb_substr($reason, 0, 80),
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
            'tid' => $tid,
            'oid' => $oid,
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

        return $baseUrl.$separator.$query;
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
        $path = $url === '' ? '/' : ($url[0] === '/' ? $url : '/'.$url);

        return $base.$path;
    }
}
