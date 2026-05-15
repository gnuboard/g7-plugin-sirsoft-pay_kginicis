<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use Modules\Sirsoft\Ecommerce\Models\Product;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

class AdminCbtTestProductControllerTest extends PluginTestCase
{
    /**
     * 회귀 — Product 모델의 name 필드는 AsUnicodeJson 캐스트.
     * 컨트롤러가 plain string 을 전달하면 저장은 되지만 retrieve 시
     * array_key_first(null) 예외로 /shop/products 가 500.
     * 본 테스트는 컨트롤러가 항상 다국어 배열로 전달함을 보장.
     */
    public function test_cbt_test_product_name_is_multilingual_array(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/admin/cbt-test-product');

        $response->assertSuccessful();
        $productId = $response->json('data.product_id');
        $this->assertNotNull($productId);

        $product = Product::find($productId);
        $this->assertNotNull($product);

        $rawName = $product->getRawOriginal('name');
        $decoded = json_decode((string) $rawName, true);
        $this->assertIsArray($decoded, 'name 은 다국어 JSON 객체여야 한다 (plain string 회귀 차단)');
        $this->assertArrayHasKey('ko', $decoded);
        $this->assertArrayHasKey('en', $decoded);
        $this->assertArrayHasKey('ja', $decoded);

        $rawDescription = $product->getRawOriginal('description');
        $decodedDesc = json_decode((string) $rawDescription, true);
        $this->assertIsArray($decodedDesc, 'description 도 다국어 JSON 객체여야 한다');
    }

    public function test_cbt_test_product_endpoint_requires_admin(): void
    {
        // 인증 없이 호출 — 401 또는 redirect
        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/admin/cbt-test-product');

        $this->assertContains(
            $response->getStatusCode(),
            [401, 403, 302],
            '비인증 호출은 401/403/302 중 하나여야 한다',
        );
    }
}
