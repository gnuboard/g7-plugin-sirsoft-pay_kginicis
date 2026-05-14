<?php

namespace Plugins\Sirsoft\PayKginicis;

use App\Extension\AbstractPlugin;

class Plugin extends AbstractPlugin
{
    public function getMetadata(): array
    {
        return [
            'author' => 'Sirsoft',
            'license' => 'MIT',
            'homepage' => 'https://sir.kr',
            'keywords' => ['payment', 'kginicis', 'inicis', 'pg', 'card', 'ecommerce', 'japan'],
        ];
    }

    public function getSettingsSchema(): array
    {
        return [
            'is_test_mode' => [
                'type' => 'boolean',
                'default' => true,
                'label' => ['ko' => '테스트 모드', 'en' => 'Test Mode'],
                'hint' => [
                    'ko' => '테스트 모드에서는 실제 결제가 발생하지 않습니다.',
                    'en' => 'No real payments occur in test mode.',
                ],
            ],
            'test_mid' => [
                'type' => 'string',
                'default' => 'INIpayTest',
                'label' => ['ko' => '테스트 가맹점 ID (MID)', 'en' => 'Test Merchant ID (MID)'],
                'hint' => [
                    'ko' => 'KG 이니시스에서 발급받은 테스트 MID',
                    'en' => 'Test MID issued by KG Inicis',
                ],
            ],
            'test_sign_key' => [
                'type' => 'string',
                'default' => 'SU5JTElURV9UUklQTEVERVNfS0VZU1RS',
                'sensitive' => true,
                'label' => ['ko' => '테스트 사인키', 'en' => 'Test Sign Key'],
                'hint' => [
                    'ko' => '결제창 서명 생성에 사용되는 키입니다.',
                    'en' => 'Key used for payment window signature generation.',
                ],
            ],
            'test_iniapi_key' => [
                'type' => 'string',
                'default' => 'ItEQKi3rY7uvDS8l',
                'sensitive' => true,
                'label' => ['ko' => '테스트 INIAPI 키', 'en' => 'Test INIAPI Key'],
                'hint' => [
                    'ko' => '취소 API 인증에 사용되는 키입니다.',
                    'en' => 'Key used for cancel API authentication.',
                ],
            ],
            'test_iniapi_iv' => [
                'type' => 'string',
                'default' => 'HYb3yQ4f65QL89==',
                'sensitive' => true,
                'label' => ['ko' => '테스트 INIAPI IV', 'en' => 'Test INIAPI IV'],
                'hint' => [
                    'ko' => '취소 API 암호화에 사용되는 초기화 벡터입니다.',
                    'en' => 'Initialization vector for cancel API encryption.',
                ],
            ],
            'live_mid' => [
                'type' => 'string',
                'default' => '',
                'label' => ['ko' => '라이브 가맹점 ID (MID)', 'en' => 'Live Merchant ID (MID)'],
            ],
            'live_sign_key' => [
                'type' => 'string',
                'default' => '',
                'sensitive' => true,
                'label' => ['ko' => '라이브 사인키', 'en' => 'Live Sign Key'],
                'hint' => [
                    'ko' => '외부에 노출되지 않도록 주의하세요.',
                    'en' => 'Keep this key secret.',
                ],
            ],
            'live_iniapi_key' => [
                'type' => 'string',
                'default' => '',
                'sensitive' => true,
                'label' => ['ko' => '라이브 INIAPI 키', 'en' => 'Live INIAPI Key'],
                'hint' => [
                    'ko' => '외부에 노출되지 않도록 주의하세요.',
                    'en' => 'Keep this key secret.',
                ],
            ],
            'live_iniapi_iv' => [
                'type' => 'string',
                'default' => '',
                'sensitive' => true,
                'label' => ['ko' => '라이브 INIAPI IV', 'en' => 'Live INIAPI IV'],
            ],
            'japan_enabled' => [
                'type' => 'boolean',
                'default' => false,
                'label' => ['ko' => '일본 결제 활성화', 'en' => 'Enable Japan Payment'],
                'hint' => [
                    'ko' => '일본 엔(JPY) 결제를 위한 별도 MID가 필요합니다.',
                    'en' => 'Requires a separate MID for Japanese Yen (JPY) payments.',
                ],
            ],
            'test_japan_sign_key' => [
                'type' => 'string',
                'default' => '5AL5Djb1Ipualn0F',
                'sensitive' => true,
                'label' => ['ko' => '테스트 일본 CBT 해시키', 'en' => 'Test Japan CBT Hash Key'],
                'hint' => [
                    'ko' => 'CBT 해시 데이터 생성에 사용되는 테스트 KEY입니다.',
                    'en' => 'Test KEY used for CBT hash data generation.',
                ],
            ],
            'live_japan_mid' => [
                'type' => 'string',
                'default' => '',
                'label' => ['ko' => '라이브 일본 MID', 'en' => 'Live Japan MID'],
            ],
            'live_japan_sign_key' => [
                'type' => 'string',
                'default' => '',
                'sensitive' => true,
                'label' => ['ko' => '라이브 일본 CBT 해시키', 'en' => 'Live Japan CBT Hash Key'],
                'hint' => [
                    'ko' => 'CBT 해시 데이터 생성에 사용되는 라이브 KEY입니다. 외부에 노출되지 않도록 주의하세요.',
                    'en' => 'Live KEY used for CBT hash data generation. Keep this key secret.',
                ],
            ],
            'redirect_success_url' => [
                'type' => 'string',
                'default' => '/shop/orders/{orderId}/complete',
                'label' => ['ko' => '결제 성공 리다이렉트 URL', 'en' => 'Payment Success Redirect URL'],
                'hint' => [
                    'ko' => '상대 경로(/shop/...) 또는 전체 URL(https://...) 모두 가능합니다. {orderId}는 주문번호로 자동 치환됩니다.',
                    'en' => 'Supports relative paths or full URLs. {orderId} will be replaced with the actual order number.',
                ],
            ],
            'redirect_fail_url' => [
                'type' => 'string',
                'default' => '/shop/checkout',
                'label' => ['ko' => '결제 실패 리다이렉트 URL', 'en' => 'Payment Failure Redirect URL'],
                'hint' => [
                    'ko' => '상대 경로 또는 전체 URL 모두 가능합니다. 오류 정보는 쿼리 파라미터로 자동 추가됩니다.',
                    'en' => 'Supports relative paths or full URLs. Error details are appended as query parameters.',
                ],
            ],
        ];
    }

    public function getConfigValues(): array
    {
        return [
            'is_test_mode' => true,
            'test_mid' => 'INIpayTest',
            'test_sign_key' => 'SU5JTElURV9UUklQTEVERVNfS0VZU1RS',
            'test_iniapi_key' => 'ItEQKi3rY7uvDS8l',
            'test_iniapi_iv' => 'HYb3yQ4f65QL89==',
            'live_mid' => '',
            'live_sign_key' => '',
            'live_iniapi_key' => '',
            'live_iniapi_iv' => '',
            'japan_enabled' => false,
            'test_japan_sign_key' => '5AL5Djb1Ipualn0F',
            'live_japan_mid' => '',
            'live_japan_sign_key' => '',
            'redirect_success_url' => '/shop/orders/{orderId}/complete',
            'redirect_fail_url' => '/shop/checkout',
        ];
    }

    public function getHookListeners(): array
    {
        return [
            Listeners\RegisterPgProviderListener::class,
            Listeners\PaymentRefundListener::class,
        ];
    }

    public function getHooks(): array
    {
        return [
            [
                'name' => 'sirsoft-pay_kginicis.payment.before_authorize',
                'type' => 'action',
                'description' => [
                    'ko' => 'KG 이니시스 서버 승인 API 호출 전',
                    'en' => 'Before KG Inicis server authorization API call',
                ],
            ],
            [
                'name' => 'sirsoft-pay_kginicis.payment.after_authorize',
                'type' => 'action',
                'description' => [
                    'ko' => 'KG 이니시스 서버 승인 완료 후',
                    'en' => 'After KG Inicis server authorization completed',
                ],
            ],
            [
                'name' => 'sirsoft-pay_kginicis.payment.before_cancel',
                'type' => 'action',
                'description' => [
                    'ko' => 'KG 이니시스 결제 취소 API 호출 전 (본인인증 등 확장 지점)',
                    'en' => 'Before KG Inicis cancel API call (extension point for re-auth, etc.)',
                ],
            ],
            [
                'name' => 'sirsoft-pay_kginicis.payment.after_cancel',
                'type' => 'action',
                'description' => [
                    'ko' => 'KG 이니시스 결제 취소 완료 후',
                    'en' => 'After KG Inicis cancel completed',
                ],
            ],
        ];
    }
}
