<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Unit\Listeners;

use App\Services\PluginSettingsService;
use Plugins\Sirsoft\PayKginicis\Listeners\RegisterEasyPayMethodsListener;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

class RegisterEasyPayMethodsListenerTest extends PluginTestCase
{
    public function test_injects_easy_pay_methods_between_phone_and_point(): void
    {
        $listener = new RegisterEasyPayMethodsListener();

        $methods = $listener->injectEasyPayMethods([
            ['id' => 'card'],
            ['id' => 'phone'],
            ['id' => 'point'],
            ['id' => 'deposit'],
        ]);

        $this->assertSame([
            'card',
            'phone',
            'kginicis_samsung_pay',
            'kginicis_naverpay',
            'kginicis_lpay',
            'kginicis_kakaopay',
            'kginicis_japan_paypay',
            'kginicis_japan_cvs',
            'point',
            'deposit',
        ], array_column($methods, 'id'));
    }

    public function test_easy_pay_methods_do_not_require_pg_provider_in_saved_defaults(): void
    {
        $listener = new RegisterEasyPayMethodsListener();

        $methods = $listener->injectEasyPayMethods([
            ['id' => 'phone'],
            ['id' => 'point'],
        ]);

        $easyPayMethods = array_filter(
            $methods,
            fn (array $method): bool => str_starts_with((string) ($method['id'] ?? ''), 'kginicis_')
        );

        $this->assertCount(6, $easyPayMethods);

        foreach ($easyPayMethods as $method) {
            $this->assertArrayHasKey('defaults', $method);
            $this->assertNull($method['defaults']['pg_provider'] ?? null);
            $this->assertFalse($method['defaults']['is_active'] ?? true);
        }
    }

    public function test_naverpay_uses_legacy_description_without_brand_button_setting(): void
    {
        // 테스트처럼 플러그인 설정이 아직 주입되지 않은 fallback 환경에서는
        // 기존 긴 설명을 유지한다. 브랜드 버튼 설정이 켜진 경우는 아래 테스트에서 별도 검증.
        $listener = new RegisterEasyPayMethodsListener();

        $methods = $listener->injectEasyPayMethods([
            ['id' => 'phone'],
            ['id' => 'point'],
        ]);

        $naverpay = collect($methods)->firstWhere('id', 'kginicis_naverpay');

        $this->assertSame('네이버페이 (KG이니시스)', $naverpay['name']['ko'] ?? null);
        $this->assertSame('네이버페이로 결제 — KG 이니시스를 통해 처리', $naverpay['description']['ko'] ?? null);
    }

    public function test_brand_button_option_uses_short_checkout_description_for_naverpay(): void
    {
        $settings = $this->createMock(PluginSettingsService::class);
        $settings->method('get')
            ->willReturnCallback(function (string $identifier, ?string $key = null, mixed $default = null): mixed {
                if ($identifier === 'sirsoft-pay_kginicis' && $key === 'easy_pay_show_brand_button') {
                    return true;
                }

                return $default;
            });
        $this->app->instance(PluginSettingsService::class, $settings);

        $listener = new RegisterEasyPayMethodsListener();

        $methods = $listener->injectEasyPayMethods([
            ['id' => 'phone'],
            ['id' => 'point'],
        ]);

        $naverpay = collect($methods)->firstWhere('id', 'kginicis_naverpay');

        $this->assertSame('네이버페이 (KG이니시스)', $naverpay['name']['ko'] ?? null);
        $this->assertSame('네이버페이로 결제', $naverpay['description']['ko'] ?? null);
        $this->assertSame('Pay with Naver Pay', $naverpay['description']['en'] ?? null);
        $this->assertSame('wallet', $naverpay['icon'] ?? null);
    }
}
