<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use App\Services\PluginSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayKginicis\Concerns\PreventsReplayCallback;
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

        // Approve 성공 후 후속 처리 실패 시 PG 자동 cancel 추적 변수.
        $approvedTid = null;
        $approvedAmount = 0;

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

            // Replay 가드: 동일 tid 가 이미 paid 상태면 중복 처리하지 않고 성공 페이지로 복귀
            if ($this->wasAlreadyPaid($tid)) {
                $this->logReplayDetected($tid, $oid, 'CBT authCallback');

                return redirect($this->resolveSuccessUrl($oid));
            }

            // PG 측 승인이 확정된 시점 — 후속 처리 실패 시 cancel 알림 필요. catch 에서 참조.
            $approvedTid = $tid;
            $approvedAmount = $amount;

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

            $this->flagCbtManualReconciliation($approvedTid, $oid, $approvedAmount, $e->getMessage());

            return redirect($this->resolveFailUrl(['error' => 'cbt_failed', 'orderId' => $oid]));
        }
    }

    /**
     * CBT 승인 후 후속 처리 실패 시 운영자 수동 정산을 위한 강한 신호 로깅.
     *
     * KG 이니시스 CBT 는 일본 결제 전용 MID 와 별도 API (cbtapprove/cbtcancel) 를
     * 사용한다. 기존 cancelPayment() 는 한국 결제용 standard MID 기반이라 CBT TID
     * 에는 적용 불가. 따라서 자동 cancel API 호출 대신 ERROR 로그로 운영자가
     * KG 이니시스 가맹점 관리자(JP) 에서 수동 처리하도록 신호한다.
     *
     * 향후 KgInicisApiService::cancelCbtPayment() 가 추가되면 본 메서드를
     * 자동 cancel 호출 흐름으로 전환 가능.
     */
    private function flagCbtManualReconciliation(
        ?string $tid,
        string $oid,
        int $amount,
        string $reason,
    ): void {
        if ($tid === null || $tid === '') {
            return;
        }

        Log::error('KG Inicis CBT: post-approve failure — MANUAL CANCEL REQUIRED on KG Inicis JP merchant admin', [
            'tid'    => $tid,
            'oid'    => $oid,
            'amount' => $amount,
            'reason' => $reason,
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
