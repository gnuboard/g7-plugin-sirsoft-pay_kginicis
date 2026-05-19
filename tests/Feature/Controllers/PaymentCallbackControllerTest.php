<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

class PaymentCallbackControllerTest extends PluginTestCase
{
    private const TEST_MID = 'INIpayTest';

    private const TEST_SIGN_KEY = 'SU5JTElURV9UUklQTEVERVNfS0VZU1RS';

    private function makeAuthorizeResponse(string $tid, string $moid, int $amount, string $resultCode = '0000'): array
    {
        return [
            'resultCode' => $resultCode,
            'resultMsg' => '성공',
            'tid' => $tid,
            'MOID' => $moid,
            'TotPrice' => (string) $amount,
            'payMethod' => 'Card',
            'applNum' => 'APP12345',
            'cardNum' => '4330-****-****-1234',
            'cardName' => '신한카드',
            'cardQuota' => '00',
            'applDate' => now()->format('YmdHis'),
        ];
    }

    private function createTestOrder(int $totalAmount = 50000): Order
    {
        $user = User::factory()->create();

        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-TEST-' . random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'subtotal_amount' => $totalAmount,
            'total_discount_amount' => 0,
            'total_coupon_discount_amount' => 0,
            'total_product_coupon_discount_amount' => 0,
            'total_order_coupon_discount_amount' => 0,
            'total_code_discount_amount' => 0,
            'base_shipping_amount' => 0,
            'extra_shipping_amount' => 0,
            'shipping_discount_amount' => 0,
            'total_shipping_amount' => 0,
            'total_amount' => $totalAmount,
            'total_due_amount' => $totalAmount,
            'total_points_used_amount' => 0,
            'total_deposit_used_amount' => 0,
            'total_paid_amount' => 0,
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::READY,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'kginicis',
            'paid_amount_local' => 0,
            'paid_at' => null,
            'transaction_id' => null,
            'card_approval_number' => null,
        ]);

        return $order;
    }

    private function mockPluginSettings(array $overrides = []): void
    {
        $defaults = [
            'is_test_mode' => true,
            'test_mid' => self::TEST_MID,
            'test_sign_key' => self::TEST_SIGN_KEY,
            'test_iniapi_key' => 'ItEQKi3rY7uvDS8l',
            'test_iniapi_iv' => 'HYb3yQ4f65QL89==',
            'live_mid' => '',
            'live_sign_key' => '',
            'live_iniapi_key' => '',
            'live_iniapi_iv' => '',
            'redirect_success_url' => '/shop/orders/{orderId}/complete',
            'redirect_fail_url' => '/shop/checkout',
        ];

        $settingsMock = $this->createMock(\App\Services\PluginSettingsService::class);
        $settingsMock->method('get')
            ->willReturn(array_merge($defaults, $overrides));

        $this->app->instance(\App\Services\PluginSettingsService::class, $settingsMock);
    }

    private function makeCallbackParams(string $moid, int $totPrice, array $overrides = []): array
    {
        return array_merge([
            'resultCode' => '0000',
            'resultMsg' => '성공',
            'authToken' => 'AUTH_TOKEN_' . uniqid(),
            'authUrl' => 'https://fcstdpay.inicis.com/api/payAuth',
            // idc_name 은 KG 이니시스가 success 콜백에 항상 포함시키는 IDC 식별자.
            // 컨트롤러가 isValidIdcAuthUrl 화이트리스트 검증에 사용 (SSRF 방어).
            'idc_name' => 'fc',
            'netCancelUrl' => 'https://stginiapi.inicis.com/api/v1/netcancel',
            'MOID' => $moid,
            'TotPrice' => $totPrice,
        ], $overrides);
    }

    // ===== 성공 콜백 테스트 =====

    public function test_auth_callback_redirects_to_complete_page_on_valid_payment(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $tid = 'TID_' . uniqid();
        $params = $this->makeCallbackParams($order->order_number, 50000);

        Http::fake([
            'fcstdpay.inicis.com/api/payAuth' => Http::response(
                $this->makeAuthorizeResponse($tid, $order->order_number, 50000),
                200
            ),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/callback', $params);

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);

        $payment = $order->payment;
        $payment->refresh();
        $this->assertEquals($tid, $payment->transaction_id);
        $this->assertEquals('APP12345', $payment->card_approval_number);
    }

    public function test_auth_callback_user_cancel_2001_redirects_without_error_query(): void
    {
        // 사용자가 결제창을 X 또는 '취소' 버튼으로 종료한 경우 — 에러 토스트 노출 X.
        // NHN KCP 의 CANCEL_RES_CODES 패턴과 동일하게 조용히 체크아웃으로 복귀.
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams('ORD-TEST-99999', 50000, [
            'resultCode' => '2001',
            'resultMsg' => '사용자가 취소를 요청하였습니다.',
        ]);

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/callback', $params);

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringNotContainsString('error=', $location, 'user cancel must not append error query');
        $this->assertStringNotContainsString('message=', $location, 'user cancel must not append message query');
    }

    public function test_auth_callback_user_cancel_message_keyword_redirects_without_error_query(): void
    {
        // resultCode 가 표준 cancel 코드가 아니지만 resultMsg 에 '취소' 키워드가
        // 포함된 경우도 사용자 취소로 간주 (KG 이니시스 버전별 코드 차이 대응).
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams('ORD-TEST-99999', 50000, [
            'resultCode' => '9998',
            'resultMsg' => '사용자가 결제를 취소하였습니다.',
        ]);

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/callback', $params);

        $response->assertRedirect();
        $this->assertStringNotContainsString('error=', $response->headers->get('Location'));
    }

    public function test_auth_callback_real_failure_still_redirects_with_error_query(): void
    {
        // 사용자 취소가 아닌 실제 결제 실패는 기존대로 error query 포함하여
        // 사용자에게 에러 토스트 노출.
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams('ORD-TEST-99999', 50000, [
            'resultCode' => '9999',
            'resultMsg' => 'PG 시스템 오류',
        ]);

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/callback', $params);

        $response->assertRedirect();
        $this->assertStringContainsString('error=9999', $response->headers->get('Location'));
    }

    public function test_auth_callback_redirects_to_fail_on_order_not_found(): void
    {
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams('NON_EXISTENT_ORDER', 50000);

        Http::fake([
            'fcstdpay.inicis.com/api/payAuth' => Http::response(
                $this->makeAuthorizeResponse('TID_NONE', 'NON_EXISTENT_ORDER', 50000),
                200
            ),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/callback', $params);

        $response->assertRedirect();
        $this->assertStringContainsString('error=order_not_found', $response->headers->get('Location'));
    }

    public function test_auth_callback_redirects_to_fail_on_pg_result_code_not_0000(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams($order->order_number, 50000);

        Http::fake([
            'fcstdpay.inicis.com/api/payAuth' => Http::response([
                'resultCode' => '9999',
                'resultMsg' => '승인 실패',
            ], 200),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/callback', $params);

        $response->assertRedirect();
        $this->assertStringContainsString('error=9999', $response->headers->get('Location'));
    }

    public function test_auth_callback_sends_net_cancel_and_redirects_to_fail_on_authorize_http_error(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams($order->order_number, 50000);

        Http::fake([
            'fcstdpay.inicis.com/api/payAuth' => Http::response(null, 500),
            'stginiapi.inicis.com/api/v1/netcancel' => Http::response('OK', 200),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/callback', $params);

        $response->assertRedirect();
        $this->assertStringContainsString('error=authorize_failed', $response->headers->get('Location'));
    }

    public function test_auth_callback_redirects_to_fail_on_missing_params(): void
    {
        $this->mockPluginSettings();

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/callback', [
            'resultCode' => '0000',
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('error=invalid_params', $response->headers->get('Location'));
    }

    public function test_auth_callback_redirects_to_custom_success_url(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings(['redirect_success_url' => '/custom/payment/{orderId}/done']);

        $tid = 'TID_CUSTOM';
        $params = $this->makeCallbackParams($order->order_number, 50000);

        Http::fake([
            'fcstdpay.inicis.com/api/payAuth' => Http::response(
                $this->makeAuthorizeResponse($tid, $order->order_number, 50000),
                200
            ),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/callback', $params);

        $response->assertRedirect("/custom/payment/{$order->order_number}/done");
    }

    public function test_auth_callback_detects_mobile_device(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams($order->order_number, 50000);

        Http::fake([
            'fcstdpay.inicis.com/api/payAuth' => Http::response(
                $this->makeAuthorizeResponse('TID_MOBILE', $order->order_number, 50000),
                200
            ),
        ]);

        $response = $this->post(
            '/plugins/sirsoft-pay_kginicis/payment/callback',
            $params,
            ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X)']
        );

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $payment = $order->payment;
        $payment->refresh();
        $this->assertEquals('mobile', $payment->payment_device);
    }

    // ===== 가상계좌 입금 통보 테스트 =====

    public function test_vbank_notify_returns_ok_on_successful_deposit(): void
    {
        $order = $this->createTestOrder(30000);
        $this->mockPluginSettings();

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/vbank-notify', $this->makeVbankNotifyPayload([
            'no_oid' => $order->order_number,
            'amt_input' => '30000',
        ]));

        $response->assertOk();
        $this->assertEquals('OK', $response->getContent());

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
    }

    /**
     * 동일 TID 가 두 번 통보되어도 중복 결제 처리하지 않고 OK 반환 (replay 방어).
     */
    public function test_vbank_notify_is_idempotent_on_duplicate_tid(): void
    {
        $order = $this->createTestOrder(30000);
        $this->mockPluginSettings();

        $payload = $this->makeVbankNotifyPayload([
            'no_tid' => 'VBANK_TID_DUP',
            'no_oid' => $order->order_number,
            'amt_input' => '30000',
        ]);

        $this->post('/plugins/sirsoft-pay_kginicis/payment/vbank-notify', $payload)->assertOk();
        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/vbank-notify', $payload);

        $response->assertOk();
        $this->assertEquals('OK', $response->getContent(), '재처리 요청도 OK 반환 (replay 방어)');
    }

    public function test_vbank_notify_returns_fail_on_order_not_found(): void
    {
        $this->mockPluginSettings();

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/vbank-notify', $this->makeVbankNotifyPayload([
            'no_oid' => 'NON_EXISTENT_ORDER',
        ]));

        $response->assertOk();
        $this->assertEquals('FAIL', $response->getContent());
    }

    /**
     * KG 이니시스 PC 가상계좌 입금통보 페이로드 빌더.
     *
     * 공식 매뉴얼 https://manual.inicis.com/pay/etc-noti.html#pc 의 필드명을 사용한다.
     * 테스트마다 일부 필드만 override 하기 위한 헬퍼.
     *
     * @param  array  $overrides  필드 override
     * @return array 입금통보 페이로드
     */
    private function makeVbankNotifyPayload(array $overrides = []): array
    {
        return array_merge([
            'no_tid'       => 'VBANK_TID_' . uniqid(),
            'no_oid'       => 'ORD-TEST-VBANK',
            'id_merchant'  => 'INIpayTest',
            'dt_trans'     => now()->format('Ymd'),
            'tm_trans'     => now()->format('His'),
            'cd_bank'      => '04',
            'no_vacct'     => '1234567890',
            'amt_input'    => '30000',
            'nm_inputbank' => '국민은행',
            'nm_input'     => '홍길동',
        ], $overrides);
    }
}
