<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Concerns;

use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;

trait ValidatesCbtOrderContext
{
    protected function expectedPaymentPrice(Order $order): int
    {
        // PG 청구 금액 = base total_due_amount 를 주문 스냅샷 환율로 결제 통화 환산한
        // 최소 화폐단위 정수. 모듈의 환산 SSoT(resolveSnapshotPaymentCharge)를 재사용해
        // buildPgPaymentData(클라이언트가 보내는 price)와 검증 기준을 동일하게 맞춘다.
        return app(CurrencyConversionService::class)
            ->resolveSnapshotPaymentCharge((float) $order->total_due_amount, $order->currency_snapshot ?? [])['minor_unit_amount'];
    }

    protected function cbtExpectedPrice(Order $order): int
    {
        return $this->expectedPaymentPrice($order);
    }

    protected function requestMatchesOrderBuyer(Request $request, Order $order): bool
    {
        /** @var OrderAddress|null $address */
        $address = $order->shippingAddress;
        if (! $address) {
            return true;
        }

        $expectedEmail = strtolower(trim((string) $address->orderer_email));
        if ($expectedEmail !== '') {
            $receivedEmail = strtolower(trim((string) $request->input('buyer_email', '')));
            if ($receivedEmail === '' || $receivedEmail !== $expectedEmail) {
                return false;
            }
        }

        $expectedPhone = $this->digitsOnly((string) $address->orderer_phone);
        if ($expectedPhone !== '') {
            $receivedPhone = $this->digitsOnly((string) $request->input('buyer_phone', ''));
            if ($receivedPhone === '' || $receivedPhone !== $expectedPhone) {
                return false;
            }
        }

        return true;
    }

    protected function cbtRequestMatchesOrderBuyer(Request $request, Order $order): bool
    {
        return $this->requestMatchesOrderBuyer($request, $order);
    }

    protected function digitsOnly(string $value): string
    {
        return preg_replace('/[^0-9]/', '', $value) ?? '';
    }

    protected function cbtDigitsOnly(string $value): string
    {
        return $this->digitsOnly($value);
    }
}
