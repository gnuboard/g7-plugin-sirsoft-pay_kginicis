<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Services;

use Illuminate\Support\Facades\DB;

class CbtReconciliationService
{
    public const META_KEY = 'kginicis_cbt_reconciliation';

    public const STATUS_AUTO_REFUNDED = 'auto_refunded';

    public const STATUS_MANUAL_REFUND_REQUIRED = 'manual_refund_required';

    public function get(string $orderNumber): ?array
    {
        $order = DB::table('ecommerce_orders')
            ->where('order_number', $orderNumber)
            ->select(['order_meta'])
            ->first();

        if (! $order) {
            return null;
        }

        $meta = $this->decodeJsonObject($order->order_meta);
        $record = $meta[self::META_KEY] ?? null;

        return is_array($record) ? $this->normalize($record) : null;
    }

    public function record(string $orderNumber, array $attributes): ?array
    {
        $order = DB::table('ecommerce_orders')
            ->where('order_number', $orderNumber)
            ->select(['id', 'order_meta'])
            ->first();

        if (! $order) {
            return null;
        }

        $meta = $this->decodeJsonObject($order->order_meta);
        $existing = $meta[self::META_KEY] ?? [];
        if (! is_array($existing)) {
            $existing = [];
        }

        $now = now()->toIso8601String();
        $record = array_merge($existing, $attributes, [
            'created_at' => $existing['created_at'] ?? $now,
            'updated_at' => $now,
        ]);
        $meta[self::META_KEY] = $record;

        DB::table('ecommerce_orders')
            ->where('id', $order->id)
            ->update([
                'order_meta' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);

        return $this->normalize($record);
    }

    private function normalize(array $record): array
    {
        $record['status'] = (string) ($record['status'] ?? '');
        $record['tid'] = (string) ($record['tid'] ?? '');
        $record['amount'] = (int) ($record['amount'] ?? 0);
        $record['retry_count'] = (int) ($record['retry_count'] ?? 0);
        $record['manual_action_required'] = $record['status'] === self::STATUS_MANUAL_REFUND_REQUIRED;
        $record['can_retry'] = $record['manual_action_required'] && $record['tid'] !== '';

        $refundResult = is_array($record['refund_result'] ?? null) ? $record['refund_result'] : [];
        $record['refund_result_code'] = (string) ($refundResult['resultCode'] ?? $refundResult['code'] ?? '');
        $record['refund_result_msg'] = (string) ($refundResult['resultMsg'] ?? $refundResult['message'] ?? '');

        return $record;
    }

    private function decodeJsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
