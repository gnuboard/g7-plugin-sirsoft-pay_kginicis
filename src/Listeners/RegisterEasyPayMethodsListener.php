<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Listeners;

use App\Contracts\Extension\HookListenerInterface;

/**
 * KG 이니시스 간편결제 (삼성페이 / 네이버페이 / L.pay / 카카오페이) 를 이커머스 결제수단 목록에 등록한다.
 *
 * 코어 sirsoft-ecommerce.settings.filter_available_payment_methods 필터 훅을 구독해
 * builtin 결제수단 배열의 'phone' (휴대폰결제) 항목 뒤, 'point' (포인트결제) 항목 앞에
 * 4개 결제수단을 삽입한다. 각 entry 의 defaults.pg_provider 는 null — 코어/이커머스의
 * "PG 선택 불필요" 상태로 표시되며, 기본 PG 사 설정과 무관하게 KG 이니시스 결제창이
 * 열린다 (orderResponseInterceptor 가 'kginicis_*' prefix 를 인식하여 KG 흐름으로 강제).
 *
 * 결제수단 ID 는 requestPayment handler 의 EasyPayMethod / DirectShowOpt 매핑과 일치:
 *   - kginicis_samsung_pay  → 'onlyssp' / 'd_samsungpay=Y'
 *   - kginicis_naverpay     → 'onlynaverpay' / 'd_npay=Y'
 *   - kginicis_lpay         → 'onlylpay'
 *   - kginicis_kakaopay     → 'onlykakaopay'
 */
class RegisterEasyPayMethodsListener implements HookListenerInterface
{
    /**
     * 구독할 훅 매핑 반환.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.settings.filter_available_payment_methods' => [
                'method' => 'injectEasyPayMethods',
                'type' => 'filter',
                'priority' => 20,
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용).
     *
     * @param  mixed  ...$args
     */
    public function handle(...$args): void {}

    /**
     * 이커머스 결제수단 목록에 KG 이니시스 간편결제 4종 inject.
     *
     * @param  array  $methods  builtin 결제수단 배열 (코어 EcommerceSettingsService::getBuiltinPaymentMethods)
     * @return array  4개 entry 가 phone~point 사이에 삽입된 배열
     */
    public function injectEasyPayMethods(array $methods): array
    {
        $easyPayMethods = [
            $this->buildEntry(
                id: 'kginicis_samsung_pay',
                nameKey: 'sirsoft-pay_kginicis::payment_methods.samsung_pay.name',
                descriptionKey: 'sirsoft-pay_kginicis::payment_methods.samsung_pay.description',
                icon: 'mobile-screen-button',
            ),
            $this->buildEntry(
                id: 'kginicis_naverpay',
                nameKey: 'sirsoft-pay_kginicis::payment_methods.naverpay.name',
                descriptionKey: 'sirsoft-pay_kginicis::payment_methods.naverpay.description',
                icon: 'wallet',
            ),
            $this->buildEntry(
                id: 'kginicis_lpay',
                nameKey: 'sirsoft-pay_kginicis::payment_methods.lpay.name',
                descriptionKey: 'sirsoft-pay_kginicis::payment_methods.lpay.description',
                icon: 'mobile-screen-button',
            ),
            $this->buildEntry(
                id: 'kginicis_kakaopay',
                nameKey: 'sirsoft-pay_kginicis::payment_methods.kakaopay.name',
                descriptionKey: 'sirsoft-pay_kginicis::payment_methods.kakaopay.description',
                icon: 'mobile-screen-button',
            ),
        ];

        // 'phone' 뒤, 'point' 앞에 삽입. 둘 중 하나라도 없으면 끝에 append.
        $insertAfter = null;
        foreach ($methods as $index => $method) {
            if (($method['id'] ?? null) === 'phone') {
                $insertAfter = $index;
                break;
            }
        }

        if ($insertAfter === null) {
            return array_merge($methods, $easyPayMethods);
        }

        return array_merge(
            array_slice($methods, 0, $insertAfter + 1),
            $easyPayMethods,
            array_slice($methods, $insertAfter + 1),
        );
    }

    /**
     * 결제수단 entry 1건 빌더 — EcommerceSettingsService 의 getBuiltinPaymentMethods 와 동일 형식.
     */
    private function buildEntry(string $id, string $nameKey, string $descriptionKey, string $icon): array
    {
        return [
            'id' => $id,
            'name' => [
                'ko' => __($nameKey, [], 'ko'),
                'en' => __($nameKey, [], 'en'),
            ],
            'description' => [
                'ko' => __($descriptionKey, [], 'ko'),
                'en' => __($descriptionKey, [], 'en'),
            ],
            'icon' => $icon,
            'source' => 'plugin:sirsoft-pay_kginicis',
            'defaults' => [
                // PG 선택 불필요 — orderResponseInterceptor 가 prefix 'kginicis_' 를 인식해
                // 기본 PG 사 설정과 무관하게 KG 이니시스 결제 흐름으로 강제.
                'pg_provider' => null,
                'is_active' => false,
                'min_order_amount' => 0,
                'stock_deduction_timing' => 'payment_complete',
            ],
        ];
    }
}
