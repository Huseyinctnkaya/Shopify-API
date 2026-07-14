# Shopify → 34devs Dashboard Marka Bağlama Akışı — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Shopify OAuth tamamlandıktan sonra, hangi 34devs markasına (Client) ait olduğu bilinmeyen access token'ı, markanın kendi client-portal hesabıyla giriş yapıp onaylamasıyla güvenli şekilde dashboard'daki `ClientIntegration` kaydına bağlamak.

**Architecture:** `Shopify-API` (connect.34devs.com) OAuth'u tamamlayıp token'ı kendi DB'sinde geçici bir `pending_shopify_connections` kaydına yazar ve tarayıcıyı sadece rastgele bir `claim_token` ile dashboard'a yönlendirir. Dashboard frontend'i client-portal login + onay ekranı gösterir; onaylanınca dashboard backend'i sunucudan sunucuya (paylaşılan bearer secret) token'ı "claim" eder ve mevcut `ClientIntegration::updateOrCreate` mantığıyla kaydeder. Token hiçbir zaman tarayıcıya düşmez.

**Tech Stack:** Laravel 12 (her iki backend), PHPUnit (Pest değil — düz PHPUnit test sınıfları), Next.js 16 App Router + Tailwind (dashboard frontend), axios.

**Spec:** `docs/superpowers/specs/2026-07-14-shopify-brand-link-design.md`

## Global Constraints

- Her iki Laravel projesi de düz PHPUnit kullanıyor (Pest yok). Test sınıfları `Tests\Feature` namespace'inde, `Tests\TestCase`'den extend eder.
- Her iki `phpunit.xml` de `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` ile test DB'sini otomatik ayarlıyor — yeni test sınıflarında `Illuminate\Foundation\Testing\RefreshDatabase` trait'i kullanılmalı.
- Access token asla tarayıcıya/URL'e düşürülmeyecek (spec'in ana güvenlik gereksinimi).
- `Shopify-API` reposu: `/Users/huseyin/Documents/GitHub/Shopify-API`
- `34devs-dashboard` reposu: `/Users/huseyin/Documents/GitHub/34devs-dashboard` (backend: `backend/`, frontend: `frontend/`)
- Paylaşılan yeni sır: `Shopify-API`'de `INTERNAL_CLAIM_SECRET`, dashboard'da `SHOPIFY_APP_INTERNAL_SECRET` — aynı değer, iki app arası `/api/internal/shopify/claim` çağrısını doğrulamak için.
- 1 client-portal hesabı = 1 marka (Client kaydı) — seçim ekranı YOK, otomatik eşleşme.

---

## Task 1: `PendingShopifyConnection` migration + model (Shopify-API)

**Repo:** `/Users/huseyin/Documents/GitHub/Shopify-API`

**Files:**
- Create: `database/migrations/2026_07_14_000001_create_pending_shopify_connections_table.php`
- Create: `app/Models/PendingShopifyConnection.php`
- Test: `tests/Feature/PendingShopifyConnectionTest.php`

**Interfaces:**
- Produces: `PendingShopifyConnection::createForShop(string $shop, string $shopName, string $accessToken, ?string $scope): self` — sonraki tasklarda callback() ve claim() bunu kullanacak.
- Produces: `PendingShopifyConnection->isClaimable(): bool`
- Produces: model attribute'ları: `shop`, `shop_name`, `access_token` (encrypted cast, okurken otomatik decrypt), `scope`, `claim_token`, `expires_at` (datetime), `claimed_at` (datetime, nullable)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PendingShopifyConnectionTest.php`:

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PendingShopifyConnectionTest`
Expected: FAIL — `Class "App\Models\PendingShopifyConnection" not found`

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_07_14_000001_create_pending_shopify_connections_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_shopify_connections', function (Blueprint $table) {
            $table->id();
            $table->string('shop');
            $table->string('shop_name');
            $table->text('access_token');
            $table->string('scope')->nullable();
            $table->string('claim_token', 40)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_shopify_connections');
    }
};
```

- [ ] **Step 4: Create the model**

Create `app/Models/PendingShopifyConnection.php`:

```php
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
```

- [ ] **Step 5: Run migration and test**

Run: `php artisan migrate --env=testing` is not needed — PHPUnit runs migrations automatically via `RefreshDatabase`. Just run:
`php artisan test --filter=PendingShopifyConnectionTest`
Expected: PASS (3 tests)

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_14_000001_create_pending_shopify_connections_table.php app/Models/PendingShopifyConnection.php tests/Feature/PendingShopifyConnectionTest.php
git commit -m "feat: pending Shopify OAuth token'ları için PendingShopifyConnection modeli"
```

---

## Task 2: `install()` artık `client_id` istemiyor (Shopify-API)

**Repo:** `/Users/huseyin/Documents/GitHub/Shopify-API`

**Files:**
- Modify: `app/Http/Controllers/ShopifyOAuthController.php` (mevcut `install()` metodu)
- Test: `tests/Feature/ShopifyOAuthInstallTest.php`

**Interfaces:**
- Consumes: yok (bağımsız)
- Produces: `GET /oauth/install?shop=X` artık `client_id` olmadan Shopify authorize URL'ine redirect ediyor.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ShopifyOAuthInstallTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

class ShopifyOAuthInstallTest extends TestCase
{
    public function test_install_redirects_to_shopify_authorize_without_client_id(): void
    {
        config([
            'services.shopify.client_id' => 'test_client_id',
            'services.shopify.scopes'    => 'read_orders',
        ]);

        $response = $this->get('/oauth/install?shop=test-shop.myshopify.com');

        $response->assertRedirect();
        $this->assertStringStartsWith(
            'https://test-shop.myshopify.com/admin/oauth/authorize?',
            $response->headers->get('Location'),
        );
    }

    public function test_install_requires_shop_param(): void
    {
        $response = $this->get('/oauth/install');

        $response->assertInvalid(['shop']);
    }
}
```

- [ ] **Step 2: Run test to verify current behavior**

Run: `php artisan test --filter=ShopifyOAuthInstallTest`
Expected: FAIL on `test_install_redirects_to_shopify_authorize_without_client_id` — çünkü mevcut kod `client_id` validasyonu istiyor ve `/oauth/install?shop=...` (client_id olmadan) 422/redirect-with-errors dönüyor, Shopify'a redirect etmiyor.

- [ ] **Step 3: Update `install()`**

`app/Http/Controllers/ShopifyOAuthController.php` içinde mevcut `install()` metodunu şununla değiştir:

```php
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ShopifyOAuthInstallTest`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/ShopifyOAuthController.php tests/Feature/ShopifyOAuthInstallTest.php
git commit -m "feat: oauth install artık client_id gerektirmiyor"
```

---

## Task 3: `callback()` pending kayıt oluşturup dashboard'a yönlendiriyor (Shopify-API)

**Repo:** `/Users/huseyin/Documents/GitHub/Shopify-API`

**Files:**
- Modify: `app/Http/Controllers/ShopifyOAuthController.php` (`callback()`, `forwardToDashboard()` silinir, constructor'daki `dashboardApiKey` kaldırılır)
- Test: `tests/Feature/ShopifyOAuthCallbackTest.php`

**Interfaces:**
- Consumes: `PendingShopifyConnection::createForShop()` (Task 1)
- Produces: `GET /oauth/callback` başarılı olursa `{dashboard.url}/shopify-app?pending={claim_token}&shop={shop}` adresine redirect eder; hata durumunda `{dashboard.url}/shopify-app?error={kod}` adresine redirect eder.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ShopifyOAuthCallbackTest.php`:

```php
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
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ShopifyOAuthCallbackTest`
Expected: FAIL — redirect hedefi hâlâ eski `dashboardApiUrl . '/clients/{id}?shopify_connected=1'` formatında, `pending_shopify_connections` tablosuna hiçbir şey yazılmıyor.

- [ ] **Step 3: Update the controller**

`app/Http/Controllers/ShopifyOAuthController.php` dosyasının tamamını şu hale getir:

```php
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ShopifyOAuthCallbackTest`
Expected: PASS (3 tests)

- [ ] **Step 5: Run full test suite to confirm nothing else broke**

Run: `php artisan test`
Expected: tüm testler PASS

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/ShopifyOAuthController.php tests/Feature/ShopifyOAuthCallbackTest.php
git commit -m "feat: oauth callback token'ı pending kayda yazıp dashboard'a claim_token ile yönlendiriyor"
```

---

## Task 4: Internal claim endpoint (Shopify-API)

**Repo:** `/Users/huseyin/Documents/GitHub/Shopify-API`

**Files:**
- Modify: `bootstrap/app.php` (api routing kaydı eklenir)
- Create: `routes/api.php`
- Modify: `config/services.php` (`internal_claim.secret` eklenir)
- Modify: `.env.example` (`INTERNAL_CLAIM_SECRET` eklenir)
- Modify: `app/Http/Controllers/ShopifyOAuthController.php` (`claim()` metodu eklenir)
- Test: `tests/Feature/ShopifyClaimTest.php`

**Interfaces:**
- Consumes: `PendingShopifyConnection` (Task 1)
- Produces: `POST /api/internal/shopify/claim` — body `{claim_token}`, header `Authorization: Bearer <INTERNAL_CLAIM_SECRET>`. Başarılıysa `200 {shop, shop_name, access_token, scope}` döner ve kaydı tek seferlik claim eder (401 secret yanlışsa, 410 zaten claim edilmiş/süresi dolmuşsa).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ShopifyClaimTest.php`:

```php
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
        $pending->update(['claimed_at' => now()]);

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
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ShopifyClaimTest`
Expected: FAIL — `/api/internal/shopify/claim` route yok (404).

- [ ] **Step 3: Register API routing**

`bootstrap/app.php` içinde `withRouting(...)` çağrısına `api` satırını ekle:

```php
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
```

- [ ] **Step 4: Create `routes/api.php`**

```php
<?php

use App\Http\Controllers\ShopifyOAuthController;
use Illuminate\Support\Facades\Route;

// POST /api/internal/shopify/claim — dashboard backend'den, server-to-server, bearer secret ile korunur
Route::post('/internal/shopify/claim', [ShopifyOAuthController::class, 'claim']);
```

- [ ] **Step 5: Add config**

`config/services.php` içinde `'shopify'` bloğundan sonra ekle:

```php
    'internal_claim' => [
        'secret' => env('INTERNAL_CLAIM_SECRET'),
    ],
```

`.env.example` içinde `SHOPIFY_SCOPES` satırından sonra ekle:

```
INTERNAL_CLAIM_SECRET=
```

- [ ] **Step 6: Add `claim()` method to the controller**

`app/Http/Controllers/ShopifyOAuthController.php` sınıfının içine, `verifyHmac()` metodundan önce ekle:

```php
    // POST /api/internal/shopify/claim  (dashboard backend'den, server-to-server)
    public function claim(Request $request): \Illuminate\Http\JsonResponse
    {
        if (!hash_equals((string) config('services.internal_claim.secret'), (string) $request->bearerToken())) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $request->validate(['claim_token' => 'required|string']);

        $pending = PendingShopifyConnection::where('claim_token', $request->claim_token)->first();

        if (!$pending || !$pending->isClaimable()) {
            return response()->json(['error' => 'Bağlantı bulunamadı veya süresi dolmuş.'], 410);
        }

        $pending->update(['claimed_at' => now()]);

        return response()->json([
            'shop'         => $pending->shop,
            'shop_name'    => $pending->shop_name,
            'access_token' => $pending->access_token,
            'scope'        => $pending->scope,
        ]);
    }
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=ShopifyClaimTest`
Expected: PASS (4 tests)

- [ ] **Step 8: Run full suite**

Run: `php artisan test`
Expected: tüm testler PASS

- [ ] **Step 9: Commit**

```bash
git add bootstrap/app.php routes/api.php config/services.php .env.example app/Http/Controllers/ShopifyOAuthController.php tests/Feature/ShopifyClaimTest.php
git commit -m "feat: internal shopify claim endpoint eklendi"
```

---

## Task 5: Ölü kodun temizlenmesi (Shopify-API)

**Repo:** `/Users/huseyin/Documents/GitHub/Shopify-API`

**Files:**
- Delete: `app/Services/ShopifyService.php`
- Modify: `config/services.php` (`dashboard.api_key` kaldırılır)
- Modify: `.env.example` (`DASHBOARD_API_KEY` satırı kaldırılır)

**Interfaces:** Yok — bu task sadece kullanılmayan kodu kaldırıyor, hiçbir arayüz değişmiyor.

- [ ] **Step 1: Confirm nothing references `ShopifyService`**

Run: `grep -rn "ShopifyService" app routes tests`
Expected: sonuç yok (zaten hiçbir route/controller bu sınıfı kullanmıyordu — `ClientIntegration` referansı da zaten kırıktı).

- [ ] **Step 2: Delete the dead service**

```bash
rm app/Services/ShopifyService.php
rmdir app/Services 2>/dev/null || true
```

- [ ] **Step 3: Remove now-unused `dashboard.api_key` config**

`config/services.php` içinde `'dashboard'` bloğunu şuna indir:

```php
    'dashboard' => [
        'url' => env('DASHBOARD_API_URL', 'https://dashboard.34devs.com'),
    ],
```

`.env.example` içinden `DASHBOARD_API_KEY=` satırını sil.

- [ ] **Step 4: Run full test suite**

Run: `php artisan test`
Expected: tüm testler PASS (silinen kod hiçbir testte kullanılmıyordu)

- [ ] **Step 5: Commit**

```bash
git add -A app/Services config/services.php .env.example
git commit -m "chore: kullanılmayan ShopifyService ve dashboard.api_key kaldırıldı"
```

---

## Task 6: `SHOPIFY_APP_INTERNAL_SECRET` config + claim HTTP client (34devs-dashboard backend)

**Repo:** `/Users/huseyin/Documents/GitHub/34devs-dashboard/backend`

**Files:**
- Modify: `config/services.php` (`shopify_app` bloğu genişletilir)
- Modify: `.env.example` (yeni env'ler eklenir)

**Interfaces:**
- Produces: `config('services.shopify_app.url')`, `config('services.shopify_app.internal_secret')` — Task 7'nin `ClientPortalShopifyController`'ı bunları kullanacak.

- [ ] **Step 1: Update `config/services.php`**

`'shopify_app'` bloğunu şuna genişlet:

```php
    'shopify_app' => [
        'api_key'         => env('SHOPIFY_APP_API_KEY'),
        'url'             => env('SHOPIFY_APP_URL', 'https://connect.34devs.com'),
        'internal_secret' => env('SHOPIFY_APP_INTERNAL_SECRET'),
    ],
```

- [ ] **Step 2: Update `.env.example`**

`SHOPIFY_SCOPES=...` satırından sonra ekle:

```
SHOPIFY_APP_URL=https://connect.34devs.com
SHOPIFY_APP_INTERNAL_SECRET=
```

- [ ] **Step 3: Commit**

```bash
git add config/services.php .env.example
git commit -m "feat: shopify-api internal claim çağrısı için config eklendi"
```

(Not: Bu task tek başına test edilebilir bir davranış üretmiyor — sadece Task 7'nin ihtiyaç duyduğu config'i hazırlıyor, bu yüzden ayrı, minimal bir commit.)

---

## Task 7: `POST /api/client-portal/shopify/connect` (34devs-dashboard backend)

**Repo:** `/Users/huseyin/Documents/GitHub/34devs-dashboard/backend`

**Files:**
- Create: `app/Http/Controllers/Api/ClientPortalShopifyController.php`
- Modify: `routes/api.php` (import + route eklenir)
- Test: `tests/Feature/ClientPortalShopifyConnectTest.php`

**Interfaces:**
- Consumes: `config('services.shopify_app.url')`, `config('services.shopify_app.internal_secret')` (Task 6); `ClientIntegration` modeli (mevcut, `app/Models/ClientIntegration.php`); `Client` modeli (mevcut).
- Produces: `POST /api/client-portal/shopify/connect` (auth:sanctum, client-portal grubu içinde) — body `{claim_token}`. Başarılıysa `200 {message, shop_name}`, claim başarısızsa `422`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ClientPortalShopifyConnectTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientPortalShopifyConnectTest extends TestCase
{
    use RefreshDatabase;

    public function test_connect_requires_client_portal_authentication(): void
    {
        $response = $this->postJson('/api/client-portal/shopify/connect', ['claim_token' => 'abc']);

        $response->assertStatus(401);
    }

    public function test_connect_creates_client_integration_from_claimed_token(): void
    {
        config([
            'services.shopify_app.url'             => 'https://connect.34devs.com',
            'services.shopify_app.internal_secret' => 'shared-secret',
        ]);

        $client = Client::create(['name' => 'Test Brand', 'portal_enabled' => true]);
        Sanctum::actingAs($client, ['client']);

        Http::fake([
            'connect.34devs.com/api/internal/shopify/claim' => Http::response([
                'shop'         => 'test-shop.myshopify.com',
                'shop_name'    => 'Test Shop',
                'access_token' => 'shpat_abc123',
                'scope'        => 'read_orders',
            ]),
        ]);

        $response = $this->postJson('/api/client-portal/shopify/connect', ['claim_token' => 'claim-abc']);

        $response->assertOk()->assertJson(['shop_name' => 'Test Shop']);

        $this->assertDatabaseHas('client_integrations', [
            'client_id'    => $client->id,
            'platform'     => 'shopify',
            'status'       => 'connected',
            'account_id'   => 'test-shop.myshopify.com',
            'account_name' => 'Test Shop',
        ]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://connect.34devs.com/api/internal/shopify/claim'
                && $request->hasHeader('Authorization', 'Bearer shared-secret')
                && $request['claim_token'] === 'claim-abc';
        });
    }

    public function test_connect_returns_422_when_claim_fails(): void
    {
        config([
            'services.shopify_app.url'             => 'https://connect.34devs.com',
            'services.shopify_app.internal_secret' => 'shared-secret',
        ]);

        $client = Client::create(['name' => 'Test Brand', 'portal_enabled' => true]);
        Sanctum::actingAs($client, ['client']);

        Http::fake([
            'connect.34devs.com/api/internal/shopify/claim' => Http::response(['error' => 'gone'], 410),
        ]);

        $response = $this->postJson('/api/client-portal/shopify/connect', ['claim_token' => 'expired']);

        $response->assertStatus(422);
        $this->assertDatabaseCount('client_integrations', 0);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ClientPortalShopifyConnectTest`
Expected: FAIL — route yok (404).

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Api/ClientPortalShopifyController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientIntegration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ClientPortalShopifyController extends Controller
{
    private function client(Request $request): Client
    {
        $user = $request->user();
        if (!($user instanceof Client)) {
            abort(403, 'Bu alana erişim yetkiniz yok.');
        }
        if (!$user->portal_enabled) {
            $user->tokens()->delete();
            abort(403, 'Portal erişimi kapatıldı.');
        }
        return $user;
    }

    // POST /api/client-portal/shopify/connect
    public function connect(Request $request): \Illuminate\Http\JsonResponse
    {
        $client = $this->client($request);

        $request->validate(['claim_token' => 'required|string']);

        $response = Http::withToken(config('services.shopify_app.internal_secret'))
            ->post(rtrim(config('services.shopify_app.url'), '/') . '/api/internal/shopify/claim', [
                'claim_token' => $request->claim_token,
            ]);

        if (!$response->successful()) {
            return response()->json(['error' => 'Bağlantı bulunamadı veya süresi dolmuş.'], 422);
        }

        $data = $response->json();

        ClientIntegration::updateOrCreate(
            ['client_id' => $client->id, 'platform' => 'shopify'],
            [
                'status'       => 'connected',
                'account_id'   => $data['shop'],
                'account_name' => $data['shop_name'],
                'access_token' => encrypt($data['access_token']),
                'extra'        => ['shop_domain' => $data['shop'], 'scope' => $data['scope'] ?? null],
                'last_sync_at' => now(),
            ]
        );

        return response()->json(['message' => 'Shopify mağazanız bağlandı.', 'shop_name' => $data['shop_name']]);
    }
}
```

- [ ] **Step 4: Register the route**

`routes/api.php` üstündeki `use App\Http\Controllers\Api\{...}` import listesine `ClientPortalShopifyController,` ekle.

`Route::middleware('auth:sanctum')->prefix('client-portal')->group(function () { ... })` bloğunun içine (mevcut `client-portal` route'larının yanına) ekle:

```php
    Route::post('/shopify/connect', [ClientPortalShopifyController::class, 'connect']);
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=ClientPortalShopifyConnectTest`
Expected: PASS (3 tests)

- [ ] **Step 6: Run full suite**

Run: `php artisan test`
Expected: tüm testler PASS

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/ClientPortalShopifyController.php routes/api.php tests/Feature/ClientPortalShopifyConnectTest.php
git commit -m "feat: client-portal shopify connect endpoint eklendi"
```

---

## Task 8: `GET /api/client-portal/shopify/status` (34devs-dashboard backend)

**Repo:** `/Users/huseyin/Documents/GitHub/34devs-dashboard/backend`

**Files:**
- Modify: `app/Http/Controllers/Api/ClientPortalShopifyController.php` (`status()` metodu eklenir)
- Modify: `routes/api.php` (route eklenir)
- Test: `tests/Feature/ClientPortalShopifyStatusTest.php`

**Interfaces:**
- Consumes: `ClientIntegration` (mevcut), `Client->integrations()` ilişkisi (mevcut)
- Produces: `GET /api/client-portal/shopify/status` (auth:sanctum) — `200 {connected: bool, shop_name?, last_sync_at?}`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ClientPortalShopifyStatusTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientPortalShopifyStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_returns_false_when_not_connected(): void
    {
        $client = Client::create(['name' => 'Test Brand', 'portal_enabled' => true]);
        Sanctum::actingAs($client, ['client']);

        $response = $this->getJson('/api/client-portal/shopify/status');

        $response->assertOk()->assertJson(['connected' => false]);
    }

    public function test_status_returns_true_when_connected(): void
    {
        $client = Client::create(['name' => 'Test Brand', 'portal_enabled' => true]);
        ClientIntegration::create([
            'client_id'    => $client->id,
            'platform'     => 'shopify',
            'status'       => 'connected',
            'account_id'   => 'test-shop.myshopify.com',
            'account_name' => 'Test Shop',
            'last_sync_at' => now(),
        ]);
        Sanctum::actingAs($client, ['client']);

        $response = $this->getJson('/api/client-portal/shopify/status');

        $response->assertOk()->assertJson(['connected' => true, 'shop_name' => 'Test Shop']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ClientPortalShopifyStatusTest`
Expected: FAIL — route yok (404).

- [ ] **Step 3: Add `status()` method**

`app/Http/Controllers/Api/ClientPortalShopifyController.php` içine, `connect()` metodundan sonra ekle:

```php
    // GET /api/client-portal/shopify/status
    public function status(Request $request): \Illuminate\Http\JsonResponse
    {
        $client = $this->client($request);

        $integration = $client->integrations()
            ->where('platform', 'shopify')
            ->where('status', 'connected')
            ->first();

        if (!$integration) {
            return response()->json(['connected' => false]);
        }

        return response()->json([
            'connected'    => true,
            'shop_name'    => $integration->account_name,
            'last_sync_at' => $integration->last_sync_at?->toIso8601String(),
        ]);
    }
```

- [ ] **Step 4: Register the route**

`routes/api.php`'de `/shopify/connect` route'unun yanına ekle:

```php
    Route::get('/shopify/status', [ClientPortalShopifyController::class, 'status']);
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=ClientPortalShopifyStatusTest`
Expected: PASS (2 tests)

- [ ] **Step 6: Run full suite**

Run: `php artisan test`
Expected: tüm testler PASS

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/ClientPortalShopifyController.php routes/api.php tests/Feature/ClientPortalShopifyStatusTest.php
git commit -m "feat: client-portal shopify status endpoint eklendi"
```

---

## Task 9: Client-portal auth helper (34devs-dashboard frontend)

**Repo:** `/Users/huseyin/Documents/GitHub/34devs-dashboard/frontend`

**Files:**
- Create: `src/app/shopify-app/clientPortalAuth.ts`

**Interfaces:**
- Produces: `loginClientPortal(email, password): Promise<void>`, `getShopifyStatus(): Promise<{connected: boolean; shop_name?: string}>`, `connectShopify(claimToken: string): Promise<{shop_name: string}>`, `getStoredToken(): string | null` — Task 10'daki sayfa bunları kullanacak.

**Neden ayrı bir dosya (mevcut `@/lib/api`'yi kullanmıyoruz):** `@/lib/api` singleton'ı staff girişini `localStorage['auth_data']`'dan okuyup her isteğe otomatik ekliyor ve 401'de `/login`'e (staff login) redirect ediyor. Aynı tarayıcıda bir staff oturumu açıksa bu, client-portal token'ının yanlışlıkla staff token'ıyla karışmasına ve akışın ortasında yanlış bir sayfaya atılmaya yol açar. Bu yüzden bu akış kendi izole axios instance'ını ve kendi token anahtarını (`sessionStorage`) kullanıyor.

Bu task için otomatik test yok (frontend'de mevcut hiçbir sayfa için unit test altyapısı yok — Jest/Vitest kurulu değil). Doğrulama Task 10 sonunda manuel olarak yapılacak.

- [ ] **Step 1: Create the helper file**

Create `src/app/shopify-app/clientPortalAuth.ts`:

```ts
import axios from 'axios'

// Bilerek @/lib/api'den ayrı: o instance staff auth_data'sını kullanıp 401'de /login'e (staff) atıyor.
const shopifyConnectApi = axios.create({
  baseURL: process.env.NEXT_PUBLIC_API_URL,
  headers: { 'Content-Type': 'application/json' },
})

const TOKEN_KEY = 'shopify_connect_client_token'

export function getStoredToken(): string | null {
  if (typeof window === 'undefined') return null
  return sessionStorage.getItem(TOKEN_KEY)
}

function storeToken(token: string) {
  sessionStorage.setItem(TOKEN_KEY, token)
}

export async function loginClientPortal(email: string, password: string): Promise<void> {
  const { data } = await shopifyConnectApi.post('/client-portal/auth/login', { email, password })
  storeToken(data.token)
}

export async function getShopifyStatus(): Promise<{ connected: boolean; shop_name?: string }> {
  const { data } = await shopifyConnectApi.get('/client-portal/shopify/status', {
    headers: { Authorization: `Bearer ${getStoredToken()}` },
  })
  return data
}

export async function connectShopify(claimToken: string): Promise<{ shop_name: string }> {
  const { data } = await shopifyConnectApi.post(
    '/client-portal/shopify/connect',
    { claim_token: claimToken },
    { headers: { Authorization: `Bearer ${getStoredToken()}` } },
  )
  return data
}
```

- [ ] **Step 2: Type-check**

Run: `cd /Users/huseyin/Documents/GitHub/34devs-dashboard/frontend && npx tsc --noEmit`
Expected: bu dosyayla ilgili hata yok (Task 10 henüz yazılmadığı için `page.tsx`'in eski static hali hâlâ derlenir).

- [ ] **Step 3: Commit**

```bash
git add src/app/shopify-app/clientPortalAuth.ts
git commit -m "feat: shopify-app sayfası için izole client-portal auth helper'ı"
```

---

## Task 10: `shopify-app` sayfasının login → onay → başarı akışına yeniden yazılması (34devs-dashboard frontend)

**Repo:** `/Users/huseyin/Documents/GitHub/34devs-dashboard/frontend`

**Files:**
- Create: `src/app/shopify-app/ShopifyAppContent.tsx`
- Modify: `src/app/shopify-app/page.tsx` (tamamen değiştirilir — Suspense wrapper)

**Interfaces:**
- Consumes: `loginClientPortal`, `getShopifyStatus`, `connectShopify`, `getStoredToken` (Task 9)

**Not:** Next.js 16 App Router, `useSearchParams()` kullanan bileşenlerin bir `<Suspense>` sınırı içinde olmasını zorunlu kılıyor — bu yüzden asıl mantık ayrı bir client component'e (`ShopifyAppContent.tsx`) taşınıyor, `page.tsx` sadece Suspense wrapper'ı.

- [ ] **Step 1: Create `ShopifyAppContent.tsx`**

Create `src/app/shopify-app/ShopifyAppContent.tsx`:

```tsx
'use client'

import { useEffect, useState } from 'react'
import { useSearchParams } from 'next/navigation'
import { ExternalLink, Loader2 } from 'lucide-react'
import { loginClientPortal, getShopifyStatus, connectShopify, getStoredToken } from './clientPortalAuth'

type Screen = 'loading' | 'login' | 'confirm' | 'success' | 'already-connected' | 'error' | 'idle'

const ERROR_MESSAGES: Record<string, string> = {
  missing_params: "Eksik parametre ile geldiniz, lütfen Shopify'dan tekrar deneyin.",
  invalid_hmac: 'Güvenlik doğrulaması başarısız oldu, lütfen tekrar deneyin.',
  invalid_state: 'Bağlantı isteği doğrulanamadı, lütfen tekrar deneyin.',
}

export default function ShopifyAppContent() {
  const searchParams = useSearchParams()
  const pending = searchParams.get('pending')
  const shop = searchParams.get('shop')
  const errorParam = searchParams.get('error')

  const [screen, setScreen] = useState<Screen>('loading')
  const [shopName, setShopName] = useState<string>(shop ?? '')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [formError, setFormError] = useState('')
  const [submitting, setSubmitting] = useState(false)

  useEffect(() => {
    if (errorParam) {
      setScreen('error')
      return
    }
    if (!getStoredToken()) {
      setScreen(pending ? 'login' : 'idle')
      return
    }
    checkExistingStatus()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  async function checkExistingStatus() {
    try {
      const status = await getShopifyStatus()
      if (status.connected) {
        setShopName(status.shop_name ?? shopName)
        setScreen('already-connected')
      } else {
        setScreen(pending ? 'confirm' : 'idle')
      }
    } catch {
      setScreen(pending ? 'login' : 'idle')
    }
  }

  async function handleLogin(e: React.FormEvent) {
    e.preventDefault()
    setFormError('')
    setSubmitting(true)
    try {
      await loginClientPortal(email, password)
      await checkExistingStatus()
    } catch {
      setFormError('E-posta veya şifre hatalı.')
    } finally {
      setSubmitting(false)
    }
  }

  async function handleConnect() {
    if (!pending) return
    setFormError('')
    setSubmitting(true)
    try {
      const result = await connectShopify(pending)
      setShopName(result.shop_name)
      setScreen('success')
    } catch {
      setFormError('Bağlantı kurulamadı, lütfen tekrar deneyin.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="min-h-screen bg-gray-50 flex items-center justify-center p-6">
      <div className="w-full max-w-md">
        <div className="flex items-center justify-center gap-2 mb-8">
          <div className="w-9 h-9 bg-black rounded-xl flex items-center justify-center">
            <span className="text-[#ccff00] font-black text-sm">34</span>
          </div>
          <span className="font-black text-xl text-gray-900 tracking-tight">DEVS</span>
        </div>

        <div className="bg-white rounded-2xl border border-gray-100 p-8 flex flex-col items-center text-center gap-5">
          {screen === 'loading' && <Loader2 className="w-8 h-8 animate-spin text-gray-400" />}

          {screen === 'error' && (
            <>
              <h1 className="text-xl font-bold text-gray-900">Bağlantı kurulamadı</h1>
              <p className="text-sm text-gray-500">
                {ERROR_MESSAGES[errorParam ?? ''] ?? 'Beklenmeyen bir hata oluştu.'}
              </p>
            </>
          )}

          {screen === 'idle' && (
            <>
              <h1 className="text-xl font-bold text-gray-900">34devs Dashboard</h1>
              <p className="text-sm text-gray-500">
                Bu sayfa Shopify mağazanızdan gelen bir bağlantı linki ile açılmalıdır.
              </p>
            </>
          )}

          {screen === 'login' && (
            <>
              <h1 className="text-xl font-bold text-gray-900">Hesabınıza giriş yapın</h1>
              <p className="text-sm text-gray-500">
                {shopName ? `"${shopName}" mağazasını` : 'Mağazanızı'} 34devs hesabınıza bağlamak için giriş yapın.
              </p>
              <form onSubmit={handleLogin} className="w-full flex flex-col gap-3 text-left">
                <input
                  type="email"
                  required
                  placeholder="E-posta"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  className="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm"
                />
                <input
                  type="password"
                  required
                  placeholder="Şifre"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  className="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm"
                />
                {formError && <p className="text-xs text-red-500">{formError}</p>}
                <button
                  type="submit"
                  disabled={submitting}
                  className="flex items-center justify-center gap-2 px-5 py-2.5 text-sm font-bold text-white bg-black hover:bg-gray-800 rounded-xl transition-colors w-full disabled:opacity-50"
                >
                  {submitting ? <Loader2 className="w-4 h-4 animate-spin" /> : 'Giriş Yap'}
                </button>
              </form>
            </>
          )}

          {screen === 'confirm' && (
            <>
              <h1 className="text-xl font-bold text-gray-900">Mağazanızı bağlayın</h1>
              <p className="text-sm text-gray-500">
                <strong>{shopName}</strong> mağazasını 34devs hesabınıza bağlamak istiyor musunuz?
              </p>
              {formError && <p className="text-xs text-red-500">{formError}</p>}
              <button
                onClick={handleConnect}
                disabled={submitting}
                className="flex items-center justify-center gap-2 px-5 py-2.5 text-sm font-bold text-white bg-black hover:bg-gray-800 rounded-xl transition-colors w-full disabled:opacity-50"
              >
                {submitting ? <Loader2 className="w-4 h-4 animate-spin" /> : 'Bağla'}
              </button>
            </>
          )}

          {(screen === 'success' || screen === 'already-connected') && (
            <>
              <div className="inline-flex items-center gap-1.5 bg-emerald-50 text-emerald-600 text-xs font-semibold px-3 py-1 rounded-full mb-1">
                <span className="w-1.5 h-1.5 bg-emerald-500 rounded-full" />
                Aktif Entegrasyon
              </div>
              <h1 className="text-xl font-bold text-gray-900">34devs Dashboard</h1>
              <p className="text-xs text-gray-400 mt-1">
                {shopName ? `"${shopName}" mağazanız` : 'Shopify mağazanız'} başarıyla bağlı.
              </p>
              <a
                href="https://dashboard.34devs.com"
                target="_top"
                className="flex items-center gap-2 px-5 py-2.5 text-sm font-bold text-white bg-black hover:bg-gray-800 rounded-xl transition-colors w-full justify-center mt-2"
              >
                Dashboard&apos;a Git <ExternalLink className="w-3.5 h-3.5" />
              </a>
            </>
          )}
        </div>

        <p className="text-center text-[11px] text-gray-300 mt-6">
          © {new Date().getFullYear()} 34devs · Shopify Partner App
        </p>
      </div>
    </div>
  )
}
```

- [ ] **Step 2: Replace `page.tsx` with the Suspense wrapper**

Replace the entire content of `src/app/shopify-app/page.tsx`:

```tsx
import { Suspense } from 'react'
import ShopifyAppContent from './ShopifyAppContent'

export default function ShopifyAppPage() {
  return (
    <Suspense fallback={null}>
      <ShopifyAppContent />
    </Suspense>
  )
}
```

- [ ] **Step 3: Type-check and build**

Run: `cd /Users/huseyin/Documents/GitHub/34devs-dashboard/frontend && npx tsc --noEmit && npm run build`
Expected: hatasız derlenir.

- [ ] **Step 4: Manual verification (dev server)**

Backend'i (`connect.34devs.com` yerine local'de) ve dashboard backend/frontend'i ayağa kaldır, ardından tarayıcıda şu URL'leri sırayla dene:

1. `http://localhost:3000/shopify-app` (pending yok) → "idle" ekranı görünmeli.
2. `http://localhost:3000/shopify-app?error=invalid_hmac` → hata ekranı, doğru mesaj.
3. `http://localhost:3000/shopify-app?pending=test123&shop=deneme.myshopify.com` (henüz login olmadan, sessionStorage temiz) → login formu, mağaza adı metinde geçmeli.
4. Gerçek bir client-portal hesabıyla giriş yap (backend'de `portal_enabled=true` bir `Client` kaydı gerekir) → "confirm" ekranına geçmeli.
5. "Bağla"ya bas → gerçek bir `claim_token` ile (Task 3/4 akışından gelen) başarı ekranı görünmeli, `client_integrations` tablosunda kayıt oluşmalı.

Bu adım otomatik test değildir — sonucu kısaca burada raporla (ekran görüntüsü gerekmez, davranış tarifi yeterli).

- [ ] **Step 5: Commit**

```bash
git add src/app/shopify-app/ShopifyAppContent.tsx src/app/shopify-app/page.tsx
git commit -m "feat: shopify-app sayfası gerçek login/onay akışıyla yeniden yazıldı"
```

---

## Self-Review Notları

- **Spec coverage:** Spec'teki tüm bölümler (akış adım 1-8, bileşen değişiklikleri, hata senaryoları, kapsam dışı maddeler) Task 1-10'da karşılanıyor. `pending_shopify_connections` cleanup job'ı bilinçli olarak eklenmedi (spec'te YAGNI olarak işaretli).
- **Placeholder scan:** Tüm kod blokları eksiksiz; "TODO"/"benzer şekilde" yok.
- **Type consistency:** `PendingShopifyConnection::createForShop()` imzası Task 1'de tanımlanıp Task 3 ve Task 4'te aynı şekilde çağrılıyor. `ClientPortalShopifyController::client()` helper'ı Task 7'de tanımlanıp Task 8'de aynı şekilde tekrar kullanılıyor. Frontend'de `loginClientPortal`/`getShopifyStatus`/`connectShopify` imzaları Task 9'da tanımlanıp Task 10'da birebir aynı şekilde import ediliyor.
