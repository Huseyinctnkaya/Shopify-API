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
        'shop', 'shop_name', 'access_token', 'refresh_token', 'scope', 'token_expires_at',
    ];

    protected $casts = [
        'access_token'     => 'encrypted',
        'refresh_token'    => 'encrypted',
        'expires_at'       => 'datetime',
        'claimed_at'       => 'datetime',
        'token_expires_at' => 'datetime',
    ];

    public static function createForShop(
        string $shop,
        string $shopName,
        string $accessToken,
        ?string $scope,
        ?string $refreshToken = null,
        ?int $expiresInSeconds = null,
    ): self {
        $connection = new self([
            'shop'             => $shop,
            'shop_name'        => $shopName,
            'access_token'     => $accessToken,
            'refresh_token'    => $refreshToken,
            'scope'            => $scope,
            'token_expires_at' => $expiresInSeconds ? now()->addSeconds($expiresInSeconds) : null,
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
