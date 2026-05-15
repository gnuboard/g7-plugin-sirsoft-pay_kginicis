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
                'cbt_hash_data'       => '/plugins/sirsoft-pay_kginicis/payment/cbt/hash-data',
                'cbt_callback'        => '/plugins/sirsoft-pay_kginicis/payment/cbt/callback',
                'cbt_auth_url'        => $isTest ? self::CBT_AUTH_URL_TEST : self::CBT_AUTH_URL_LIVE,
                'mobile_signature'    => '/plugins/sirsoft-pay_kginicis/payment/mobile/signature',
                'mobile_callback'     => '/plugins/sirsoft-pay_kginicis/payment/mobile/callback',
                'mobile_vbank_notify' => '/plugins/sirsoft-pay_kginicis/payment/mobile/vbank-notify',
            ],
            'japan_enabled'              => $settings['japan_enabled'] ?? false,
            'use_escrow'                 => $settings['use_escrow'] ?? false,
            'japan_mid'                  => $isTest
                ? KgInicisApiService::JAPAN_TEST_MID
                : ($settings['live_japan_mid'] ?? ''),
            'enabled_easy_pays'          => $this->getEnabledEasyPays($settings),
            'easy_pay_allow_with_other_pg' => (bool) ($settings['easy_pay_allow_with_other_pg'] ?? false),
            'use_credit_point'           => (bool) ($settings['use_credit_point'] ?? false),
        ]);
    }

    private function getEnabledEasyPays(array $settings): array
    {
        $enabled = [];
        if ($settings['easy_pay_samsung_pay'] ?? false) $enabled[] = 'SAMSUNG';
        if ($settings['easy_pay_lpay'] ?? false)        $enabled[] = 'LPAY';
        if ($settings['easy_pay_kakaopay'] ?? false)    $enabled[] = 'KAKAOPAY';
        return $enabled;
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
