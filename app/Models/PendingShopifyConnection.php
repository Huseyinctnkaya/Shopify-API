<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PendingShopifyConnection extends Model
{
    // claim_token/expires_at/claimed_at kasıtlı olarak fillable DIŞINDA:
    // bunlar güvenlik durumunu (bir token'ın claim edilebilir olup olmadığını)
    // kontrol ediyor, mass assignment ile dışarıdan set edilebilir olmamalı.
    protected $fillable = [
        'shop', 'shop_name', 'access_token', 'scope',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'expires_at'   => 'datetime',
        'claimed_at'   => 'datetime',
    ];

    public static function createForShop(string $shop, string $shopName, string $accessToken, ?string $scope): self
    {
        $connection = new self([
            'shop'         => $shop,
            'shop_name'    => $shopName,
            'access_token' => $accessToken,
            'scope'        => $scope,
        ]);

        $connection->claim_token = Str::random(40);
        $connection->expires_at  = now()->addMinutes(30);
        $connection->save();

        return $connection;
    }

    public function isClaimable(): bool
    {
        return $this->claimed_at === null && $this->expires_at->isFuture();
    }

    public function markClaimed(): void
    {
        $this->forceFill(['claimed_at' => now()])->save();
    }
}
