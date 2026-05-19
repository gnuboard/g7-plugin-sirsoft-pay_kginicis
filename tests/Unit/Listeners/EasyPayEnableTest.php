<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Unit\Listeners;

use PHPUnit\Framework\TestCase;
use Plugins\Sirsoft\PayKginicis\Listeners\RegisterPgProviderListener;

/**
 * RegisterPgProviderListener::getEnabledEasyPays 의 라이브 자격증명 게이트 검증.
 *
 * 의도된 동작:
 *   - 테스트 모드: 토글만 켜져 있으면 enabled (KG 이니시스 테스트 키는 내장)
 *   - 실결제 모드: live_mid + live_sign_key 둘 다 채워져 있을 때만 enabled
 *   - 실결제 모드 + 자격증명 누락: 모든 간편결제 비노출 (결제창 실패 사전 차단)
 */
class EasyPayEnableTest extends TestCase
{
    private function invoke(array $settings): array
    {
        $reflection = new \ReflectionClass(RegisterPgProviderListener::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('getEnabledEasyPays');
        $method->setAccessible(true);

        return $method->invoke($instance, $settings);
    }

    public function test_test_mode_returns_all_enabled_toggles(): void
    {
        $result = $this->invoke([
            'is_test_mode' => true,
            'easy_pay_samsung_pay' => true,
            'easy_pay_lpay' => true,
            'easy_pay_kakaopay' => true,
        ]);

        $this->assertSame(['SAMSUNG', 'LPAY', 'KAKAOPAY'], $result);
    }

    public function test_test_mode_with_only_one_toggle(): void
    {
        $result = $this->invoke([
            'is_test_mode' => true,
            'easy_pay_kakaopay' => true,
        ]);

        $this->assertSame(['KAKAOPAY'], $result);
    }

    public function test_test_mode_with_no_toggles_returns_empty(): void
    {
        $this->assertSame([], $this->invoke(['is_test_mode' => true]));
    }

    public function test_live_mode_with_full_credentials_returns_enabled_toggles(): void
    {
        $result = $this->invoke([
            'is_test_mode' => false,
            'live_mid' => 'SIRshop001',
            'live_sign_key' => 'live_sign_key_value',
            'easy_pay_samsung_pay' => true,
            'easy_pay_kakaopay' => true,
        ]);

        $this->assertSame(['SAMSUNG', 'KAKAOPAY'], $result);
    }

    public function test_live_mode_without_live_mid_returns_empty(): void
    {
        $result = $this->invoke([
            'is_test_mode' => false,
            'live_mid' => '',
            'live_sign_key' => 'live_sign_key_value',
            'easy_pay_samsung_pay' => true,
            'easy_pay_lpay' => true,
        ]);

        $this->assertSame([], $result);
    }

    public function test_live_mode_without_live_sign_key_returns_empty(): void
    {
        $result = $this->invoke([
            'is_test_mode' => false,
            'live_mid' => 'SIRshop001',
            'live_sign_key' => '',
            'easy_pay_kakaopay' => true,
        ]);

        $this->assertSame([], $result);
    }

    public function test_live_mode_with_whitespace_only_credentials_returns_empty(): void
    {
        $result = $this->invoke([
            'is_test_mode' => false,
            'live_mid' => '   ',
            'live_sign_key' => "  \t  ",
            'easy_pay_samsung_pay' => true,
        ]);

        $this->assertSame([], $result);
    }

    public function test_live_mode_with_null_credentials_returns_empty(): void
    {
        $result = $this->invoke([
            'is_test_mode' => false,
            'live_mid' => null,
            'live_sign_key' => null,
            'easy_pay_kakaopay' => true,
        ]);

        $this->assertSame([], $result);
    }

    public function test_default_to_test_mode_when_flag_missing(): void
    {
        // is_test_mode 누락 → 안전한 기본값 (test 로 처리, live 자격증명 검사 안 함)
        $result = $this->invoke([
            'easy_pay_lpay' => true,
        ]);

        $this->assertSame(['LPAY'], $result);
    }
}
