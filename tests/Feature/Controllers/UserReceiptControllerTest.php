<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use App\Models\User;
use App\Services\PluginSettingsService;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

class UserReceiptControllerTest extends PluginTestCase
{
    public function test_receipt_response_includes_easy_pay_display_label(): void
    {
        $this->mockPluginSettings();
        $user = User::factory()->create();
        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-RECEIPT-' . random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'total_amount' => 1000,
            'total_due_amount' => 0,
            'total_paid_amount' => 1000,
            'paid_at' => now(),
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'kginicis',
            'transaction_id' => 'StdpayCARDINIpayTest20260521124857685014',
            'embedded_pg_provider' => 'naverpay',
            'paid_amount_local' => 1000,
            'payment_meta' => [
                'selected_payment_method' => 'kginicis_naverpay',
                'embedded_pg_provider' => 'naverpay',
                'embedded_pg_provider_label' => '네이버페이',
            ],
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/plugins/sirsoft-pay_kginicis/user/orders/{$order->order_number}/receipt");

        $response->assertOk()
            ->assertJsonPath('payment_method_label', '신용카드')
            ->assertJsonPath('payment_method_display_label', '네이버페이 (신용카드)')
            ->assertJsonPath('selected_payment_method', 'kginicis_naverpay')
            ->assertJsonPath('embedded_pg_provider', 'naverpay')
            ->assertJsonPath('embedded_pg_provider_label', '네이버페이');
    }

    public function test_receipt_response_keeps_base_payment_label_without_easy_pay_context(): void
    {
        $this->mockPluginSettings();
        $user = User::factory()->create();
        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-RECEIPT-' . random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'total_amount' => 1000,
            'total_due_amount' => 0,
            'total_paid_amount' => 1000,
            'paid_at' => now(),
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'kginicis',
            'transaction_id' => 'StdpayCARDINIpayTest20260521124857685014',
            'paid_amount_local' => 1000,
            'payment_meta' => ['pay_method' => 'Card'],
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/plugins/sirsoft-pay_kginicis/user/orders/{$order->order_number}/receipt");

        $response->assertOk()
            ->assertJsonPath('payment_method_label', '신용카드')
            ->assertJsonPath('payment_method_display_label', '신용카드')
            ->assertJsonPath('embedded_pg_provider', null)
            ->assertJsonPath('embedded_pg_provider_label', null);
    }

    private function mockPluginSettings(): void
    {
        $settingsMock = $this->createMock(PluginSettingsService::class);
        $settingsMock->method('get')->willReturn(['is_test_mode' => true]);

        $this->app->instance(PluginSettingsService::class, $settingsMock);
    }
}
