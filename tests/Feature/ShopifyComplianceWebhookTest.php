<?php

namespace Tests\Feature;

use App\Models\PendingShopifyConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopifyComplianceWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function signedRequest(string $topic, array $payload, string $secret): array
    {
        $body = json_encode($payload);
        $hmac = base64_encode(hash_hmac('sha256', $body, $secret, true));

        return [$body, $hmac];
    }

    public function test_returns_401_when_hmac_missing(): void
    {
        $response = $this->postJson('/api/webhooks/compliance', ['shop_domain' => 'test-shop.myshopify.com']);

        $response->assertStatus(401);
    }

    public function test_returns_401_when_hmac_invalid(): void
    {
        config(['services.shopify.client_secret' => 'test_secret']);

        $response = $this->postJson(
            '/api/webhooks/compliance',
            ['shop_domain' => 'test-shop.myshopify.com'],
            ['X-Shopify-Hmac-Sha256' => 'wrong-hmac==', 'X-Shopify-Topic' => 'shop/redact']
        );

        $response->assertStatus(401);
    }

    public function test_customers_data_request_returns_ok_with_valid_hmac(): void
    {
        config(['services.shopify.client_secret' => 'test_secret']);

        $payload = ['shop_id' => 1, 'shop_domain' => 'test-shop.myshopify.com', 'customer' => ['id' => 1]];
        [$body, $hmac] = $this->signedRequest('customers/data_request', $payload, 'test_secret');

        $response = $this->call('POST', '/api/webhooks/compliance', [], [], [], [
            'HTTP_X-Shopify-Hmac-Sha256' => $hmac,
            'HTTP_X-Shopify-Topic'       => 'customers/data_request',
            'CONTENT_TYPE'               => 'application/json',
        ], $body);

        $response->assertOk()->assertJson(['message' => 'ok']);
    }

    public function test_customers_redact_returns_ok_with_valid_hmac(): void
    {
        config(['services.shopify.client_secret' => 'test_secret']);

        $payload = ['shop_id' => 1, 'shop_domain' => 'test-shop.myshopify.com', 'customer' => ['id' => 1]];
        [$body, $hmac] = $this->signedRequest('customers/redact', $payload, 'test_secret');

        $response = $this->call('POST', '/api/webhooks/compliance', [], [], [], [
            'HTTP_X-Shopify-Hmac-Sha256' => $hmac,
            'HTTP_X-Shopify-Topic'       => 'customers/redact',
            'CONTENT_TYPE'               => 'application/json',
        ], $body);

        $response->assertOk()->assertJson(['message' => 'ok']);
    }

    public function test_shop_redact_deletes_pending_connections_for_shop(): void
    {
        config(['services.shopify.client_secret' => 'test_secret']);

        PendingShopifyConnection::createForShop('test-shop.myshopify.com', 'Test Shop', 'shpat_abc', null);
        PendingShopifyConnection::createForShop('other-shop.myshopify.com', 'Other Shop', 'shpat_def', null);

        $payload = ['shop_id' => 1, 'shop_domain' => 'test-shop.myshopify.com'];
        [$body, $hmac] = $this->signedRequest('shop/redact', $payload, 'test_secret');

        $response = $this->call('POST', '/api/webhooks/compliance', [], [], [], [
            'HTTP_X-Shopify-Hmac-Sha256' => $hmac,
            'HTTP_X-Shopify-Topic'       => 'shop/redact',
            'CONTENT_TYPE'               => 'application/json',
        ], $body);

        $response->assertOk()->assertJson(['message' => 'ok']);

        $this->assertDatabaseMissing('pending_shopify_connections', ['shop' => 'test-shop.myshopify.com']);
        $this->assertDatabaseHas('pending_shopify_connections', ['shop' => 'other-shop.myshopify.com']);
    }
}
