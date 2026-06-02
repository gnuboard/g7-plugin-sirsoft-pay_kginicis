<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayKginicis\Concerns\PreventsReplayCallback;
use Plugins\Sirsoft\PayKginicis\Concerns\SanitizesPgResponse;

class CbtCvsOperationsService
{
    use PreventsReplayCallback;
    use SanitizesPgResponse;

    private const HISTORY_LIMIT = 20;

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

    public function handleNotify(array $payload, string $source = 'kg', ?string $remoteIp = null): array
    {
        $tid = trim((string) ($payload['tid'] ?? ''));
        $orderId = trim((string) ($payload['orderId'] ?? ''));
        $mid = trim((string) ($payload['mid'] ?? ''));
        $status = preg_replace('/\s+/', '', (string) ($payload['status'] ?? ''));
        $amount = (int) ($payload['amount'] ?? 0);
        $payment = null;
        $existingMeta = [];

        if ($tid === '' || $orderId === '' || $mid === '' || $amount <= 0) {
            Log::warning('KG Inicis CBT CVS: invalid notify payload', [
                'tid' => $tid,
                'order_id' => $orderId,
                'mid' => $mid,
                'amount' => $payload['amount'] ?? null,
                'keys' => array_keys($payload),
            ]);

            return $this->notifyResult('FAIL', 'failed', 'invalid_payload');
        }

        try {
            $order = $this->orderService->findByOrderNumber($orderId);
            if (! $order) {
                Log::error('KG Inicis CBT CVS: order not found', ['order_id' => $orderId, 'tid' => $tid]);

                return $this->notifyResult('FAIL', 'failed', 'order_not_found');
            }

            $payment = $order->payment()->first();
            if (! $payment) {
                Log::warning('KG Inicis CBT CVS: payment row not found', [
                    'order_id' => $orderId,
                    'tid' => $tid,
                ]);

                return $this->notifyResult('FAIL', 'failed', 'payment_not_found');
            }

            $existingMeta = is_array($payment->payment_meta) ? $payment->payment_meta : [];

            if ($status !== '00') {
                $this->storeNotifyHistory($payment, $existingMeta, $payload, 'ignored', 'non_success_status', $source, $remoteIp);
                Log::info('KG Inicis CBT CVS: non-success notify ignored', [
                    'tid' => $tid,
                    'order_id' => $orderId,
                    'status' => $status,
                ]);

                return $this->notifyResult('OK', 'ignored', 'non_success_status');
            }

            if ($this->wasAlreadyPaid($tid)) {
                $this->storeNotifyHistory($payment, $existingMeta, $payload, 'ignored', 'already_paid', $source, $remoteIp);
                $this->logReplayDetected($tid, $orderId, 'CBT CVS notify');

                return $this->notifyResult('OK', 'ignored', 'already_paid');
            }

            if (! $this->paymentStatusEquals($payment->payment_status, PaymentStatusEnum::WAITING_DEPOSIT)) {
                $this->storeNotifyHistory($payment, $existingMeta, $payload, 'failed', 'payment_status_mismatch', $source, $remoteIp);
                Log::warning('KG Inicis CBT CVS: payment status is not waiting_deposit', [
                    'order_id' => $orderId,
                    'tid' => $tid,
                    'payment_status' => $this->paymentStatusValue($payment->payment_status),
                ]);

                return $this->notifyResult('FAIL', 'failed', 'payment_status_mismatch');
            }

            $expectedPayMethod = strtoupper((string) ($existingMeta['pay_method'] ?? ''));
            if ($expectedPayMethod !== 'CVS') {
                $this->storeNotifyHistory($payment, $existingMeta, $payload, 'failed', 'pay_method_mismatch', $source, $remoteIp);
                Log::warning('KG Inicis CBT CVS: existing payment method is not CVS', [
                    'order_id' => $orderId,
                    'tid' => $tid,
                    'pay_method' => $existingMeta['pay_method'] ?? null,
                ]);

                return $this->notifyResult('FAIL', 'failed', 'pay_method_mismatch');
            }

            $expectedSid = trim((string) ($existingMeta['cbt_sid'] ?? ''));
            $receivedSid = trim((string) ($payload['sid'] ?? ''));
            if ($expectedSid !== '' && $receivedSid !== $expectedSid) {
                $this->storeNotifyHistory($payment, $existingMeta, $payload, 'failed', 'sid_mismatch', $source, $remoteIp);
                Log::warning('KG Inicis CBT CVS: sid mismatch', [
                    'order_id' => $orderId,
                    'tid' => $tid,
                    'received_sid' => $receivedSid,
                    'expected_sid' => $expectedSid,
                ]);

                return $this->notifyResult('FAIL', 'failed', 'sid_mismatch');
            }

            $currency = strtoupper(trim((string) ($payload['currencyCd'] ?? '')));
            if ($currency !== 'JPY') {
                $this->storeNotifyHistory($payment, $existingMeta, $payload, 'failed', 'currency_mismatch', $source, $remoteIp);
                Log::warning('KG Inicis CBT CVS: currency mismatch', [
                    'order_id' => $orderId,
                    'tid' => $tid,
                    'currency' => $payload['currencyCd'] ?? null,
                ]);

                return $this->notifyResult('FAIL', 'failed', 'currency_mismatch');
            }

            $expectedAmount = $this->resolveExpectedCvsAmount($order, $existingMeta);
            if ($expectedAmount > 0 && $amount !== $expectedAmount) {
                $this->storeNotifyHistory($payment, $existingMeta, $payload, 'failed', 'amount_mismatch', $source, $remoteIp);
                Log::warning('KG Inicis CBT CVS: amount mismatch', [
                    'order_id' => $orderId,
                    'tid' => $tid,
                    'received_amount' => $amount,
                    'expected_amount' => $expectedAmount,
                ]);

                return $this->notifyResult('FAIL', 'failed', 'amount_mismatch');
            }

            $expectedMid = (string) ($existingMeta['cbt_mid'] ?? $this->apiService->getJapanMid());
            if ($expectedMid !== '' && $mid !== $expectedMid) {
                $this->storeNotifyHistory($payment, $existingMeta, $payload, 'failed', 'mid_mismatch', $source, $remoteIp);
                Log::warning('KG Inicis CBT CVS: MID mismatch', [
                    'order_id' => $orderId,
                    'tid' => $tid,
                    'received_mid' => $mid,
                    'expected_mid' => $expectedMid,
                ]);

                return $this->notifyResult('FAIL', 'failed', 'mid_mismatch');
            }

            $sanitized = $this->sanitizePgResponse($payload, self::NOTIFY_RESPONSE_KEYS);
            $completedMeta = $this->appendNotifyHistory($existingMeta, $payload, 'confirmed', 'deposit_confirmed', $source, $remoteIp);
            $completedMeta = array_merge($completedMeta, [
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
            ]);

            $this->orderService->completePayment($order, [
                'transaction_id' => $tid,
                'payment_meta' => $completedMeta,
            ], $amount);

            $order->payment()->update(['pg_provider' => 'kginicis']);

            Log::info('KG Inicis CBT CVS: deposit confirmed', [
                'order_id' => $orderId,
                'tid' => $tid,
                'amount' => $amount,
            ]);

            return $this->notifyResult('OK', 'confirmed', 'deposit_confirmed');
        } catch (\Throwable $e) {
            if ($payment instanceof OrderPayment) {
                $this->storeNotifyHistory($payment, $existingMeta, $payload, 'failed', 'exception', $source, $remoteIp);
            }

            Log::error('KG Inicis CBT CVS: notify failed', [
                'order_id' => $orderId,
                'tid' => $tid,
                'error' => $e->getMessage(),
            ]);

            return $this->notifyResult('FAIL', 'failed', 'exception');
        }
    }

    public function summary(string $orderNumber): ?array
    {
        $order = $this->findOrder($orderNumber);
        if (! $order) {
            return null;
        }

        $payment = $order->payment;
        $meta = is_array($payment?->payment_meta) ? $payment->payment_meta : [];
        $isCbtCvs = $payment instanceof OrderPayment && $this->isCbtCvsMeta($meta);
        $paymentTerm = (string) ($meta['cvs_payment_term'] ?? '');
        $paymentTermAt = $this->parseCbtDateTime($paymentTerm);
        $paymentStatus = $payment instanceof OrderPayment ? $this->paymentStatusValue($payment->payment_status) : '';
        $isWaitingDeposit = $paymentStatus === PaymentStatusEnum::WAITING_DEPOSIT->value;
        $isExpiredByTime = $paymentTermAt instanceof CarbonImmutable
            && $paymentTermAt->isPast()
            && ! in_array($paymentStatus, [PaymentStatusEnum::PAID->value, PaymentStatusEnum::EXPIRED->value], true);

        return [
            'is_cbt_cvs' => $isCbtCvs,
            'order_number' => $order->order_number,
            'order_status' => $this->enumValue($order->order_status),
            'payment_status' => $paymentStatus,
            'tid' => (string) ($payment?->transaction_id ?? ''),
            'amount' => (int) ($meta['cvs_amount'] ?? ($payment?->paid_amount_local ?: $order->total_due_amount)),
            'currency' => (string) ($payment?->currency ?? $order->currency ?? 'JPY'),
            'cbt_mid' => (string) ($meta['cbt_mid'] ?? ''),
            'cbt_sid' => (string) ($meta['cbt_sid'] ?? ''),
            'is_test_mode' => (bool) ($meta['is_test_mode'] ?? false),
            'convenience' => (string) ($meta['cvs_convenience'] ?? ''),
            'conf_no' => (string) ($meta['cvs_conf_no'] ?? ''),
            'receipt_no' => (string) ($meta['cvs_receipt_no'] ?? ''),
            'payment_term' => $paymentTerm,
            'payment_term_formatted' => $paymentTermAt?->format('Y-m-d H:i:s'),
            'is_expired_by_time' => $isExpiredByTime,
            'cvs_status' => (string) ($meta['cvs_status'] ?? ($isWaitingDeposit ? 'waiting_deposit' : '')),
            'last_notify_at' => (string) ($meta['cvs_last_notify_at'] ?? ''),
            'last_notify_result' => (string) ($meta['cvs_last_notify_result'] ?? ''),
            'last_notify_reason' => (string) ($meta['cvs_last_notify_reason'] ?? ''),
            'notify_history' => array_slice($this->normalizeHistory($meta['cvs_notify_history'] ?? []), 0, 10),
            'notify_url' => url('/plugins/sirsoft-pay_kginicis/payment/cbt/cvs-notify'),
            'can_simulate_notify' => $isCbtCvs && $isWaitingDeposit && (bool) ($meta['is_test_mode'] ?? false),
            'can_mark_expired' => $isCbtCvs && $isWaitingDeposit && $isExpiredByTime,
            'last_recheck_at' => (string) ($meta['cvs_last_recheck_at'] ?? ''),
            'last_recheck_result' => (string) ($meta['cvs_last_recheck_result'] ?? ''),
            'expired_at' => (string) ($meta['cvs_expired_at'] ?? ''),
            'expiry_reason' => (string) ($meta['cvs_expiry_reason'] ?? ''),
        ];
    }

    public function simulatePaidNotify(string $orderNumber, ?string $remoteIp = null): array
    {
        $context = $this->operationContext($orderNumber);
        if (! $context['ok']) {
            return $context;
        }

        /** @var Order $order */
        $order = $context['order'];
        /** @var OrderPayment $payment */
        $payment = $context['payment'];
        $meta = $context['meta'];

        if (! (bool) ($meta['is_test_mode'] ?? false)) {
            return $this->operationError('messages.cbt_cvs.not_test_mode', 422);
        }

        if (! $this->paymentStatusEquals($payment->payment_status, PaymentStatusEnum::WAITING_DEPOSIT)) {
            return $this->operationError('messages.cbt_cvs.not_waiting_deposit', 422);
        }

        $now = now();
        $payload = [
            'tid' => $payment->transaction_id ?: 'ADMIN_CVS_' . $order->order_number,
            'mid' => (string) ($meta['cbt_mid'] ?? $this->apiService->getJapanMid()),
            'applDt' => $now->format('Ymd'),
            'applTm' => $now->format('His'),
            'status' => '00',
            'payNm' => 'CVS',
            'orderId' => $order->order_number,
            'applNo' => (string) ($meta['cvs_receipt_no'] ?? 'ADMIN-SIM'),
            'sid' => (string) ($meta['cbt_sid'] ?? ''),
            'convenience' => (string) ($meta['cvs_convenience'] ?? ''),
            'confNo' => (string) ($meta['cvs_conf_no'] ?? ''),
            'receiptNo' => (string) ($meta['cvs_receipt_no'] ?? ''),
            'paymentTerm' => (string) ($meta['cvs_payment_term'] ?? ''),
            'amount' => (string) $this->resolveExpectedCvsAmount($order, $meta),
            'currencyCd' => 'JPY',
        ];

        $notify = $this->handleNotify($payload, 'admin_simulation', $remoteIp);
        if (($notify['body'] ?? 'FAIL') !== 'OK') {
            return array_merge($this->operationError('messages.cbt_cvs.simulate_failed', 422), [
                'notify' => $notify,
                'summary' => $this->summary($orderNumber),
            ]);
        }

        return [
            'ok' => true,
            'notify' => $notify,
            'summary' => $this->summary($orderNumber),
        ];
    }

    public function expireOverdue(string $orderNumber): array
    {
        $context = $this->operationContext($orderNumber);
        if (! $context['ok']) {
            return $context;
        }

        /** @var OrderPayment $payment */
        $payment = $context['payment'];
        $meta = $context['meta'];
        $summary = $this->summary($orderNumber);

        if (! ($summary['can_mark_expired'] ?? false)) {
            return $this->operationError('messages.cbt_cvs.not_expirable', 422);
        }

        $updatedMeta = array_merge($meta, [
            'cvs_status' => 'expired',
            'cvs_expired_at' => now()->toIso8601String(),
            'cvs_expiry_reason' => 'payment_term_elapsed',
        ]);

        DB::transaction(function () use ($payment, $updatedMeta): void {
            $payment->forceFill([
                'payment_status' => PaymentStatusEnum::EXPIRED,
                'payment_meta' => $updatedMeta,
            ])->save();
        });

        return [
            'ok' => true,
            'summary' => $this->summary($orderNumber),
        ];
    }

    public function markRechecked(string $orderNumber): array
    {
        $context = $this->operationContext($orderNumber);
        if (! $context['ok']) {
            return $context;
        }

        /** @var OrderPayment $payment */
        $payment = $context['payment'];
        $meta = array_merge($context['meta'], [
            'cvs_last_recheck_at' => now()->toIso8601String(),
            'cvs_last_recheck_result' => 'local_status_checked',
        ]);

        $this->savePaymentMeta($payment, $meta);

        return [
            'ok' => true,
            'summary' => $this->summary($orderNumber),
        ];
    }

    private function operationContext(string $orderNumber): array
    {
        $order = $this->findOrder($orderNumber);
        if (! $order) {
            return $this->operationError('messages.errors.order_not_found', 404);
        }

        $payment = $order->payment;
        $meta = is_array($payment?->payment_meta) ? $payment->payment_meta : [];

        if (! $payment instanceof OrderPayment || ! $this->isCbtCvsMeta($meta)) {
            return $this->operationError('messages.cbt_cvs.not_cvs', 422, [
                'order' => $order,
                'payment' => $payment,
                'meta' => $meta,
            ]);
        }

        return [
            'ok' => true,
            'order' => $order,
            'payment' => $payment,
            'meta' => $meta,
        ];
    }

    private function operationError(string $messageKey, int $status, array $context = []): array
    {
        return array_merge([
            'ok' => false,
            'message_key' => $messageKey,
            'status' => $status,
        ], $context);
    }

    private function findOrder(string $orderNumber): ?Order
    {
        return Order::query()
            ->with('payment')
            ->where('order_number', $orderNumber)
            ->first();
    }

    private function notifyResult(string $body, string $status, string $reason): array
    {
        return [
            'body' => $body,
            'status' => $status,
            'reason' => $reason,
        ];
    }

    private function storeNotifyHistory(
        OrderPayment $payment,
        array $existingMeta,
        array $payload,
        string $result,
        string $reason,
        string $source,
        ?string $remoteIp
    ): array {
        $updatedMeta = $this->appendNotifyHistory($existingMeta, $payload, $result, $reason, $source, $remoteIp);
        $this->savePaymentMeta($payment, $updatedMeta);

        return $updatedMeta;
    }

    private function appendNotifyHistory(
        array $existingMeta,
        array $payload,
        string $result,
        string $reason,
        string $source,
        ?string $remoteIp
    ): array {
        $now = now()->toIso8601String();
        $history = $this->normalizeHistory($existingMeta['cvs_notify_history'] ?? []);
        array_unshift($history, [
            'received_at' => $now,
            'source' => $source,
            'remote_ip' => $remoteIp,
            'result' => $result,
            'reason' => $reason,
            'tid' => trim((string) ($payload['tid'] ?? '')),
            'order_id' => trim((string) ($payload['orderId'] ?? '')),
            'mid' => trim((string) ($payload['mid'] ?? '')),
            'status' => preg_replace('/\s+/', '', (string) ($payload['status'] ?? '')),
            'amount' => (int) ($payload['amount'] ?? 0),
            'currency' => strtoupper(trim((string) ($payload['currencyCd'] ?? ''))),
            'sid' => trim((string) ($payload['sid'] ?? '')),
        ]);

        return array_merge($existingMeta, [
            'cvs_notify_history' => array_slice($history, 0, self::HISTORY_LIMIT),
            'cvs_last_notify_at' => $now,
            'cvs_last_notify_result' => $result,
            'cvs_last_notify_reason' => $reason,
        ]);
    }

    private function savePaymentMeta(OrderPayment $payment, array $meta): void
    {
        $payment->forceFill(['payment_meta' => $meta])->save();
        $payment->refresh();
    }

    private function normalizeHistory(mixed $history): array
    {
        if (! is_array($history)) {
            return [];
        }

        return array_values(array_filter($history, static fn ($item): bool => is_array($item)));
    }

    private function isCbtCvsMeta(array $meta): bool
    {
        return ($meta['is_cbt'] ?? false) === true
            && strtoupper((string) ($meta['pay_method'] ?? '')) === 'CVS';
    }

    private function resolveExpectedCvsAmount(Order $order, array $paymentMeta): int
    {
        $metaAmount = (int) ($paymentMeta['cvs_amount'] ?? 0);
        if ($metaAmount > 0) {
            return $metaAmount;
        }

        return (int) round((float) $order->total_due_amount);
    }

    private function parseCbtDateTime(?string $value): ?CarbonImmutable
    {
        $compact = preg_replace('/\D+/', '', (string) $value);
        if (! is_string($compact) || strlen($compact) !== 14) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('YmdHis', $compact) ?: null;
        } catch (\Throwable) {
            return null;
        }
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

    private function enumValue(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return (string) $value;
    }
}
