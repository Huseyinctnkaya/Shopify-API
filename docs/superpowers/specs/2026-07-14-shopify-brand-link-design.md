# Shopify → 34devs Dashboard Marka Bağlama Akışı

**Tarih:** 2026-07-14
**Durum:** Onaylandı, plana geçiliyor
**Kapsam:** İki repo — `Shopify-API` (connect.34devs.com) ve `34devs-dashboard` (backend + frontend)

## Amaç

Bir marka Shopify mağazasına `34devs Shopify App`'i kurduğunda, OAuth ile alınan access token'ın hangi 34devs müşterisine (Client / "Marka") ait olduğunu güvenli bir şekilde belirlemek ve dashboard'daki mevcut Shopify veri çekme altyapısını (`ClientIntegration`, `ShopifyService`, `ShopifyDataController`) otomatik olarak devreye sokmak.

Şu ana kadar `Shopify-API` reposu, `client_id`'nin OAuth başlamadan önce zaten bilindiğini varsayıyordu. Bu artık geçerli değil: OAuth Shopify tarafından tetiklendiğinde hangi markaya ait olduğu bilinmiyor. Bu belirsizliği çözmek için marka, OAuth bittikten sonra kendi client-portal hesabıyla giriş yapıp bağlantıyı onaylayacak.

## Mevcut Durum (bulgular)

- `34devs-dashboard/backend` içinde zaten tam çalışan, bağımsız bir Shopify entegrasyonu var (`ShopifyOAuthController`, `ClientIntegration` model+migration, `ShopifyService`, `ShopifyDataController`). Bu akış `client_id` baştan bilindiğinde (staff dashboard içinden başlatıldığında) çalışıyor — bu akışa dokunulmuyor.
- `Shopify-API` reposundaki `ShopifyService.php`, var olmayan bir `ClientIntegration` modeline referans veriyor ve hiçbir route tarafından çağrılmıyor — ölü kod.
- Dashboard backend'de client-portal login endpoint'i (`POST /api/client-portal/auth/login`) zaten var ve çalışıyor (email = Client tablosunda unique, 1 email = 1 marka).
- Dashboard frontend'de client-portal'a ait **hiçbir UI yok** (`(auth)/login` sadece staff girişi yapıyor). `shopify-app/page.tsx` şu an statik bir "bağlandı" ekranı, hiçbir yerden linklenmiyor, gerçek bir akış içermiyor.
- Marka başına tek bir client-portal hesabı → tek bir Client kaydı olduğu için "hangi markaya bağlanacak" sorusu otomatik cevaplanıyor, ayrı bir seçim ekranına gerek yok.

## Akış

1. Marka, Shopify mağazasına app'i kurar (veya kurulum linkine tıklar) → Shopify, `connect.34devs.com/oauth/install?shop=X` adresine yönlendirir. `client_id` artık gerekmiyor.
2. `Shopify-API` OAuth handshake'i tamamlar (`/oauth/callback`): HMAC doğrular, `code`'u token'a çevirir, mağaza adını çeker.
3. Token, `Shopify-API`'nin kendi DB'sinde geçici bir `pending_shopify_connections` kaydına yazılır: `shop`, `shop_name`, `access_token` (encrypted), `scope`, tek kullanımlık `claim_token`, `expires_at` (now + 30 dk), `claimed_at` (nullable).
4. Tarayıcı `https://dashboard.34devs.com/shopify-app?pending={claim_token}&shop={shop}` adresine yönlendirilir. **Access token hiçbir zaman tarayıcıya/URL'e düşmez** — sadece rastgele, tahmin edilemez bir referans (`claim_token`) gider.
5. Dashboard frontend'de `shopify-app/page.tsx` yeniden yazılır:
   - `pending` ve `shop` query param'larını okur.
   - Client-portal oturumu yoksa: hafif bir email+şifre login formu gösterir (`POST /api/client-portal/auth/login`'i çağırır, dönen Sanctum token'ı saklar).
   - Login sonrası: "`{shop_name}` mağazasını hesabınıza bağlamak istiyor musunuz?" onay ekranı + **Bağla** butonu.
6. **Bağla**'ya basınca dashboard backend'de yeni endpoint çalışır: `POST /api/client-portal/shopify/connect` (auth:sanctum, client-portal token gerekli), body: `{ claim_token }`.
   - Bu endpoint, sunucudan sunucuya (paylaşılan bearer secret ile) `Shopify-API`'nin yeni `POST /internal/shopify/claim` endpoint'ini çağırır.
   - `Shopify-API` tarafı: `claim_token`'ı arar, süresi geçmemiş ve daha önce claim edilmemişse `{shop, shop_name, access_token, scope}` döner ve kaydı `claimed_at = now()` ile işaretler (tek seferlik — ikinci çağrı 410/404 döner).
   - Dashboard tarafı, dönen veriyle mevcut `ClientIntegration::updateOrCreate(['client_id' => giriş yapan marka, 'platform' => 'shopify'], [...])` mantığını çalıştırır (mevcut `saveToken`/`saveIntegration` ile aynı desen).
7. Dashboard, bağlantı başarılıysa başarı ekranı gösterir. Bu noktadan sonra dashboard'daki mevcut `ShopifyDataController` (summary/orders/products/customers) otomatik olarak veri çekmeye başlar — ekstra bir "veri çekme" mekanizması **kurulmuyor**, zaten var olan on-demand endpoint'ler yeterli.
8. Yeni: `GET /api/client-portal/shopify/status` — sayfa mount olduğunda "zaten bağlı mı" kontrolü için (mevcut bağlantıyı gösterip tekrar login/onay istemeyi atlamak için).

## Bileşen Değişiklikleri

### `Shopify-API` reposu
- **Yeni migration + model:** `pending_shopify_connections` / `PendingShopifyConnection`
- **`ShopifyOAuthController::install()`:** `client_id` validasyonu kaldırılır, sadece `shop` zorunlu.
- **`ShopifyOAuthController::callback()`:** `forwardToDashboard()` çağrısı kaldırılır; yerine pending kayıt oluşturma + dashboard'a redirect eklenir.
- **Yeni endpoint:** `POST /internal/shopify/claim` — mevcut `DASHBOARD_API_KEY`/`SHOPIFY_APP_API_KEY` çiftinden bağımsız, bu yön için yeni bir paylaşılan sır kullanılır: `Shopify-API` `.env`'de `INTERNAL_CLAIM_SECRET`, dashboard `.env`'de `SHOPIFY_APP_INTERNAL_SECRET` — aynı değer, dashboard'dan gelen isteği doğrulamak için.
- **Silinecek:** `app/Services/ShopifyService.php` — kullanılmıyor, `ClientIntegration` referansı zaten kırıktı.

### `34devs-dashboard` backend
- **Yeni endpoint:** `POST /api/client-portal/shopify/connect` (auth:sanctum, client-portal token) — `Shopify-API`'nin claim endpoint'ini çağırıp `ClientIntegration`'ı oluşturur/günceller.
- **Yeni endpoint:** `GET /api/client-portal/shopify/status` — bağlı mı kontrolü.
- Yeni config: `services.shopify_app.internal_secret` (`SHOPIFY_APP_INTERNAL_SECRET` env'den), `Shopify-API`'nin `INTERNAL_CLAIM_SECRET` değeriyle aynı — claim çağrısında `Authorization: Bearer` olarak gönderilir.

### `34devs-dashboard` frontend
- **`shopify-app/page.tsx` yeniden yazılır:** pending param okuma → login formu (yeni, minimal — tam client-portal UI'ı DEĞİL) → onay ekranı → başarı/hata ekranı.
- Bu sayfa dışında client-portal'a dair başka bir UI **kurulmuyor** (kapsam dışı — sadece bu bağlama akışı için gereken minimum login formu).

## Hata Senaryoları

- `claim_token` süresi dolmuş / bulunamıyor / zaten claim edilmiş → dashboard sayfası "Bağlantı süresi doldu, lütfen Shopify'dan tekrar deneyin" mesajı gösterir.
- Client-portal login başarısız → mevcut "E-posta veya şifre hatalı" mesajı.
- Shopify OAuth HMAC doğrulaması başarısız → `Shopify-API` callback'i zaten mevcut error redirect mantığını kullanır (artık dashboard'un `/shopify-app?error=...`'ına yönlenecek şekilde güncellenir).
- İki backend arası claim çağrısı başarısız (network/secret hatası) → dashboard "Bağlantı kurulamadı, tekrar deneyin" gösterir, pending kayıt claim edilmemiş sayılır (kullanıcı tekrar deneyebilir, TTL dolana kadar).

## Kapsam Dışı (YAGNI)

- Bir client-portal hesabının birden fazla markaya/mağazaya bağlanması (şu an 1 email = 1 marka varsayımı sabit).
- Tam bir client-portal frontend'i (dashboard, projeler, faturalar vb. — sadece bu Shopify bağlama ekranı yazılıyor).
- Pending kayıtlar için ayrı bir cleanup job/scheduled command — düşük hacimli tablo, süresi geçmiş kayıtlar zararsız; ihtiyaç olursa sonra eklenir.
- Shopify App Store'a resmi listeleme / embedded App Bridge entegrasyonu — bu app hâlâ "custom/unlisted" bir app olarak kalıyor.

## Test Planı

- `Shopify-API`: `install()` artık `client_id` olmadan 200/redirect veriyor mu; `callback()` pending kayıt oluşturuyor mu; `/internal/shopify/claim` doğru secret olmadan 401, doğru secret + geçerli token ile 200 + tek seferlik (ikinci çağrı 410) davranıyor mu.
- Dashboard backend: `/api/client-portal/shopify/connect` — geçersiz claim_token, başarılı claim + `ClientIntegration` oluşturma/güncelleme, auth olmadan 401.
- Dashboard frontend: manuel/Playwright ile pending param'lı sayfa açılışı → login → onay → başarı ekranı gözle doğrulanacak (mevcut `webapp-testing` skill ile).
