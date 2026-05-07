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
            'authUrl' => 'https://stginiapi.inicis.com/api/v1/auth',
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
            'stginiapi.inicis.com/api/v1/auth' => Http::response(
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

    public function test_auth_callback_redirects_to_fail_on_result_code_not_0000(): void
    {
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams('ORD-TEST-99999', 50000, [
            'resultCode' => '2001',
            'resultMsg' => '사용자 취소',
        ]);

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/callback', $params);

        $response->assertRedirect();
        $this->assertStringContainsString('error=2001', $response->headers->get('Location'));
    }

    public function test_auth_callback_redirects_to_fail_on_order_not_found(): void
    {
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams('NON_EXISTENT_ORDER', 50000);

        Http::fake([
            'stginiapi.inicis.com/api/v1/auth' => Http::response(
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
            'stginiapi.inicis.com/api/v1/auth' => Http::response([
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
            'stginiapi.inicis.com/api/v1/auth' => Http::response(null, 500),
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
            'stginiapi.inicis.com/api/v1/auth' => Http::response(
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
            'stginiapi.inicis.com/api/v1/auth' => Http::response(
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

        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/vbank-notify', [
            'tid' => 'VBANK_TID_001',
            'MOID' => $order->order_number,
            'TotPrice' => 30000,
            'resultCode' => '0000',
            'vbankNum' => '1234567890',
            'vbankName' => '국민은행',
            'vbankExpDate' => now()->addDays(3)->format('Ymd'),
        ]);

        $response->assertOk();
        $this->assertEquals('OK', $response->getContent());

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
    }

    public function test_vbank_notify_returns_ok_on_cancelled_deposit(): void
    {
        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/vbank-notify', [
            'tid' => 'VBANK_TID_002',
            'MOID' => 'ORD-TEST-CANCEL',
            'TotPrice' => 30000,
            'resultCode' => '9999',
        ]);

        $response->assertOk();
        $this->assertEquals('OK', $response->getContent());
    }

    public function test_vbank_notify_returns_fail_on_order_not_found(): void
    {
        $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/vbank-notify', [
            'tid' => 'VBANK_TID_003',
            'MOID' => 'NON_EXISTENT_ORDER',
            'TotPrice' => 30000,
            'resultCode' => '0000',
        ]);

        $response->assertOk();
        $this->assertEquals('FAIL', $response->getContent());
    }
}
