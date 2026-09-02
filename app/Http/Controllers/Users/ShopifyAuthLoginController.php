<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Libraries\Shopifyapi;
use App\Mail\MailTemplate;
use App\Models\AdminUser;
use App\Models\EmailTemplate;
use App\Models\Plan;
use App\Models\RecurringPlanCharge;
use App\Models\UserToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tymon\JWTAuth\Facades\JWTAuth;

class ShopifyAuthLoginController extends Controller
{
    public $newUserToken = '';

    public function login(Request $request)
    {

        if ($request->boolean('embedded')) {
            $data = $request->except('hmac');
            $hmac = $request->input('hmac');
            ksort($data);
            $calculatedHMAC = hash_hmac('sha256', http_build_query($data), config('services.shopify.secret'));
            if ($hmac === $calculatedHMAC) {
                $shop = $request->input('shop');
                $storeData = AdminUser::where('shop_url', $shop)->first();

                // If they are on a legacy permanent token, redirect to OAuth to get an expiring token!
                if ($storeData && empty($storeData->refresh_token)) {
                    $params = ['shop_domain' => $shop, 'token' => '', 'api_key' => config('services.shopify.key'), 'secret' => config('services.shopify.secret')];
                    $shopifyapi = new Shopifyapi($params);
                    $authorizeURL = $shopifyapi->getAuthorizeUrl(config('services.shopify.scope'), env('SHOPIFY_AUTH_URL'));

                    return response()->json([
                        'authorize_url' => $authorizeURL,
                        'status' => 2,
                    ]);
                }

                $storeData->last_login_at = date('Y-m-d', time());
                $storeData->update();

                $this->newUserToken = JWTAuth::fromUser($storeData);

                $userToken = new UserToken;
                $userToken->user_token = $this->newUserToken;
                $userToken->ip = $_SERVER['REMOTE_ADDR'];
                $userToken->shop_id = $storeData->id;
                $userToken->save();

                return response()->json([
                    'access_token' => $this->newUserToken,
                    'plan_id' => $storeData->plan_id,
                    'status' => 1,
                ]);
            }
        }

        if ($request->get('code')) {
            date_default_timezone_set('UTC');
            $code = $request->get('code');
            $shop = $request->get('shop');
            $host = $request->get('host');

            $params = ['shop_domain' => $shop, 'token' => '', 'api_key' => config('services.shopify.key'), 'secret' => config('services.shopify.secret')];
            $shopifyapi = new Shopifyapi($params);

            $tokenResponse = $shopifyapi->getAccessToken($code);
            $token = is_array($tokenResponse) ? ($tokenResponse['access_token'] ?? '') : '';
            $refreshToken = is_array($tokenResponse) ? ($tokenResponse['refresh_token'] ?? null) : null;
            $expiresIn = is_array($tokenResponse) ? ($tokenResponse['expires_in'] ?? null) : null;
            $tokenExpiresAt = $expiresIn ? date('Y-m-d H:i:s', time() + $expiresIn) : null;

            $storeData = AdminUser::where('shop_url', $shop)->first();
            if ($token != '') {

                if (empty($storeData)) {

                    $storeData = new AdminUser;
                    $storeData->identifier = uniqid();
                    $storeData->shop_url = $shop;
                    $storeData->token = $token;
                    $storeData->refresh_token = $refreshToken;
                    $storeData->token_expires_at = $tokenExpiresAt;
                    $storeData->created_at = now();
                    $storeData->platform = 'shopify';
                    $storeData->is_active = 1;
                    $storeData->is_closed = 0;
                    $storeData->review_notice_at = date('Y-m-d', strtotime('+14 days'));
                    $storeData->host = $host;
                    $storeData->last_login_at = date('Y-m-d', time());
                    $storeData->save();

                    $this->newUserToken = JWTAuth::fromUser($storeData);

                    $userToken = new UserToken;
                    $userToken->user_token = $this->newUserToken;
                    $userToken->ip = $_SERVER['REMOTE_ADDR'];
                    $userToken->shop_id = $storeData->id;
                    $userToken->save();

                } else {

                    $storeData->token = $token;
                    $storeData->refresh_token = $refreshToken;
                    $storeData->token_expires_at = $tokenExpiresAt;
                    $storeData->mail_status = 1;
                    $storeData->is_active = 1;
                    $storeData->is_closed = 0;
                    $storeData->last_login_at = date('Y-m-d', time());
                    $storeData->updated_at = now();
                    $storeData->update();

                    $this->newUserToken = JWTAuth::fromUser($storeData);

                    $userToken = new UserToken;
                    $userToken->user_token = $this->newUserToken;
                    $userToken->ip = $_SERVER['REMOTE_ADDR'];
                    $userToken->shop_id = $storeData->id;
                    $userToken->save();
                }
            }

        } elseif ($request->post('shop', true) || ($request->get('shop', true))) {

            $shop = $request->input('shop');
            $params = ['shop_domain' => $shop, 'token' => '', 'api_key' => config('services.shopify.key'), 'secret' => config('services.shopify.secret')];

            $shopifyapi = new Shopifyapi($params);

            $authorizeURL = $shopifyapi->getAuthorizeUrl(config('services.shopify.scope'), env('SHOPIFY_AUTH_URL'));

            return response()->json([
                'authorize_url' => $authorizeURL,
                'status' => 2,
            ]);
        }

        return response()->json([
            'message' => 'Invalid request',
        ], 400);
    }

    public function addUser(Request $request)
    {
        if ($request->get('code')) {
            date_default_timezone_set('UTC');
            $code = $request->get('code');
            $shop = $request->get('shop');
            $host = $request->get('host');

            $params = ['shop_domain' => $shop, 'token' => '', 'api_key' => config('services.shopify.key'), 'secret' => config('services.shopify.secret')];
            $shopifyapi = new Shopifyapi($params);

            $tokenResponse = $shopifyapi->getAccessToken($code);
            $token = is_array($tokenResponse) ? ($tokenResponse['access_token'] ?? '') : '';
            $refreshToken = is_array($tokenResponse) ? ($tokenResponse['refresh_token'] ?? null) : null;
            $expiresIn = is_array($tokenResponse) ? ($tokenResponse['expires_in'] ?? null) : null;
            $tokenExpiresAt = $expiresIn ? date('Y-m-d H:i:s', time() + $expiresIn) : null;

            $storeData = AdminUser::where('shop_url', $shop)->first();
            if ($token != '') {

                if (empty($storeData)) {

                    $storeData = new AdminUser;
                    $storeData->identifier = uniqid();
                    $storeData->shop_url = $shop;
                    $storeData->token = $token;
                    $storeData->refresh_token = $refreshToken;
                    $storeData->token_expires_at = $tokenExpiresAt;
                    $storeData->created_at = now();
                    $storeData->platform = 'shopify';
                    $storeData->is_active = 1;
                    $storeData->is_closed = 0;
                    $storeData->review_notice_at = date('Y-m-d', strtotime('+14 days'));
                    $storeData->host = $host;
                    $storeData->last_login_at = date('Y-m-d', time());
                    $storeData->save();

                    $this->newUserToken = JWTAuth::fromUser($storeData);

                    $userToken = new UserToken;
                    $userToken->user_token = $this->newUserToken;
                    $userToken->ip = $_SERVER['REMOTE_ADDR'];
                    $userToken->shop_id = $storeData->id;
                    $userToken->save();

                } else {

                    $storeData->token = $token;
                    $storeData->refresh_token = $refreshToken;
                    $storeData->token_expires_at = $tokenExpiresAt;
                    $storeData->mail_status = 1;
                    $storeData->is_active = 1;
                    $storeData->is_closed = 0;
                    $storeData->last_login_at = date('Y-m-d', time());
                    $storeData->updated_at = now();
                    $storeData->update();

                    $this->newUserToken = JWTAuth::fromUser($storeData);

                    $userToken = new UserToken;
                    $userToken->user_token = $this->newUserToken;
                    $userToken->ip = $_SERVER['REMOTE_ADDR'];
                    $userToken->shop_id = $storeData->id;
                    $userToken->save();
                }

                $this->checkWebhook($shop, $host);

                return response()->json([
                    'access_token' => $this->newUserToken,
                    'plan_id' => $storeData->plan_id,
                    'status' => 1,
                ]);
            }
            $latestToken = UserToken::where('shop_id', $storeData->id)
                ->orderByDesc('id')   // latest by auto-increment
                ->first();

            if ($latestToken) {
                $token = $latestToken->user_token;
            }

            return response()->json([
                'access_token' => $token,
                'plan_id' => $storeData->plan_id,
                'status' => 1,
            ]);
        }
    }

    public function checkWebhook($shop, $host): void
    {
        if (! $shop) {
            echo 'Set cookies setting  "Always Allow " In safari browser. For  <a target="_blank" href="http://www.macworld.co.uk/how-to/mac/how-enable-cookies-mac-3462635/">enable cookies</a> in your browser and try again.';
            exit;
        }

        $adminUserData = AdminUser::where('shop_url', $shop)->first();
        $token = $adminUserData->token;
        $params = [
            'shop_domain' => $shop,
            'token' => $token,
            'api_key' => config('services.shopify.key'),
            'secret' => config('services.shopify.secret'),
        ];
        $shopifyapi = new Shopifyapi($params);

        /*  Get Shop Info from shop.json file */
        $shopdata = $shopifyapi->call('GET', '/admin/api/'.config('services.shopify.version').'/shop.json', '', $token);

        $user = AdminUser::where('shop_url', $shop)->first();
        $id = $user->id;
        $userData = AdminUser::find($id);
        $userData->shop_owner_name = $shopdata['shop_owner'];
        $userData->name = $shopdata['shop_owner'];
        $userData->shop_hash = md5(base64_encode(md5($shop)));
        $userData->domain = $shopdata['domain'];
        $userData->email = $shopdata['email'];
        $userData->address1 = $shopdata['address1'];
        $userData->address2 = $shopdata['address2'];
        $userData->city = $shopdata['city'];
        $userData->state = $shopdata['province'];
        $userData->zip = $shopdata['zip'];
        $userData->country = $shopdata['country'];
        $userData->main_domain = $shopdata['domain'];
        $userData->shopify_plan = $shopdata['plan_name'];

        if (! empty($host)) {
            $userData->host = $host;
        }

        $userData->update();

        $storeData = AdminUser::where('shop_url', $shop)->first();

        try {
            $this->syncFBMetafields($shop, $token, (string) $storeData->identifier);
        } catch (\Throwable $e) {
            Log::error('FB metafield sync failed', [
                'shop' => $shop,
                'error' => $e->getMessage(),
            ]);
        }

        if ($storeData->mail_status == 0) {
            $data = AdminUser::where('shop_url', $shop)->where('mail_status', 0)->first();
            $id = $data->id;
            $updateData = AdminUser::find($id);
            $updateData->mail_status = 1;
            $updateData->updated_at = now();
            $updateData->update();
        }

        $webhooks = $shopifyapi->call('GET', '/admin/api/'.config('services.shopify.version').'/webhooks.json', '', $token);
        $checkUninstall = 0;
        $webhookUninstallURL = route('uninstall');
        $webhookUninstall['webhook'] = ['topic' => 'app/uninstalled',
            'address' => $webhookUninstallURL,
            'format' => 'json'];
        try {
            if (is_array($webhooks)) {
                foreach ($webhooks as $webhook) {
                    if (is_array($webhook) && isset($webhook['address']) && $webhookUninstallURL == $webhook['address']) {
                        $checkUninstall = 1;
                    }
                }
            }

            if ($checkUninstall == 0) {
                $shopifyapi->call('POST', '/admin/api/'.config('services.shopify.version').'/webhooks.json', $webhookUninstall, $token);
            }
        } catch (ShopifyApiException $e) {

        }

        $webhookShopUpdateURL = route('shopify.webhook.shop-update');
        $webhookShopUpdate = [
            'webhook' => [
                'topic' => 'shop/update',
                'address' => $webhookShopUpdateURL,
                'format' => 'json',
            ],
        ];

        $checkShopUpdate = 0;
        if (is_array($webhooks)) {
            foreach ($webhooks as $webhook) {
                if (is_array($webhook) && isset($webhook['address']) && $webhookShopUpdateURL == $webhook['address']) {
                    $checkShopUpdate = 1;
                    break;
                }
            }
        }
        if ($checkShopUpdate == 0) {
            $shopifyapi->call('POST', '/admin/api/'.config('services.shopify.version').'/webhooks.json', $webhookShopUpdate, $token);
        }

        $this->pushScriptTag($shop, $token);

        /* When Store Live Then change pro plan to free plan */
        $shopify_plan = $storeData->shopify_plan;
        $shopify_plan_name = ['affiliate', 'partner_test', 'plus_partner_sandbox', 'staff', 'staff_business'];
        if (in_array($shopify_plan, $shopify_plan_name) && ! in_array($shopdata['plan_name'], $shopify_plan_name)) {
            date_default_timezone_set('UTC');
            $userData = AdminUser::find($storeData->id);
            $userData->visitors = 0;
            $userData->charge_id = 0;
            $userData->plan_id = 1;
            $userData->plan_type = 'Monthly';
            $userData->plan_created_at = now();
            $userData->current_charges = 0;
            $userData->next_reset_date = now();
            $userData->max_visitors_at = null;
            $userData->updated_at = now();
            $userData->update();

            $planData = RecurringPlanCharge::where('store', $storeData->id)->where('charge_id', $storeData->charge_id)->first();
            $id = $planData->id;
            $updateData = RecurringPlanCharge::find($id);
            $updateData->is_deleted = 1;
            $updateData->update();
        }
    }

    public function pushScriptTag($shop, $token)
    {

        $scriptUrl = env('SCRIPT_URL');
        $normalizedUrl = trim($scriptUrl);

        // Remove old duplicates (or all existing same-src tags)
        $this->removeScriptTagsBySrc($shop, $token, $scriptUrl);

        //        if (!$this->scriptTagExists($shop, $token, $normalizedUrl)) {
        //            $this->addScriptToShop($shop, $token, $normalizedUrl);
        //        }

        check_theme_embeded_status($shop, $token);
    }

    private function removeScriptTagsBySrc(string $shop, string $accessToken, string $scriptUrl): void
    {
        $version = config('services.shopify.version');

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $accessToken,
            'Accept' => 'application/json',
        ])->get("https://{$shop}/admin/api/{$version}/script_tags.json");

        if (! $response->successful()) {
            Log::warning('Failed to fetch script tags before cleanup', [
                'shop' => $shop,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return;
        }

        $tags = $response->json('script_tags', []);

        foreach ($tags as $tag) {
            $tagSrc = isset($tag['src']) ? trim($tag['src']) : '';
            $tagId = $tag['id'] ?? null;

            if ($tagId && $tagSrc === $scriptUrl) {
                $deleteResp = Http::withHeaders([
                    'X-Shopify-Access-Token' => $accessToken,
                    'Accept' => 'application/json',
                ])->delete("https://{$shop}/admin/api/{$version}/script_tags/{$tagId}.json");

                if (! $deleteResp->successful()) {
                    Log::warning('Failed to delete duplicate script tag', [
                        'shop' => $shop,
                        'script_tag_id' => $tagId,
                        'status' => $deleteResp->status(),
                        'body' => $deleteResp->body(),
                    ]);
                }
            }
        }
    }

    public function uninstall(Request $request)
    {
        $shop = $request->header('X-Shopify-Shop-Domain');

        if (! $shop) {
            return response()->json(['error' => 'Shop missing'], 400);
        }

        $storeData = AdminUser::where('shop_url', $shop)->first();
        $token = $storeData->token;

        if ($storeData->plan_id != 0 && $storeData->plan_id != '') {
            $planDetails = Plan::where('id', $storeData->plan_id)->first();

            $uninstallMessage = ':sob: Shopify: '.$storeData->shop_owner_name.' has uninstalled '.config('services.shopify.name');
            $title = $storeData->shop_owner_name.' has uninstalled '.config('services.shopify.name');
            $text = "\nPlan: ".$planDetails->name."\n";
            $text .= 'Email: '.$storeData->email."\n";
            $text .= 'Shop: '.$storeData->shop_url."\n";
            $text .= 'Visitors: '.$storeData->visitors."\n";
            $text .= 'Installed on: '.date('d M, Y', strtotime($storeData->created_at))."\n";
            $color = '#e83845';

            get_slack_message($uninstallMessage, $title, $text, $color);
        }
        ?>

        <?php
        // Send mail when user uninstall app
        if (isset($storeData->id) && ! empty($storeData->email)) {
            $email_templates = EmailTemplate::where('key', 'UNINSTALL_MAIL')->first();
            if (! empty($email_templates) && env('TEST_MAIL')) {
                Mail::to($storeData->email)->send(new MailTemplate($email_templates, $storeData->email, $storeData->identifier));
            }
        }

        $shopId = $storeData->id;
        $updateData = AdminUser::find($shopId);
        $updateData->charge_id = 0;
        $updateData->plan_type = '';
        $updateData->is_active = 0;
        $updateData->plan_id = 0;
        $updateData->updated_at = date('Y-m-d H:i:s');
        $updateData->current_charges = 0;
        $updateData->update();

        //        $widgetData = Widget::where('user_id',$shopId)->where('is_deleted', 0)->get();
        //
        //        foreach($widgetData as $widget) {
        //            $widget->is_deleted = 1;
        //            $widget->deleted_by = $shopId;
        //            $widget->deleted_at = now();
        //            $widget->update();
        //
        //            $currentWidgetSettings = WidgetSetting::where('widget_id',$widget->unique_id)->first();
        //            $ctaIconSetting = json_decode($currentWidgetSettings->cta_icon_setting, true);
        //            $existingImage = ChannelImage::where('img_name', $ctaIconSetting['custom_cta_file'])->where('shop_id', $storeData->id)->first();
        //            if($existingImage) {
        //                $existingImage->is_used = 0;
        //                $existingImage->updated_at = now();
        //                $existingImage->update();
        //            }
        //        }

        return response()->json(['status' => 1]);

    }

    /**
     * Generic Shopify GraphQL call.
     */
    private function shopifyGraphql(string $shop, string $token, string $query, array $variables = []): array
    {
        $version = config('services.shopify.version'); // e.g. 2026-01
        $url = "https://{$shop}/admin/api/{$version}/graphql.json";

        $payload = ['query' => $query];

        // Shopify expects variables to be an object, not [].
        // If empty, send {} explicitly.
        $payload['variables'] = ! empty($variables) ? $variables : new \stdClass;

        $resp = Http::withHeaders([
            'X-Shopify-Access-Token' => $token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post($url, $payload);

        if (! $resp->successful()) {
            throw new \RuntimeException("GraphQL HTTP error: {$resp->status()} {$resp->body()}");
        }

        $json = $resp->json();

        if (! empty($json['errors'])) {
            throw new \RuntimeException('GraphQL errors: '.json_encode($json['errors']));
        }

        return $json['data'] ?? [];
    }

    /**
     * Upsert BOTH app metafield + shop metafield:
     * namespace: fba, key: fba_id, type: json
     */
    private function syncFBMetafields(string $shop, string $token, string $userIdentifier): void
    {
        // 1) Get owner IDs
        $ids = $this->shopifyGraphql($shop, $token, <<<'GQL'
query {
  shop { id }
  currentAppInstallation { id }
}
GQL);

        $shopOwnerId = $ids['shop']['id'] ?? null;
        $appOwnerId = $ids['currentAppInstallation']['id'] ?? null;

        $value = json_encode([
            'user_identifier' => $userIdentifier,
            'shop' => $shop,
        ], JSON_UNESCAPED_SLASHES);

        $metafields = [];
        if ($appOwnerId) {
            $metafields[] = [
                'ownerId' => $appOwnerId,
                'namespace' => 'fba',
                'key' => 'fba_id',
                'type' => 'json',
                'value' => $value,
            ];
        }

        if ($shopOwnerId) {
            $metafields[] = [
                'ownerId' => $shopOwnerId,
                'namespace' => 'fba',
                'key' => 'fba_id',
                'type' => 'json',
                'value' => $value,
            ];
        }

        if (empty($metafields)) {
            return;
        }

        // 2) Upsert metafields
        $result = $this->shopifyGraphql($shop, $token, <<<'GQL'
mutation SetMetafields($metafields: [MetafieldsSetInput!]!) {
  metafieldsSet(metafields: $metafields) {
    metafields { id key namespace }
    userErrors { field message code }
  }
}
GQL, ['metafields' => $metafields]);

        $errors = $result['metafieldsSet']['userErrors'] ?? [];
        if (! empty($errors)) {
            throw new \RuntimeException('metafieldsSet userErrors: '.json_encode($errors));
        }
    }

    /**
     * Verify Shopify webhook HMAC.
     */
    private function verifyWebhookHmac(Request $request): bool
    {
        $hmacHeader = (string) $request->header('X-Shopify-Hmac-Sha256', '');
        $rawBody = $request->getContent();

        $calculated = base64_encode(hash_hmac(
            'sha256',
            $rawBody,
            config('services.shopify.secret'),
            true
        ));

        return hash_equals($hmacHeader, $calculated);
    }

    public function shopUpdateWebhook(Request $request)
    {
        if (! $this->verifyWebhookHmac($request)) {
            return response()->json(['error' => 'Invalid webhook signature'], 401);
        }

        $shop = $request->header('X-Shopify-Shop-Domain');
        if (! $shop) {
            return response()->json(['error' => 'Shop missing'], 400);
        }

        $storeData = AdminUser::where('shop_url', $shop)->first();
        if (! $storeData || ! $storeData->token) {
            return response()->json(['ok' => true], 200);
        }

        try {
            $this->syncFBMetafields($shop, $storeData->token, (string) $storeData->identifier);
        } catch (\Throwable $e) {
            Log::error('FB metafield resync failed on shop/update webhook', [
                'shop' => $shop,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['ok' => true], 200);
    }
}
