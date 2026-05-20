<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;

class RegisterPgProviderListener implements HookListenerInterface
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_kginicis';

    private const LIVE_MID_PREFIX = 'SIR';

    private const ESCROW_TEST_MID = 'iniescrow0';

    private const CBT_AUTH_URL_TEST = 'https://devcbt.inicis.com/cbtauth';

    private const CBT_AUTH_URL_LIVE = 'https://cbt.inicis.com/cbtauth';

/**

 * getSubscribedHooks

 *

 * @return array

 */

    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.payment.registered_pg_providers' => [
                'method' => 'registerProvider',
                'type' => 'filter',
                'priority' => 10,
            ],
            'sirsoft-ecommerce.payment.get_client_config' => [
                'method' => 'getClientConfig',
                'type' => 'filter',
                'priority' => 10,
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용 — 개별 메서드에서 처리)
     *
     * @param  mixed  ...$args  훅 인수
     */
    public function handle(...$args): void {}

    /**
     * PG 제공자 목록에 KG 이니시스 등록
     *
     * @param  array  $providers  기존 PG 제공자 목록
     * @return array KG 이니시스가 추가된 PG 제공자 목록
     */
    public function registerProvider(array $providers): array
    {
        $providers[] = [
            'id' => 'kginicis',
            'name' => function_exists('localized_label')
                ? localized_label(nameKey: 'sirsoft-pay_kginicis::provider.name')
                : ['ko' => 'KG이니시스', 'en' => 'KG Inicis'],
            'icon' => 'credit-card',
            'supported_methods' => ['card', 'bank_transfer', 'virtual_account', 'mobile'],
        ];

        return $providers;
    }

/**

 * getClientConfig

 *

 * @param  array  $config

 * @param  string  $provider

 * @return array

 */

    public function getClientConfig(array $config, string $provider): array
    {
        if ($provider !== 'kginicis') {
            return $config;
        }

        $settings = $this->getPluginSettings();
        $isTest = $settings['is_test_mode'] ?? true;

        $useEscrow = (bool) ($settings['use_escrow'] ?? false);

        return array_merge($config, [
            'mid' => $isTest
                ? ($useEscrow ? self::ESCROW_TEST_MID : ($settings['test_mid'] ?? ''))
                : $this->buildLiveMid($settings['live_mid'] ?? ''),
            'sdk_url' => $isTest
                ? 'https://stgstdpay.inicis.com/stdjs/INIStdPay.js'
                : 'https://stdpay.inicis.com/stdjs/INIStdPay.js',
            'callback_urls' => [
                'signature'           => '/plugins/sirsoft-pay_kginicis/payment/signature',
                'callback'            => '/plugins/sirsoft-pay_kginicis/payment/callback',
                'cbt_checkout_token'  => '/plugins/sirsoft-pay_kginicis/payment/cbt/checkout-token',
                'cbt_hash_data'       => '/plugins/sirsoft-pay_kginicis/payment/cbt/hash-data',
                'cbt_callback'        => '/plugins/sirsoft-pay_kginicis/payment/cbt/callback',
                'cbt_auth_url'        => $isTest ? self::CBT_AUTH_URL_TEST : self::CBT_AUTH_URL_LIVE,
                'mobile_signature'    => '/plugins/sirsoft-pay_kginicis/payment/mobile/signature',
                'mobile_callback'     => '/plugins/sirsoft-pay_kginicis/payment/mobile/callback',
                'mobile_vbank_notify' => '/plugins/sirsoft-pay_kginicis/payment/mobile/vbank-notify',
            ],
            'japan_enabled'              => $settings['japan_enabled'] ?? false,
            'japan_configured'           => $this->isJapanConfigured($settings, $isTest),
            'use_escrow'                 => $settings['use_escrow'] ?? false,
            'japan_mid'                  => $isTest
                ? KgInicisApiService::JAPAN_TEST_MID
                : ($settings['live_japan_mid'] ?? ''),
            'cbt_extra_data'             => $this->buildCbtExtraData($settings),
            'enabled_easy_pays'          => $this->getEnabledEasyPays($settings),
            'easy_pay_allow_with_other_pg' => (bool) ($settings['easy_pay_allow_with_other_pg'] ?? false),
            'use_credit_point'           => (bool) ($settings['use_credit_point'] ?? false),
        ]);
    }

    /**
     * 활성화된 간편결제 수단 목록 반환.
     *
     * 테스트 모드: KG 이니시스 공식 테스트 MID/sign_key 가 plugin code 에 내장되어
     *   있으므로 토글만 켜져 있으면 항상 노출 가능.
     * 실결제 모드: 가맹점이 자체 발급받은 live_mid 와 live_sign_key 가 모두
     *   채워져 있을 때만 노출. 미입력 상태로 노출 시 결제창 진입 후 실패가
     *   확실하므로 사용자 혼란 방지를 위해 사전 차단.
     *
     * @param  array  $settings  플러그인 설정
     * @return list<'SAMSUNG'|'LPAY'|'KAKAOPAY'>
     */
    private function getEnabledEasyPays(array $settings): array
    {
        $isTest = (bool) ($settings['is_test_mode'] ?? true);

        if (! $isTest) {
            $liveMid = trim((string) ($settings['live_mid'] ?? ''));
            $liveSignKey = trim((string) ($settings['live_sign_key'] ?? ''));
            if ($liveMid === '' || $liveSignKey === '') {
                return [];
            }
        }

        $enabled = [];
        if ($settings['easy_pay_samsung_pay'] ?? false) $enabled[] = 'SAMSUNG';
        if ($settings['easy_pay_lpay'] ?? false)        $enabled[] = 'LPAY';
        if ($settings['easy_pay_kakaopay'] ?? false)    $enabled[] = 'KAKAOPAY';
        return $enabled;
    }

    private function buildCbtExtraData(array $settings): array
    {
        return [
            'paymentUI' => [
                'language' => 'JP',
                'logoUrl' => '',
                'colorTheme' => 'blue2',
            ],
            'payment' => [
                // CVS 는 별도 NOTI/환불계좌 플로우가 필요하므로 현재 JPPG 카드만 노출한다.
                'paymethod' => ['CARD'],
                'card' => [
                    'payType' => ['one', 'installments'],
                    'installMonth' => [3, 5, 6, 10, 12],
                ],
            ],
            'gmoPayment' => [
                'merchantName' => $this->setting($settings, 'japan_merchant_name', 'サンプルストア'),
                'merchantNameKana' => $this->setting($settings, 'japan_merchant_name_kana', 'サンプルストア'),
                'merchantNameAlphabet' => $this->setting($settings, 'japan_merchant_name_alphabet', 'Sample Store'),
                'merchantNameShort' => $this->setting($settings, 'japan_merchant_name_short', 'サンプル'),
                'contactName' => $this->setting($settings, 'japan_contact_name', 'サポート窓口'),
                'contactEmail' => $this->setting($settings, 'japan_contact_email', 'support@example.com'),
                'contactPhone' => $this->setting($settings, 'japan_contact_phone', '0120-123-456'),
                'contactOpeningHours' => $this->setting($settings, 'japan_contact_opening_hours', '10:00-18:00'),
            ],
        ];
    }

    private function isJapanConfigured(array $settings, bool $isTest): bool
    {
        if (! (bool) ($settings['japan_enabled'] ?? false)) {
            return false;
        }

        if ($isTest) {
            return trim((string) ($settings['test_japan_sign_key'] ?? '')) !== '';
        }

        return trim((string) ($settings['live_japan_mid'] ?? '')) !== ''
            && trim((string) ($settings['live_japan_sign_key'] ?? '')) !== '';
    }

    private function setting(array $settings, string $key, string $default): string
    {
        $value = trim((string) ($settings[$key] ?? ''));

        return $value !== '' ? $value : $default;
    }

    private function buildLiveMid(string $suffix): string
    {
        if ($suffix === '') {
            return '';
        }

        return str_starts_with($suffix, self::LIVE_MID_PREFIX) ? $suffix : self::LIVE_MID_PREFIX . $suffix;
    }

    private function getPluginSettings(): array
    {
        return plugin_settings(self::PLUGIN_IDENTIFIER);
    }
}
