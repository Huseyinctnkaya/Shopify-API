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
