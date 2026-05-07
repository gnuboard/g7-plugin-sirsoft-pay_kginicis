<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Listeners;

use App\Contracts\Extension\HookListenerInterface;

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
            'name_key' => 'sirsoft-pay_kginicis::provider.name',
            'name' => localized_label(nameKey: 'sirsoft-pay_kginicis::provider.name'),
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
                'signature'        => '/plugins/sirsoft-pay_kginicis/payment/signature',
                'callback'         => '/plugins/sirsoft-pay_kginicis/payment/callback',
                'cbt_hash_data'    => '/plugins/sirsoft-pay_kginicis/payment/cbt/hash-data',
                'cbt_callback'     => '/plugins/sirsoft-pay_kginicis/payment/cbt/callback',
                'cbt_auth_url'     => $isTest ? self::CBT_AUTH_URL_TEST : self::CBT_AUTH_URL_LIVE,
                'mobile_signature' => '/plugins/sirsoft-pay_kginicis/payment/mobile/signature',
                'mobile_callback'  => '/plugins/sirsoft-pay_kginicis/payment/mobile/callback',
            ],
            'japan_enabled' => $settings['japan_enabled'] ?? false,
            'use_escrow' => $settings['use_escrow'] ?? false,
            'japan_mid' => $isTest
                ? ($settings['test_japan_mid'] ?? '')
                : ($settings['live_japan_mid'] ?? ''),
        ]);
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
