<?php

namespace App\Libraries;

use Illuminate\Support\Facades\Http;

class ShopifyRest
{
    public static function post(string $shop, string $token, string $endpoint, array $payload): array
    {
        $url = "https://{$shop}/admin/api/" .
            config('services.shopify.graphic_version') .
            "/{$endpoint}";

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $token,
            'Content-Type' => 'application/json',
        ])->post($url, $payload);

        return [
            'status' => $response->status(),
            'body'   => $response->json(),
        ];
    }
}
