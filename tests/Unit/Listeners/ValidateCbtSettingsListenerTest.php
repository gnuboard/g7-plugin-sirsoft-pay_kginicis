<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Unit\Listeners;

use App\Services\PluginSettingsService;
use Illuminate\Validation\ValidationException;
use Plugins\Sirsoft\PayKginicis\Listeners\ValidateCbtSettingsListener;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

class ValidateCbtSettingsListenerTest extends PluginTestCase
{
    public function test_ignores_other_plugins(): void
    {
        $listener = new ValidateCbtSettingsListener();

        $listener->validateBeforeSave('other-plugin', [
            'japan_enabled' => true,
            'is_test_mode' => false,
        ]);

        $this->assertTrue(true);
    }

    public function test_live_japan_payment_requires_live_credentials(): void
    {
        $this->mockCurrentSettings([]);

        $listener = new ValidateCbtSettingsListener();

        $this->expectException(ValidationException::class);

        $listener->validateBeforeSave('sirsoft-pay_kginicis', [
            'japan_enabled' => true,
            'is_test_mode' => false,
            'live_japan_mid' => '',
            'live_japan_sign_key' => '',
        ]);
    }

    public function test_live_japan_payment_rejects_sample_jppg_display_values(): void
    {
        $this->mockCurrentSettings([]);

        $listener = new ValidateCbtSettingsListener();

        try {
            $listener->validateBeforeSave('sirsoft-pay_kginicis', $this->validLiveSettings([
                'japan_merchant_name' => 'サンプルストア',
            ]));
            $this->fail('ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('japan_merchant_name', $e->errors());
        }
    }

    public function test_live_japan_payment_accepts_real_jppg_values(): void
    {
        $this->mockCurrentSettings([]);

        $listener = new ValidateCbtSettingsListener();
        $listener->validateBeforeSave('sirsoft-pay_kginicis', $this->validLiveSettings());

        $this->assertTrue(true);
    }

    public function test_test_mode_requires_test_cbt_hash_key_when_japan_enabled(): void
    {
        $this->mockCurrentSettings([]);

        $listener = new ValidateCbtSettingsListener();

        $this->expectException(ValidationException::class);

        $listener->validateBeforeSave('sirsoft-pay_kginicis', [
            'japan_enabled' => true,
            'is_test_mode' => true,
            'test_japan_sign_key' => '',
        ]);
    }

    private function mockCurrentSettings(array $settings): void
    {
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')->willReturn($settings);
        $this->app->instance(PluginSettingsService::class, $mock);
    }

    private function validLiveSettings(array $overrides = []): array
    {
        return array_merge([
            'japan_enabled' => true,
            'is_test_mode' => false,
            'live_japan_mid' => 'JPLIVE001',
            'live_japan_sign_key' => 'live-secret-key',
            'japan_merchant_name' => '実店舗ストア',
            'japan_merchant_name_kana' => 'ジツテンポストア',
            'japan_merchant_name_alphabet' => 'Real Store',
            'japan_merchant_name_short' => 'リアル',
            'japan_contact_name' => 'Customer Support',
            'japan_contact_email' => 'support@real.example',
            'japan_contact_phone' => '03-1111-2222',
            'japan_contact_opening_hours' => '09:00-18:00',
        ], $overrides);
    }
}
