<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Unit\Listeners;

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
}
