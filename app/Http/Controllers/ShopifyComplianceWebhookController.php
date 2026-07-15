<?php

namespace App\Http\Controllers;

use App\Models\PendingShopifyConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShopifyComplianceWebhookController extends Controller
{
    // POST /api/webhooks/compliance  (Shopify'ın zorunlu GDPR webhook'ları)
    public function handle(Request $request): \Illuminate\Http\JsonResponse
    {
        if (!$this->verifyHmac($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $topic = (string) $request->header('X-Shopify-Topic', '');

        match ($topic) {
            'customers/data_request' => $this->handleCustomersDataRequest($request),
            'customers/redact'       => $this->handleCustomersRedact($request),
            'shop/redact'            => $this->handleShopRedact($request),
            default                  => Log::warning('Bilinmeyen compliance webhook topic\'i alındı', ['topic' => $topic]),
        };

        return response()->json(['message' => 'ok']);
    }

    // Bu app müşteri kişisel verisi hiç saklamıyor — sadece geçici (30 dk'lık) OAuth
    // token'ları tutuyor, kalıcı hiçbir müşteri/mağaza verisi yok. Redaksiyon
    // yapılacak bir şey olmadığı için sadece isteği loglayıp onaylıyoruz.
    private function handleCustomersDataRequest(Request $request): void
    {
        Log::info('GDPR customers/data_request alındı — saklanan müşteri verisi yok', [
            'shop_domain' => $request->json('shop_domain'),
        ]);
    }

    private function handleCustomersRedact(Request $request): void
    {
        Log::info('GDPR customers/redact alındı — saklanan müşteri verisi yok', [
            'shop_domain' => $request->json('shop_domain'),
        ]);
    }

    private function handleShopRedact(Request $request): void
    {
        $shop = $request->json('shop_domain');

        if ($shop) {
            PendingShopifyConnection::where('shop', $shop)->delete();
        }

        Log::info('GDPR shop/redact alındı, bekleyen bağlantı kayıtları temizlendi', ['shop_domain' => $shop]);
    }

    // Webhook HMAC doğrulaması OAuth'takinden farklı: ham istek gövdesi üzerinden
    // hesaplanır ve base64 ile kodlanır (OAuth'ta hex ile kodlanan, sıralı query
    // string üzerinden hesaplanan HMAC'ten farklı bir mekanizma).
    private function verifyHmac(Request $request): bool
    {
        $hmacHeader = (string) $request->header('X-Shopify-Hmac-Sha256', '');

        if ($hmacHeader === '') {
            return false;
        }

        $computed = base64_encode(
            hash_hmac('sha256', $request->getContent(), (string) config('services.shopify.client_secret'), true)
        );

        return hash_equals($computed, $hmacHeader);
    }
}
