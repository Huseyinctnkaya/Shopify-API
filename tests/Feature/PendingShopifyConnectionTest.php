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

    public function test_create_for_shop_stores_refresh_token_and_token_expiry(): void
    {
        $connection = PendingShopifyConnection::createForShop(
            'test-shop.myshopify.com',
            'Test Shop',
            'shpat_secret123',
            'read_orders',
            'shprt_secret456',
            3600,
        );

        $this->assertEquals('shprt_secret456', $connection->refresh_token);
        $this->assertTrue($connection->token_expires_at->between(now()->addSeconds(3599), now()->addSeconds(3601)));
    }

    public function test_is_claimable_false_when_expired(): void
    {
        $connection = PendingShopifyConnection::createForShop('test-shop.myshopify.com', 'Test Shop', 'token', null);
        // expires_at kasıtlı olarak fillable değil (güvenlik durumu alanı) — test setup'ında forceFill kullanılır.
        $connection->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->assertFalse($connection->fresh()->isClaimable());
    }

    public function test_is_claimable_false_when_already_claimed(): void
    {
        $connection = PendingShopifyConnection::createForShop('test-shop.myshopify.com', 'Test Shop', 'token', null);
        $connection->markClaimed();

        $this->assertFalse($connection->fresh()->isClaimable());
    }

    public function test_security_fields_cannot_be_set_via_mass_assignment(): void
    {
        // Create a connection using the safe factory method first
        $connection = PendingShopifyConnection::createForShop(
            'test-shop.myshopify.com',
            'Test Shop',
            'token',
            null
        );

        $originalToken = $connection->claim_token;
        $originalExpiresAt = $connection->expires_at;

        // Now attempt to update with attacker-supplied values via mass assignment
        // Since claim_token, expires_at, and claimed_at are NOT in $fillable,
        // they should be silently ignored by Eloquent
        $connection->update([
            'shop_name' => 'Updated Shop',
            'claim_token' => 'attacker-supplied-token',
            'expires_at' => now()->addYears(10),
            'claimed_at' => now(),
        ]);

        // Refresh from database to ensure mass assignment was truly blocked
        $connection->refresh();

        // Verify security fields were NOT modified
        $this->assertEquals($originalToken, $connection->claim_token);
        $this->assertEquals($originalExpiresAt, $connection->expires_at);
        $this->assertNull($connection->claimed_at);
        // Verify fillable field WAS modified
        $this->assertEquals('Updated Shop', $connection->shop_name);
    }
}
