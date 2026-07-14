<?php

namespace Tests\Feature;

use App\Models\PendingShopifyConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PendingShopifyConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_for_shop_generates_claim_token_and_30_minute_expiry(): void
    {
        $connection = PendingShopifyConnection::createForShop(
            'test-shop.myshopify.com',
            'Test Shop',
            'shpat_secret123',
            'read_orders',
        );

        $this->assertEquals(40, strlen($connection->claim_token));
        $this->assertEquals('shpat_secret123', $connection->access_token);
        $this->assertEquals('test-shop.myshopify.com', $connection->shop);
        $this->assertTrue($connection->expires_at->between(now()->addMinutes(29), now()->addMinutes(31)));
        $this->assertTrue($connection->isClaimable());
    }

    public function test_is_claimable_false_when_expired(): void
    {
        $connection = PendingShopifyConnection::createForShop('test-shop.myshopify.com', 'Test Shop', 'token', null);
        $connection->update(['expires_at' => now()->subMinute()]);

        $this->assertFalse($connection->fresh()->isClaimable());
    }

    public function test_is_claimable_false_when_already_claimed(): void
    {
        $connection = PendingShopifyConnection::createForShop('test-shop.myshopify.com', 'Test Shop', 'token', null);
        $connection->update(['claimed_at' => now()]);

        $this->assertFalse($connection->fresh()->isClaimable());
    }
}
