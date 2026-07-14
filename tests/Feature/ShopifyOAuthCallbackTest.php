<?php

namespace Tests\Feature;

use App\Models\PendingShopifyConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyOAuthCallbackTest extends TestCase
{
    use RefreshDatabase;

    private function signedParams(array $params, string $secret): array
    {
        $forHmac = $params;
        ksort($forHmac);
        $queryString = collect($forHmac)->map(fn($v, $k) => "{$k}={$v}")->implode('&');
        $params['hmac'] = hash_hmac('sha256', $queryString, $secret);
        return $params;
    }

    public function test_callback_creates_pending_connection_and_redirects_with_claim_token(): void
    {
        config([
            'services.shopify.client_secret' => 'test_secret',
            'services.dashboard.url'         => 'https://dashboard.34devs.com',
        ]);

        Http::fake([
            'test-shop.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'shpat_abc123',
                'scope'        => 'read_orders,read_products',
            ]),
            'test-shop.myshopify.com/admin/api/*/shop.json' => Http::response([
                'shop' => ['name' => 'Test Shop'],
            ]),
        ]);

        $state  = encrypt(['shop' => 'test-shop.myshopify.com']);
        $params = $this->signedParams([
            'shop'  => 'test-shop.myshopify.com',
            'code'  => 'abc',
            'state' => $state,
        ], 'test_secret');

        $response = $this->get('/oauth/callback?' . http_build_query($params));

        $this->assertDatabaseCount('pending_shopify_connections', 1);
        $pending = PendingShopifyConnection::first();
        $this->assertEquals('test-shop.myshopify.com', $pending->shop);
        $this->assertEquals('Test Shop', $pending->shop_name);
        $this->assertEquals('shpat_abc123', $pending->access_token);

        $response->assertRedirect(
            "https://dashboard.34devs.com/shopify-app?pending={$pending->claim_token}&shop=test-shop.myshopify.com"
        );
    }

    public function test_callback_redirects_with_error_when_hmac_invalid(): void
    {
        config([
            'services.shopify.client_secret' => 'test_secret',
            'services.dashboard.url'         => 'https://dashboard.34devs.com',
        ]);

        $state = encrypt(['shop' => 'test-shop.myshopify.com']);

        $response = $this->get('/oauth/callback?' . http_build_query([
            'shop' => 'test-shop.myshopify.com', 'code' => 'abc', 'state' => $state, 'hmac' => 'wrong',
        ]));

        $response->assertRedirect('https://dashboard.34devs.com/shopify-app?error=invalid_hmac');
        $this->assertDatabaseCount('pending_shopify_connections', 0);
    }

    public function test_callback_redirects_with_error_when_params_missing(): void
    {
        config(['services.dashboard.url' => 'https://dashboard.34devs.com']);

        $response = $this->get('/oauth/callback');

        $response->assertRedirect('https://dashboard.34devs.com/shopify-app?error=missing_params');
    }

    public function test_callback_redirects_with_error_when_state_is_invalid(): void
    {
        config([
            'services.shopify.client_secret' => 'test_secret',
            'services.dashboard.url'         => 'https://dashboard.34devs.com',
        ]);

        $params = $this->signedParams([
            'shop'  => 'test-shop.myshopify.com',
            'code'  => 'abc',
            'state' => 'not-a-valid-encrypted-payload',
        ], 'test_secret');

        $response = $this->get('/oauth/callback?' . http_build_query($params));

        $response->assertRedirect('https://dashboard.34devs.com/shopify-app?error=invalid_state');
        $this->assertDatabaseCount('pending_shopify_connections', 0);
    }

    public function test_callback_redirects_with_error_when_token_exchange_fails(): void
    {
        config([
            'services.shopify.client_secret' => 'test_secret',
            'services.dashboard.url'         => 'https://dashboard.34devs.com',
        ]);

        Http::fake([
            'test-shop.myshopify.com/admin/oauth/access_token' => Http::response(['errors' => 'invalid_request'], 400),
        ]);

        $state  = encrypt(['shop' => 'test-shop.myshopify.com']);
        $params = $this->signedParams([
            'shop'  => 'test-shop.myshopify.com',
            'code'  => 'abc',
            'state' => $state,
        ], 'test_secret');

        $response = $this->get('/oauth/callback?' . http_build_query($params));

        $this->assertTrue(
            str_starts_with($response->getTargetUrl(), 'https://dashboard.34devs.com/shopify-app?error='),
            "Expected redirect to start with error parameter, got: {$response->getTargetUrl()}"
        );
        $this->assertDatabaseCount('pending_shopify_connections', 0);
    }
}
