<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use App\Services\PluginSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserReceiptController
{
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
            ->select(['p.transaction_id'])
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

        return response()->json([
            'receipt_url'  => $receiptUrl,
            'is_test_mode' => $isTestMode,
        ]);
    }
}
