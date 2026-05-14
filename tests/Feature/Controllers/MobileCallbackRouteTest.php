<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

class MobileCallbackRouteTest extends PluginTestCase
{
    /**
     * KG 이니시스 모바일 표준결제는 P_NEXT_URL 로 POST 콜백을 보낸다.
     * 라우트가 GET 만 허용하면 405 Method Not Allowed 회귀가 발생한다.
     * 가상계좌 결제 시 PO 운영 검증으로 발견된 회귀.
     */
    public function test_mobile_callback_route_accepts_post(): void
    {
        $response = $this->withoutMiddleware()->post(
            '/plugins/sirsoft-pay_kginicis/payment/mobile/callback',
            [
                'P_STATUS'  => '99',
                'P_RMESG1'  => 'test',
                'P_OID'     => 'TEST-OID-NOT-EXIST',
                'P_TID'     => 'TEST-TID',
                'P_REQ_URL' => 'https://stgstdpay.inicis.com/api/payAuth',
                'P_AMT'     => '1000',
                'idc_name'  => 'fc',
            ]
        );

        $this->assertNotSame(405, $response->getStatusCode(), 'POST callback must not return 405 Method Not Allowed');
    }

    public function test_mobile_callback_route_accepts_get(): void
    {
        $response = $this->withoutMiddleware()->get(
            '/plugins/sirsoft-pay_kginicis/payment/mobile/callback?P_STATUS=99&P_OID=TEST-OID-NOT-EXIST'
        );

        $this->assertNotSame(405, $response->getStatusCode(), 'GET callback should remain supported');
    }
}
