<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use App\Extension\HookManager;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\PayKginicis\Http\Requests\EscrowNotifyRequest;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;

/**
 * KG 이니시스 에스크로 상태변경 통보 컨트롤러
 *
 * POST /plugins/sirsoft-pay_kginicis/payment/escrow-notify
 * 응답: "cd_rslt=0000" (200, text/plain)
 */
class EscrowNotifyController
{
    public function __construct(
        private readonly OrderProcessingService $orderService,
    ) {}

/**

 * handle

 *

 * @param  EscrowNotifyRequest  $request

 * @return Response

 */

    public function handle(EscrowNotifyRequest $request): Response
    {
        $validated = $request->validated();

        $tid      = (string) $validated['no_tid'];
        $moid     = (string) $validated['no_oid'];
        $clStatus = (string) $validated['cl_status'];
        $price    = (int) $validated['price'];

        Log::info('KG Inicis: escrow notify received', [
            'tid'       => $tid,
            'moid'      => $moid,
            'cl_status' => $clStatus,
            'price'     => $price,
        ]);

        try {
            $order = $this->orderService->findByOrderNumber($moid);

            if (! $order) {
                Log::error('KG Inicis: escrow notify - order not found', ['moid' => $moid, 'tid' => $tid]);

                return $this->ok();
            }

            // cl_status → 이벤트 훅 발행
            match ($clStatus) {
                '2'  => HookManager::doAction('sirsoft-pay_kginicis.escrow.shipping_registered', $order, $validated),
                '3',
                '31',
                '32' => HookManager::doAction('sirsoft-pay_kginicis.escrow.purchase_confirmed', $order, $validated),
                '4'  => HookManager::doAction('sirsoft-pay_kginicis.escrow.purchase_rejected', $order, $validated),
                '8'  => HookManager::doAction('sirsoft-pay_kginicis.escrow.cancelled', $order, $validated),
                '10' => HookManager::doAction('sirsoft-pay_kginicis.escrow.denial_confirmed', $order, $validated),
                default => Log::info('KG Inicis: escrow notify - unknown cl_status', ['cl_status' => $clStatus, 'moid' => $moid]),
            };

            Log::info('KG Inicis: escrow notify processed', [
                'tid'       => $tid,
                'moid'      => $moid,
                'cl_status' => $clStatus,
            ]);

        } catch (\Exception $e) {
            Log::error('KG Inicis: escrow notify failed', [
                'tid'   => $tid,
                'moid'  => $moid,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->ok();
    }

    private function ok(): Response
    {
        return response('cd_rslt=0000', 200)->header('Content-Type', 'text/plain');
    }
}
