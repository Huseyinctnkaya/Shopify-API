<?php

namespace App\Services;

use App\Models\ClientIntegration;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopifyService
{
    private const API_VERSION = '2025-01';

    public function __construct(private ClientIntegration $integration) {}

    // ─── Sipariş verileri ─────────────────────────────────────────────────────

    public function getOrders(array $params = []): array
    {
        $default = ['status' => 'any', 'limit' => 250];

        return $this->get('orders.json', array_merge($default, $params))
            ->json('orders', []);
    }

    public function getOrdersCount(array $params = []): int
    {
        return $this->get('orders/count.json', $params)->json('count', 0);
    }

    // ─── Gelir / Analitik ─────────────────────────────────────────────────────

    public function getRevenueSummary(string $from, string $to): array
    {
        $orders = $this->get('orders.json', [
            'status'          => 'any',
            'financial_status' => 'paid',
            'created_at_min'  => $from,
            'created_at_max'  => $to,
            'limit'           => 250,
            'fields'          => 'id,total_price,currency,created_at,financial_status',
        ])->json('orders', []);

        $total     = collect($orders)->sum(fn($o) => (float) $o['total_price']);
        $count     = count($orders);
        $avgOrder  = $count > 0 ? round($total / $count, 2) : 0;

        return [
            'total_revenue' => round($total, 2),
            'order_count'   => $count,
            'avg_order'     => $avgOrder,
            'currency'      => $orders[0]['currency'] ?? 'TRY',
            'period'        => ['from' => $from, 'to' => $to],
        ];
    }

    // ─── Ürünler ──────────────────────────────────────────────────────────────

    public function getProducts(array $params = []): array
    {
        return $this->get('products.json', array_merge(['limit' => 250], $params))
            ->json('products', []);
    }

    public function getProductsCount(): int
    {
        return $this->get('products/count.json')->json('count', 0);
    }

    // ─── Müşteriler ───────────────────────────────────────────────────────────

    public function getCustomersCount(): int
    {
        return $this->get('customers/count.json')->json('count', 0);
    }

    // ─── Dashboard özet ───────────────────────────────────────────────────────

    public function getDashboardSummary(string $from, string $to): array
    {
        return [
            'revenue'         => $this->getRevenueSummary($from, $to),
            'products_count'  => $this->getProductsCount(),
            'customers_count' => $this->getCustomersCount(),
            'orders_count'    => $this->getOrdersCount(['created_at_min' => $from, 'created_at_max' => $to]),
        ];
    }

    // ─── Token yenileme ───────────────────────────────────────────────────────

    public function refreshTokenIfNeeded(): void
    {
        if (!$this->integration->isTokenExpired()) {
            return;
        }

        $refreshToken = $this->integration->refresh_token
            ? decrypt($this->integration->refresh_token)
            : null;

        if (!$refreshToken) {
            Log::warning("Shopify token süresi doldu ama refresh_token yok.", [
                'integration_id' => $this->integration->id,
            ]);
            return;
        }

        $shop = $this->integration->account_id;

        $response = Http::post("https://{$shop}/admin/oauth/access_token", [
            'client_id'     => config('services.shopify.client_id'),
            'client_secret' => config('services.shopify.client_secret'),
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        if (!$response->successful()) {
            Log::error("Shopify token yenilenemedi.", [
                'integration_id' => $this->integration->id,
                'response'       => $response->body(),
            ]);
            return;
        }

        $tokens = $response->json();

        $this->integration->update([
            'access_token'     => encrypt($tokens['access_token']),
            'refresh_token'    => isset($tokens['refresh_token']) ? encrypt($tokens['refresh_token']) : $this->integration->refresh_token,
            'token_expires_at' => isset($tokens['expires_in'])
                ? now()->addSeconds($tokens['expires_in'])
                : null,
        ]);

        // in-memory token'ı da güncelle
        $this->integration->refresh();
    }

    // ─── HTTP helper ──────────────────────────────────────────────────────────

    private function get(string $endpoint, array $params = []): Response
    {
        $this->refreshTokenIfNeeded();

        $shop  = $this->integration->account_id;
        $token = decrypt($this->integration->access_token);

        return Http::withHeaders(['X-Shopify-Access-Token' => $token])
            ->get("https://{$shop}/admin/api/" . self::API_VERSION . "/{$endpoint}", $params);
    }
}
