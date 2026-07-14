<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PendingShopifyConnection extends Model
{
    protected $fillable = [
        'shop', 'shop_name', 'access_token', 'scope', 'claim_token', 'expires_at', 'claimed_at',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'expires_at'   => 'datetime',
        'claimed_at'   => 'datetime',
    ];

    public static function createForShop(string $shop, string $shopName, string $accessToken, ?string $scope): self
    {
        return self::create([
            'shop'        => $shop,
            'shop_name'   => $shopName,
            'access_token' => $accessToken,
            'scope'       => $scope,
            'claim_token' => Str::random(40),
            'expires_at'  => now()->addMinutes(30),
        ]);
    }

    public function isClaimable(): bool
    {
        return $this->claimed_at === null && $this->expires_at->isFuture();
    }
}
