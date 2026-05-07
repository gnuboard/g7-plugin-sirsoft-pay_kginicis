<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Unit\Services;

use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

class KgInicisApiServiceTest extends PluginTestCase
{
    private const TEST_MID = 'INIpayTest';

    private const TEST_SIGN_KEY = 'SU5JTElURV9UUklQTEVERVNfS0VZU1RS';

    private const TEST_INIAPI_KEY = 'ItEQKi3rY7uvDS8l';

    private const TEST_INIAPI_IV = 'HYb3yQ4f65QL89==';

    private function makeService(array $settingsOverrides = []): KgInicisApiService
    {
        $defaults = [
            'is_test_mode' => true,
            'test_mid' => self::TEST_MID,
            'test_sign_key' => self::TEST_SIGN_KEY,
            'test_iniapi_key' => self::TEST_INIAPI_KEY,
            'test_iniapi_iv' => self::TEST_INIAPI_IV,
            'live_mid' => '',
            'live_sign_key' => '',
            'live_iniapi_key' => '',
            'live_iniapi_iv' => '',
        ];

        $settingsMock = $this->createMock(PluginSettingsService::class);
        $settingsMock->method('get')
            ->willReturn(array_merge($defaults, $settingsOverrides));

        return new KgInicisApiService($settingsMock);
    }

    public function test_get_mid_returns_test_mid_in_test_mode(): void
    {
        $service = $this->makeService();

        $this->assertEquals(self::TEST_MID, $service->getMid());
    }

    public function test_get_mid_returns_live_mid_in_live_mode(): void
    {
        $service = $this->makeService([
            'is_test_mode' => false,
            'live_mid' => 'live_mid_value',
            'live_sign_key' => 'live_sign_key_value',
        ]);

        $this->assertEquals('live_mid_value', $service->getMid());
    }

    public function test_get_js_url_returns_test_url_in_test_mode(): void
    {
        $service = $this->makeService();

        $this->assertEquals('https://stgstdpay.inicis.com/stdjs/INIStdPay.js', $service->getJsUrl());
    }

    public function test_get_js_url_returns_live_url_in_live_mode(): void
    {
        $service = $this->makeService(['is_test_mode' => false]);

        $this->assertEquals('https://stdpay.inicis.com/stdjs/INIStdPay.js', $service->getJsUrl());
    }

    public function test_get_mkey_returns_sha256_of_sign_key(): void
    {
        $service = $this->makeService();

        $expected = hash('sha256', self::TEST_SIGN_KEY);
        $this->assertEquals($expected, $service->getMKey());
    }

    public function test_generate_signature_returns_correct_sha256(): void
    {
        $service = $this->makeService();

        $oid = 'ORD-001';
        $price = 50000;
        $timestamp = '1714000000000';

        $expected = hash('sha256', 'oid=' . $oid . '&price=' . $price . '&timestamp=' . $timestamp);
        $this->assertEquals($expected, $service->generateSignature($oid, $price, $timestamp));
    }

    public function test_generate_signature_differs_for_different_amounts(): void
    {
        $service = $this->makeService();

        $sig1 = $service->generateSignature('ORD-001', 50000, '1714000000000');
        $sig2 = $service->generateSignature('ORD-001', 99999, '1714000000000');

        $this->assertNotEquals($sig1, $sig2);
    }

    public function test_authorize_payment_posts_to_auth_url_and_returns_response(): void
    {
        $service = $this->makeService();

        $authUrl = 'https://stginiapi.inicis.com/api/v1/auth';
        $authToken = 'AUTH_TOKEN_TEST';

        Http::fake([
            $authUrl => Http::response([
                'resultCode' => '0000',
                'resultMsg' => '성공',
                'tid' => 'TID_123456',
                'payMethod' => 'Card',
            ], 200),
        ]);

        $result = $service->authorizePayment($authUrl, $authToken);

        $this->assertEquals('0000', $result['resultCode']);
        $this->assertEquals('TID_123456', $result['tid']);

        Http::assertSent(function ($request) use ($authUrl, $authToken) {
            return $request->url() === $authUrl
                && $request['authToken'] === $authToken;
        });
    }

    public function test_authorize_payment_throws_on_http_error(): void
    {
        $service = $this->makeService();

        Http::fake([
            '*' => Http::response(null, 500),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/HTTP 500/');

        $service->authorizePayment('https://stginiapi.inicis.com/api/v1/auth', 'TOKEN');
    }

    public function test_send_net_cancel_posts_auth_token_to_net_cancel_url(): void
    {
        $service = $this->makeService();

        $netCancelUrl = 'https://stginiapi.inicis.com/api/v1/netcancel';
        $authToken = 'AUTH_TOKEN_TEST';

        Http::fake([
            $netCancelUrl => Http::response('OK', 200),
        ]);

        $service->sendNetCancel($netCancelUrl, $authToken);

        Http::assertSent(function ($request) use ($netCancelUrl, $authToken) {
            return $request->url() === $netCancelUrl
                && $request['authToken'] === $authToken;
        });
    }

    public function test_send_net_cancel_does_not_throw_on_http_error(): void
    {
        $service = $this->makeService();

        Http::fake([
            '*' => Http::response(null, 500),
        ]);

        // 망취소 실패는 무시
        $service->sendNetCancel('https://stginiapi.inicis.com/api/v1/netcancel', 'TOKEN');

        $this->assertTrue(true);
    }

    public function test_cancel_payment_sends_full_refund_request(): void
    {
        $service = $this->makeService();

        Http::fake([
            'stginiapi.inicis.com/api/v1/refund' => Http::response([
                'resultCode' => '00',
                'resultMsg' => '취소 성공',
                'tid' => 'TID_CANCEL',
            ], 200),
        ]);

        $result = $service->cancelPayment('TID_ORIG', 'Card', null, '고객 요청');

        $this->assertEquals('00', $result['resultCode']);

        Http::assertSent(function ($request) {
            return $request['type'] === 'Refund'
                && $request['paymethod'] === 'Card'
                && $request['mid'] === self::TEST_MID
                && $request['tid'] === 'TID_ORIG'
                && ! isset($request['price'])
                && isset($request['hashData'])
                && isset($request['timestamp'])
                && isset($request['clientIp']);
        });
    }

    public function test_cancel_payment_sends_partial_refund_request(): void
    {
        $service = $this->makeService();

        Http::fake([
            'stginiapi.inicis.com/api/v1/refund' => Http::response([
                'resultCode' => '00',
                'resultMsg' => '부분 취소 성공',
            ], 200),
        ]);

        $result = $service->cancelPayment('TID_ORIG', 'Card', 10000, '부분 취소');

        $this->assertEquals('00', $result['resultCode']);

        Http::assertSent(function ($request) {
            return $request['type'] === 'PartialRefund'
                && $request['price'] == 10000;
        });
    }

    public function test_cancel_payment_throws_on_non_00_result_code(): void
    {
        $service = $this->makeService();

        Http::fake([
            'stginiapi.inicis.com/api/v1/refund' => Http::response([
                'resultCode' => '9999',
                'resultMsg' => '취소 실패',
            ], 200),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('취소 실패');

        $service->cancelPayment('TID_ORIG', 'Card', null, '취소');
    }

    public function test_cancel_payment_throws_on_http_error(): void
    {
        $service = $this->makeService();

        Http::fake([
            '*' => Http::response(null, 500),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/HTTP 500/');

        $service->cancelPayment('TID_ORIG', 'Card', null, '취소');
    }

    public function test_cancel_payment_hash_data_includes_price_for_partial_refund(): void
    {
        $service = $this->makeService();

        $tid = 'TID_ORIG';
        $payMethod = 'Card';
        $cancelPrice = 10000;

        Http::fake([
            'stginiapi.inicis.com/api/v1/refund' => Http::response([
                'resultCode' => '00',
                'resultMsg' => '성공',
            ], 200),
        ]);

        $service->cancelPayment($tid, $payMethod, $cancelPrice, '부분취소');

        Http::assertSent(function ($request) use ($tid, $payMethod, $cancelPrice) {
            $hashBase = self::TEST_INIAPI_KEY
                . 'PartialRefund'
                . $payMethod
                . $request['timestamp']
                . $request['clientIp']
                . self::TEST_MID
                . $tid
                . $cancelPrice;
            $expectedHash = hash('sha512', $hashBase);

            return $request['hashData'] === $expectedHash;
        });
    }
}
