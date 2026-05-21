<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use App\Services\PluginSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Plugins\Sirsoft\PayKginicis\Concerns\ResolvesEasyPaySelection;

class UserReceiptController
{
    use ResolvesEasyPaySelection;

    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_kginicis';

    // 출처: C:\xampp824\www\gnu5\shop\orderinquiryview.php (mCmReceipt_head.jsp)
    private const RECEIPT_BASE_URL = 'https://iniweb.inicis.com/DefaultWebApp/mall/cr/cm/mCmReceipt_head.jsp';

    public function __construct(
        private readonly PluginSettingsService $pluginSettingsService,
    ) {}

    /**
     * show
     *
     * @param  Request  $request
     * @param  string  $orderNumber
     * @return JsonResponse
     */
    public function show(Request $request, string $orderNumber): JsonResponse
    {
        $user = $request->user();

        $payment = DB::table('ecommerce_order_payments as p')
            ->join('ecommerce_orders as o', 'o.id', '=', 'p.order_id')
            ->where('o.order_number', $orderNumber)
            ->where('o.user_id', $user->id)
            ->where('p.pg_provider', 'kginicis')
            ->select([
                'p.transaction_id',
                'p.payment_method',
                'p.embedded_pg_provider',
                'p.payment_meta',
            ])
            ->first();

        if (! $payment || ! $payment->transaction_id) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $receiptUrl = self::RECEIPT_BASE_URL . '?' . http_build_query([
            'noTid'    => $payment->transaction_id,
            'noMethod' => '1',
        ]);

        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $isTestMode = (bool) ($settings['is_test_mode'] ?? true);
        $paymentMeta = $this->decodePaymentMeta($payment->payment_meta ?? null);
        $embeddedPgProvider = $payment->embedded_pg_provider
            ?: ($paymentMeta['embedded_pg_provider'] ?? null);
        $embeddedPgProviderLabel = $paymentMeta['embedded_pg_provider_label']
            ?? $this->embeddedPgProviderLabel(is_string($embeddedPgProvider) ? $embeddedPgProvider : null);
        $basePaymentMethodLabel = $this->paymentMethodLabel((string) ($payment->payment_method ?? ''));

        return response()->json([
            'receipt_url'                   => $receiptUrl,
            'is_test_mode'                  => $isTestMode,
            'payment_method_label'          => $basePaymentMethodLabel,
            'payment_method_display_label'  => $this->paymentMethodDisplayLabel(
                $basePaymentMethodLabel,
                is_string($embeddedPgProviderLabel) ? $embeddedPgProviderLabel : null,
            ),
            'selected_payment_method'       => $paymentMeta['selected_payment_method'] ?? null,
            'embedded_pg_provider'          => is_string($embeddedPgProvider) ? $embeddedPgProvider : null,
            'embedded_pg_provider_label'    => is_string($embeddedPgProviderLabel) ? $embeddedPgProviderLabel : null,
        ]);
    }

    private function decodePaymentMeta(mixed $paymentMeta): array
    {
        if (is_array($paymentMeta)) {
            return $paymentMeta;
        }

        if (! is_string($paymentMeta) || $paymentMeta === '') {
            return [];
        }

        $decoded = json_decode($paymentMeta, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function paymentMethodLabel(string $paymentMethod): string
    {
        return match (strtolower($paymentMethod)) {
            'card' => '신용카드',
            'vbank' => '가상계좌',
            'bank' => '계좌이체',
            'dbank' => '무통장입금',
            'phone', 'mobile' => '휴대폰결제',
            'point' => '포인트결제',
            'deposit' => '예치금결제',
            'free' => '무료',
            default => $paymentMethod !== '' ? $paymentMethod : '-',
        };
    }

    private function embeddedPgProviderLabel(?string $provider): ?string
    {
        if ($provider === null || $provider === '') {
            return null;
        }

        foreach ($this->kginicisEasyPayMethodMap() as $context) {
            if (($context['provider'] ?? null) === $provider) {
                return $context['label'];
            }
        }

        return match (strtolower($provider)) {
            'payco' => '페이코',
            'tosspay', 'toss' => '토스페이',
            'ssgpay' => 'SSG페이',
            default => $provider,
        };
    }

    private function paymentMethodDisplayLabel(string $baseLabel, ?string $embeddedLabel): string
    {
        if ($embeddedLabel === null || $embeddedLabel === '') {
            return $baseLabel;
        }

        if ($baseLabel === '-' || $baseLabel === '') {
            return $embeddedLabel;
        }

        return $embeddedLabel . ' (' . $baseLabel . ')';
    }
}
