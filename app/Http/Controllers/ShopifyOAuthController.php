<?php

namespace App\Http\Controllers;

use App\Models\PendingShopifyConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ShopifyOAuthController extends Controller
{
    private string $appUrl;
    private string $dashboardApiUrl;

    public function __construct()
    {
        $this->appUrl          = config('app.url');
        $this->dashboardApiUrl = config('services.dashboard.url');
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

        $errorRedirect = fn (string $error) => redirect("{$this->dashboardApiUrl}/shopify-app?error={$error}");

        if (!$shop || !$code || !$state) {
            return $errorRedirect('missing_params');
        }

        if (!$this->verifyHmac($request)) {
            return $errorRedirect('invalid_hmac');
        }

        try {
            decrypt($state);
        } catch (\Throwable) {
            return $errorRedirect('invalid_state');
        }

        try {
            $tokens   = $this->exchangeCodeForToken($shop, $code);
            $shopName = $this->getShopName($shop, $tokens['access_token']);

            $pending = PendingShopifyConnection::createForShop(
                $shop,
                $shopName,
                $tokens['access_token'],
                $tokens['scope'] ?? null,
                $tokens['refresh_token'] ?? null,
                $tokens['expires_in'] ?? null,
            );
        } catch (\Throwable $e) {
            return $errorRedirect(urlencode($e->getMessage()));
        }

        return redirect(
            "{$this->dashboardApiUrl}/shopify-app?pending={$pending->claim_token}&shop=" . urlencode($shop)
        );
    }

    private function exchangeCodeForToken(string $shop, string $code): array
    {
        // expiring=1: 2026-04-01 sonrası oluşturulan public app'ler için Shopify artık
        // non-expiring offline token kabul etmiyor (Admin API 403 döner). Bu parametre
        // olmadan dönen token'lar API çağrılarında reddedilir.
        $response = Http::post("https://{$shop}/admin/oauth/access_token", [
            'client_id'     => config('services.shopify.client_id'),
            'client_secret' => config('services.shopify.client_secret'),
            'code'          => $code,
            'expiring'      => 1,
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

    // POST /api/internal/shopify/claim  (dashboard backend'den, server-to-server)
    public function claim(Request $request): \Illuminate\Http\JsonResponse
    {
        $secret = (string) config('services.internal_claim.secret');

        // Secret boşsa (env yapılandırılmamışsa) fail-closed: hash_equals('', '') PHP'de
        // true döner, yani secret set edilmemişken Authorization header'ı olmayan istekler
        // yanlışlıkla yetkili sayılırdı. Boş secret her zaman reddedilir.
        if ($secret === '' || !hash_equals($secret, (string) $request->bearerToken())) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $request->validate(['claim_token' => 'required|string']);

        $pending = PendingShopifyConnection::where('claim_token', $request->claim_token)->first();

        if (!$pending || !$pending->isClaimable()) {
            return response()->json(['error' => 'Bağlantı bulunamadı veya süresi dolmuş.'], 410);
        }

        $pending->markClaimed();

        return response()->json([
            'shop'             => $pending->shop,
            'shop_name'        => $pending->shop_name,
            'access_token'     => $pending->access_token,
            'refresh_token'    => $pending->refresh_token,
            'scope'            => $pending->scope,
            'token_expires_at' => $pending->token_expires_at?->toIso8601String(),
        ]);
    }

    // Shopify'ın gönderdiği HMAC imzasını doğrula (güvenlik)
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
