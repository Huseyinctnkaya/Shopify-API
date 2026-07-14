<?php

namespace Tests\Feature;

use App\Models\PendingShopifyConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopifyClaimTest extends TestCase
{
    use RefreshDatabase;

    public function test_claim_returns_401_without_valid_secret(): void
    {
        config(['services.internal_claim.secret' => 'correct-secret']);

        $response = $this->postJson('/api/internal/shopify/claim', ['claim_token' => 'whatever'], [
            'Authorization' => 'Bearer wrong-secret',
        ]);

        $response->assertStatus(401);
    }

    public function test_claim_returns_token_and_marks_pending_as_claimed(): void
    {
        config(['services.internal_claim.secret' => 'correct-secret']);

        $pending = PendingShopifyConnection::createForShop(
            'test-shop.myshopify.com', 'Test Shop', 'shpat_abc', 'read_orders',
        );

        $response = $this->postJson('/api/internal/shopify/claim', ['claim_token' => $pending->claim_token], [
            'Authorization' => 'Bearer correct-secret',
        ]);

        $response->assertOk()->assertJson([
            'shop'         => 'test-shop.myshopify.com',
            'shop_name'    => 'Test Shop',
            'access_token' => 'shpat_abc',
            'scope'        => 'read_orders',
        ]);

        $this->assertNotNull($pending->fresh()->claimed_at);
    }

    public function test_claim_returns_410_when_already_claimed(): void
    {
        config(['services.internal_claim.secret' => 'correct-secret']);

        $pending = PendingShopifyConnection::createForShop('test-shop.myshopify.com', 'Test Shop', 'shpat_abc', null);
        $pending->markClaimed();

        $response = $this->postJson('/api/internal/shopify/claim', ['claim_token' => $pending->claim_token], [
            'Authorization' => 'Bearer correct-secret',
        ]);

        $response->assertStatus(410);
    }

    public function test_claim_returns_410_when_claim_token_unknown(): void
    {
        config(['services.internal_claim.secret' => 'correct-secret']);

        $response = $this->postJson('/api/internal/shopify/claim', ['claim_token' => 'does-not-exist'], [
            'Authorization' => 'Bearer correct-secret',
        ]);

        $response->assertStatus(410);
    }

    public function test_claim_returns_401_when_secret_is_not_configured_even_without_auth_header(): void
    {
        config(['services.internal_claim.secret' => null]);

        $response = $this->postJson('/api/internal/shopify/claim', ['claim_token' => 'whatever']);

        $response->assertStatus(401);
    }
}
