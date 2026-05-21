<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Concerns;

use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;

trait ValidatesCbtOrderContext
{
    protected function cbtExpectedPrice(Order $order): int
    {
        return (int) round((float) $order->total_due_amount);
    }

    protected function cbtRequestMatchesOrderBuyer(Request $request, Order $order): bool
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

        $expectedPhone = $this->cbtDigitsOnly((string) $address->orderer_phone);
        if ($expectedPhone !== '') {
            $receivedPhone = $this->cbtDigitsOnly((string) $request->input('buyer_phone', ''));
            if ($receivedPhone === '' || $receivedPhone !== $expectedPhone) {
                return false;
            }
        }

        return true;
    }

    protected function cbtDigitsOnly(string $value): string
    {
        return preg_replace('/[^0-9]/', '', $value) ?? '';
    }
}
