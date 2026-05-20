<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use Mockery;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

class CbtPaymentControllerTest extends PluginTestCase
{
    public function test_cbt_callback_accepts_manual_ok_result_code_and_completes_payment(): void
    {
        $order = $this->makePendingJpyOrder('JP-ORDER-001', 100);

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->with('JP-ORDER-001')
            ->andReturn($order);
        $orderService->shouldReceive('completePayment')
            ->once()
            ->with($order, Mockery::on(function (array $paymentData): bool {
                $meta = $paymentData['payment_meta'] ?? [];

                return $paymentData['transaction_id'] === 'CBT_TID_001'
                    && $paymentData['card_approval_number'] === 'APPROVE1'
                    && ($meta['is_cbt'] ?? false) === true
                    && ($meta['cbt_mid'] ?? '') === KgInicisApiService::JAPAN_TEST_MID
                    && ($meta['cbt_sid'] ?? '') === 'SID001'
                    && ($meta['pay_method'] ?? '') === 'CARD';
            }), 100)
            ->andReturn($order);

        $apiService = Mockery::mock(KgInicisApiService::class);
        $apiService->shouldReceive('getJapanMid')->andReturn(KgInicisApiService::JAPAN_TEST_MID);
        $apiService->shouldReceive('isTestMode')->andReturn(true);
        $apiService->shouldNotReceive('refundCbtPayment');
        $apiService->shouldReceive('approveCbtPayment')
            ->with('SID001')
            ->andReturn([
                'resultCode' => 'OK',
                'resultMsg' => 'SUCCESS',
                'tid' => 'CBT_TID_001',
                'paymethod' => 'CARD',
                'approve' => 'APPROVE1',
            ]);

        $this->app->instance(OrderProcessingService::class, $orderService);
        $this->app->instance(KgInicisApiService::class, $apiService);

        $response = $this->get('/plugins/sirsoft-pay_kginicis/payment/cbt/callback?'
            . http_build_query([
                'oid' => 'JP-ORDER-001',
                'sid' => 'SID001',
                'resultCode' => 'OK',
                'mid' => KgInicisApiService::JAPAN_TEST_MID,
                'paymethod' => 'CARD',
            ]));

        $response->assertRedirect('http://localhost/shop/orders/JP-ORDER-001/complete');
    }

    public function test_cbt_callback_auto_refunds_approved_payment_when_local_completion_fails(): void
    {
        $order = $this->makePendingJpyOrder('JP-ORDER-003', 100);

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->with('JP-ORDER-003')
            ->andReturn($order);
        $orderService->shouldReceive('completePayment')
            ->once()
            ->andThrow(new \RuntimeException('local write failed'));

        $apiService = Mockery::mock(KgInicisApiService::class);
        $apiService->shouldReceive('getJapanMid')->andReturn(KgInicisApiService::JAPAN_TEST_MID);
        $apiService->shouldReceive('isTestMode')->andReturn(true);
        $apiService->shouldReceive('approveCbtPayment')
            ->with('SID003')
            ->andReturn([
                'resultCode' => 'OK',
                'tid' => 'CBT_TID_003',
                'paymethod' => 'CARD',
                'amount' => 100,
            ]);
        $apiService->shouldReceive('refundCbtPayment')
            ->once()
            ->with(
                'CBT_TID_003',
                null,
                Mockery::on(fn (string $msg): bool => str_contains($msg, 'local write failed')),
            )
            ->andReturn(['resultCode' => '00']);

        $this->app->instance(OrderProcessingService::class, $orderService);
        $this->app->instance(KgInicisApiService::class, $apiService);

        $response = $this->get('/plugins/sirsoft-pay_kginicis/payment/cbt/callback?'
            . http_build_query([
                'oid' => 'JP-ORDER-003',
                'sid' => 'SID003',
                'resultCode' => 'OK',
                'mid' => KgInicisApiService::JAPAN_TEST_MID,
            ]));

        $response->assertRedirect('http://localhost/shop/checkout?error=cbt_failed&orderId=JP-ORDER-003');
    }

    public function test_cbt_callback_auto_refunds_when_approved_amount_mismatches_order(): void
    {
        $order = $this->makePendingJpyOrder('JP-ORDER-004', 100);

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->with('JP-ORDER-004')
            ->andReturn($order);
        $orderService->shouldNotReceive('completePayment');

        $apiService = Mockery::mock(KgInicisApiService::class);
        $apiService->shouldReceive('getJapanMid')->andReturn(KgInicisApiService::JAPAN_TEST_MID);
        $apiService->shouldReceive('approveCbtPayment')
            ->with('SID004')
            ->andReturn([
                'resultCode' => 'OK',
                'tid' => 'CBT_TID_004',
                'paymethod' => 'CARD',
                'amount' => 99,
            ]);
        $apiService->shouldReceive('refundCbtPayment')
            ->once()
            ->with(
                'CBT_TID_004',
                null,
                Mockery::on(fn (string $msg): bool => str_contains($msg, 'amount mismatch')),
            )
            ->andReturn(['resultCode' => '00']);

        $this->app->instance(OrderProcessingService::class, $orderService);
        $this->app->instance(KgInicisApiService::class, $apiService);

        $response = $this->get('/plugins/sirsoft-pay_kginicis/payment/cbt/callback?'
            . http_build_query([
                'oid' => 'JP-ORDER-004',
                'sid' => 'SID004',
                'resultCode' => 'OK',
                'mid' => KgInicisApiService::JAPAN_TEST_MID,
            ]));

        $response->assertRedirect('http://localhost/shop/checkout?error=cbt_failed&orderId=JP-ORDER-004');
    }

    public function test_cbt_hash_data_rejects_amount_mismatch(): void
    {
        $order = $this->makePendingJpyOrder('JP-ORDER-002', 100);

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->with('JP-ORDER-002')
            ->andReturn($order);

        $apiService = Mockery::mock(KgInicisApiService::class);
        $apiService->shouldReceive('isJapanEnabled')->andReturn(true);
        $apiService->shouldReceive('isJapanConfigured')->andReturn(true);

        $this->app->instance(OrderProcessingService::class, $orderService);
        $this->app->instance(KgInicisApiService::class, $apiService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/cbt/hash-data', [
            'oid' => 'JP-ORDER-002',
            'price' => 99,
            'timestamp' => date('YmdHis'),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Payment amount does not match the order amount.');
    }

    public function test_cbt_hash_data_rejects_order_buyer_mismatch(): void
    {
        $order = $this->makePendingJpyOrder('JP-ORDER-005', 100);
        $order->setRelation('shippingAddress', new OrderAddress([
            'address_type' => 'shipping',
            'orderer_email' => 'buyer@example.jp',
            'orderer_phone' => '090-1234-5678',
        ]));

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->with('JP-ORDER-005')
            ->andReturn($order);

        $apiService = Mockery::mock(KgInicisApiService::class);
        $apiService->shouldReceive('isJapanEnabled')->andReturn(true);
        $apiService->shouldReceive('isJapanConfigured')->andReturn(true);
        $apiService->shouldNotReceive('generateCbtHashData');

        $this->app->instance(OrderProcessingService::class, $orderService);
        $this->app->instance(KgInicisApiService::class, $apiService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/cbt/hash-data', [
            'oid' => 'JP-ORDER-005',
            'price' => 100,
            'timestamp' => date('YmdHis'),
            'buyer_email' => 'attacker@example.jp',
            'buyer_phone' => '09012345678',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Order buyer verification failed.');
    }

    public function test_cbt_hash_data_accepts_matching_order_buyer_context(): void
    {
        $order = $this->makePendingJpyOrder('JP-ORDER-006', 100);
        $order->setRelation('shippingAddress', new OrderAddress([
            'address_type' => 'shipping',
            'orderer_email' => 'buyer@example.jp',
            'orderer_phone' => '090-1234-5678',
        ]));

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->with('JP-ORDER-006')
            ->andReturn($order);

        $apiService = Mockery::mock(KgInicisApiService::class);
        $apiService->shouldReceive('isJapanEnabled')->andReturn(true);
        $apiService->shouldReceive('isJapanConfigured')->andReturn(true);
        $apiService->shouldReceive('getJapanMid')->andReturn(KgInicisApiService::JAPAN_TEST_MID);
        $apiService->shouldReceive('generateCbtHashData')
            ->with(KgInicisApiService::JAPAN_TEST_MID, Mockery::type('string'), 100, 'JP-ORDER-006')
            ->andReturn('hash-ok');

        $this->app->instance(OrderProcessingService::class, $orderService);
        $this->app->instance(KgInicisApiService::class, $apiService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/cbt/hash-data', [
            'oid' => 'JP-ORDER-006',
            'price' => 100,
            'timestamp' => date('YmdHis'),
            'buyer_email' => 'BUYER@example.jp',
            'buyer_phone' => '09012345678',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.hash_data', 'hash-ok');
    }

    private function makePendingJpyOrder(string $orderNumber, int $amount): Order
    {
        $order = new Order();
        $order->order_number = $orderNumber;
        $order->order_status = OrderStatusEnum::PENDING_ORDER;
        $order->currency = 'JPY';
        $order->total_due_amount = $amount;

        return $order;
    }
}
