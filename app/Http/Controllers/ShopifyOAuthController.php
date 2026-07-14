<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ShopifyOAuthController extends Controller
{
    private string $appUrl;
    private string $dashboardApiUrl;
    private string $dashboardApiKey;

    public function __construct()
    {
        $this->appUrl          = config('app.url');
        $this->dashboardApiUrl = config('services.dashboard.url');
        $this->dashboardApiKey = config('services.dashboard.api_key');
    }

    // GET /oauth/install?shop=magaza.myshopify.com
    public function install(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'shop' => 'required|string|regex:/^[a-zA-Z0-9\-]+\.myshopify\.com$/',
        ]);

        $state = encrypt(['shop' => $request->shop]);

        $params = http_build_query([
            'client_id'    => config('services.shopify.client_id'),
            'scope'        => config('services.shopify.scopes'),
            'redirect_uri' => $this->appUrl . '/oauth/callback',
            'state'        => $state,
        ]);

        return redirect("https://{$request->shop}/admin/oauth/authorize?{$params}");
    }

    // GET /oauth/callback  (public — Shopify buraya yönlendirir)
    public function callback(Request $request): \Illuminate\Http\RedirectResponse
    {
        $shop  = $request->query('shop');
        $code  = $request->query('code');
        $state = $request->query('state');

        if (!$shop || !$code || !$state) {
            return redirect($this->dashboardApiUrl . '?error=missing_params');
        }

        if (!$this->verifyHmac($request)) {
            return redirect($this->dashboardApiUrl . '?error=invalid_hmac');
        }

        try {
            $payload = decrypt($state);
        } catch (\Throwable) {
            return redirect($this->dashboardApiUrl . '?error=invalid_state');
        }

        try {
            $tokens   = $this->exchangeCodeForToken($shop, $code);
            $shopName = $this->getShopName($shop, $tokens['access_token']);
            $this->forwardToDashboard($payload['client_id'], $shop, $shopName, $tokens);
        } catch (\Throwable $e) {
            return redirect($this->dashboardApiUrl . '?error=' . urlencode($e->getMessage()));
        }

        $clientId = $payload['client_id'];
        return redirect("{$this->dashboardApiUrl}/clients/{$clientId}?shopify_connected=1");
    }

    private function exchangeCodeForToken(string $shop, string $code): array
    {
        $response = Http::post("https://{$shop}/admin/oauth/access_token", [
            'client_id'     => config('services.shopify.client_id'),
            'client_secret' => config('services.shopify.client_secret'),
            'code'          => $code,
        ]);

        if (!$response->successful()) {
            throw new \Exception('Token alınamadı: ' . $response->body());
        }

        return $response->json();
    }

    private function getShopName(string $shop, string $accessToken): string
    {
        $response = Http::withHeaders(['X-Shopify-Access-Token' => $accessToken])
            ->get("https://{$shop}/admin/api/2025-01/shop.json");

        return $response->json('shop.name') ?? $shop;
    }

    // Token'ı dashboard backend'e gönder
    private function forwardToDashboard(int $clientId, string $shop, string $shopName, array $tokens): void
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->dashboardApiKey,
            'Accept'        => 'application/json',
        ])->post("{$this->dashboardApiUrl}/api/shopify/save-token", [
            'client_id'    => $clientId,
            'shop'         => $shop,
            'shop_name'    => $shopName,
            'access_token' => $tokens['access_token'],
            'scope'        => $tokens['scope'] ?? null,
        ]);

        if (!$response->successful()) {
            throw new \Exception('Dashboard\'a token gönderilemedi: ' . $response->body());
        }
    }

    private function verifyHmac(Request $request): bool
    {
        $params = $request->except('hmac');
        ksort($params);

        $queryString = collect($params)
            ->map(fn($v, $k) => "{$k}=" . (is_array($v) ? implode(',', $v) : $v))
            ->implode('&');

        $computed = hash_hmac('sha256', $queryString, config('services.shopify.client_secret'));

        return hash_equals($computed, $request->query('hmac', ''));
    }
}
