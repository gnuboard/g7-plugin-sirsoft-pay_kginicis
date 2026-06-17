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

    public function test_naverpay_uses_brand_description_by_default(): void
    {
        // 신규 설치 기본값(defaults.json easy_pay_show_brand_button=true)에서는
        // 네이버페이가 브랜드 버튼(짧은 설명)으로 표시된다 — 커밋 a71a5dcb0 "간편결제 브랜드 버튼 표시 확장"이
        // 기본값을 brand 로 전환했다. (별도 설정으로 legacy 긴 설명도 선택 가능)
        $listener = new RegisterEasyPayMethodsListener();

        $methods = $listener->injectEasyPayMethods([
            ['id' => 'phone'],
            ['id' => 'point'],
        ]);

        $naverpay = collect($methods)->firstWhere('id', 'kginicis_naverpay');

        $this->assertSame('네이버페이 (KG이니시스)', $naverpay['name']['ko'] ?? null);
        $this->assertSame('네이버페이로 결제', $naverpay['description']['ko'] ?? null);
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
