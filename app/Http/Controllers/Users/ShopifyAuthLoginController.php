<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Libraries\Shopifyapi;
use App\Libraries\ShopifyApiException;
use App\Mail\MailTemplate;
use App\Models\AdminUser;
use App\Models\CtaImage;
use App\Models\EmailTemplate;
use App\Models\Plan;
use App\Models\RecurringPlanCharge;
use App\Models\Submission;
use App\Models\UserToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Models\Widget;
use App\Models\WidgetSetting;
use App\Models\WidgetView;

class ShopifyAuthLoginController extends Controller
{
    public $newUserToken = '';

    /**
     * Max age (seconds) of a Shopify-signed embedded-app URL before it is rejected.
     */
    private const SIGNED_REQUEST_MAX_AGE = 300;

    /**
     * Verify the HMAC Shopify appends to signed query strings (OAuth callback, embedded app load).
     */
    private function verifyQueryHmac(Request $request): bool
    {
        $hmac = $request->input('hmac');
        if (! is_string($hmac) || $hmac === '') {
            return false;
        }

        $data = $request->except('hmac');
        ksort($data);

        $calculated = hash_hmac('sha256', http_build_query($data), (string) config('services.shopify.secret'));

        return hash_equals($calculated, $hmac);
    }

    /**
     * A signed request is only valid shortly after Shopify issued it.
     */
    private function isFreshTimestamp(mixed $timestamp): bool
    {
        if (! is_numeric($timestamp)) {
            return false;
        }

        return abs(time() - (int) $timestamp) <= self::SIGNED_REQUEST_MAX_AGE;
    }

    private function issueUserToken(AdminUser $storeData): string
    {
        $token = JWTAuth::fromUser($storeData);

        $userToken = new UserToken;
        $userToken->user_token = $token;
        $userToken->ip = request()->ip();
        $userToken->shop_id = $storeData->id;
        $userToken->save();

        return $token;
    }

    public function login(Request $request)
    {
        $shop = $request->input('shop');
        if (! Shopifyapi::isValidShopDomain($shop)) {
            return response()->json(['message' => 'Invalid shop'], 400);
        }

        if ($request->boolean('embedded')) {
            if ($this->verifyQueryHmac($request) && $this->isFreshTimestamp($request->input('timestamp'))) {
                $storeData = AdminUser::where('shop_url', $shop)->first();

                if ($storeData) {
                    // If they are on a legacy permanent token, redirect to OAuth to get an expiring token!
                    if (empty($storeData->refresh_token)) {
                        $params = ['shop_domain' => $shop, 'token' => '', 'api_key' => config('services.shopify.key'), 'secret' => config('services.shopify.secret')];
                        $shopifyapi = new Shopifyapi($params);
                        $authorizeURL = $shopifyapi->getAuthorizeUrl(config('services.shopify.scope'), config('services.shopify.auth_url'));

                        return response()->json([
                            'authorize_url' => $authorizeURL,
                            'status' => 2,
                        ]);
                    }

                    $storeData->last_login_at = date('Y-m-d', time());
                    $storeData->update();

                    $this->newUserToken = $this->issueUserToken($storeData);

                    return response()->json([
                        'access_token' => $this->newUserToken,
                        'plan_id' => $storeData->plan_id,
                        'status' => 1,
                    ]);
                }
            }
        }

        if ($request->get('code')) {
            return $this->addUser($request);
        }

        $params = ['shop_domain' => $shop, 'token' => '', 'api_key' => config('services.shopify.key'), 'secret' => config('services.shopify.secret')];
        $shopifyapi = new Shopifyapi($params);
        $authorizeURL = $shopifyapi->getAuthorizeUrl(config('services.shopify.scope'), config('services.shopify.auth_url'));

        return response()->json([
            'authorize_url' => $authorizeURL,
            'status' => 2,
        ]);
    }

    /**
     * Completes the OAuth install: exchanges the Shopify-signed callback code for an access token.
     */
    public function addUser(Request $request)
    {
        $code = $request->input('code');
        $shop = $request->input('shop');
        $host = $request->input('host');

        if (! $code || ! Shopifyapi::isValidShopDomain($shop)) {
            return response()->json(['message' => 'Invalid request'], 400);
        }

        // The OAuth callback must carry Shopify's signature; a bare shop + code is not enough.
        if (! $this->verifyQueryHmac($request) || ! $this->isFreshTimestamp($request->input('timestamp'))) {
            return response()->json(['message' => 'Invalid request signature'], 401);
        }

        date_default_timezone_set('UTC');

        $params = ['shop_domain' => $shop, 'token' => '', 'api_key' => config('services.shopify.key'), 'secret' => config('services.shopify.secret')];
        $shopifyapi = new Shopifyapi($params);

        $tokenResponse = $shopifyapi->getAccessToken($code);
        $token = is_array($tokenResponse) ? ($tokenResponse['access_token'] ?? '') : '';
        $refreshToken = is_array($tokenResponse) ? ($tokenResponse['refresh_token'] ?? null) : null;
        $expiresIn = is_array($tokenResponse) ? ($tokenResponse['expires_in'] ?? null) : null;
        $tokenExpiresAt = $expiresIn ? date('Y-m-d H:i:s', time() + $expiresIn) : null;

        if ($token == '') {
            // Shopify rejected the code. Never fall back to an existing session token.
            return response()->json(['message' => 'Unable to authorize shop'], 401);
        }

        $storeData = AdminUser::where('shop_url', $shop)->first();

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
        }

        $this->newUserToken = $this->issueUserToken($storeData);

        $this->checkWebhook($shop, $host);

        return response()->json([
            'access_token' => $this->newUserToken,
            'plan_id' => $storeData->plan_id,
            'status' => 1,
        ]);
    }

    public function checkWebhook($shop, $host): void
    {
        if (! $shop) {
            // Was echo+exit — killed the whole HTTP response with raw HTML
            // instead of letting the caller's normal JSON response continue.
            Log::warning('checkWebhook called without a shop — likely a third-party-cookie issue in the merchant\'s browser');

            return;
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
            Log::warning('Failed to register uninstall webhook', [
                'shop' => $shop,
                'response' => $e->getResponse(),
            ]);
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

        $this->refreshThemeEmbedStatus($shop, $token);

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

    /**
     * The storefront script is delivered by the theme app embed, so only its status is checked.
     */
    public function refreshThemeEmbedStatus($shop, $token): void
    {
        check_theme_embeded_status($shop, $token);
    }

    public function uninstall(Request $request)
    {
        if (! $this->verifyWebhookHmac($request)) {
            return response()->json(['error' => 'Invalid webhook signature'], 401);
        }

        $shop = $request->header('X-Shopify-Shop-Domain');

        if (! $shop) {
            return response()->json(['error' => 'Shop missing'], 400);
        }

        $storeData = AdminUser::where('shop_url', $shop)->first();
        if (! $storeData) {
            return response()->json(['status' => 1]);
        }

        if ($storeData->plan_id != 0 && $storeData->plan_id != '') {
            $planDetails = Plan::where('id', $storeData->plan_id)->first();

            $uninstallMessage = ':sob: Shopify: '.$storeData->shop_owner_name.' has uninstalled '.config('services.shopify.name');
            $title = $storeData->shop_owner_name.' has uninstalled '.config('services.shopify.name');
            $text = "\nPlan: ".($planDetails->name ?? '')."\n";
            $text .= 'Email: '.$storeData->email."\n";
            $text .= 'Shop: '.$storeData->shop_url."\n";
            $text .= 'Visitors: '.$storeData->visitors."\n";
            $text .= 'Installed on: '.date('d M, Y', strtotime($storeData->created_at))."\n";
            $color = '#e83845';

            get_slack_message($uninstallMessage, $title, $text, $color);
        }

        // Send mail when user uninstall app
        if (! empty($storeData->email)) {
            $email_templates = EmailTemplate::where('key', 'UNINSTALL_MAIL')->first();
            if (! empty($email_templates) && config('services.slack.test_mail')) {
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

        // Sessions must not outlive the installation.
        UserToken::where('shop_id', $shopId)->delete();

        $widgetData = Widget::where('user_id', $shopId)->where('is_deleted', 0)->get();

        foreach ($widgetData as $widget) {
            $widget->is_deleted = 1;
            $widget->deleted_by = $shopId;
            $widget->deleted_at = now();
            $widget->update();

            $currentWidgetSettings = WidgetSetting::where('widget_id', $widget->unique_id)->first();
            $displayRuleSetting = $currentWidgetSettings ? json_decode($currentWidgetSettings->display_rule_setting, true) : null;
            $ctaFile = is_array($displayRuleSetting) ? ($displayRuleSetting['custom_cta_file'] ?? null) : null;
            $existingImage = $ctaFile ? CtaImage::where('img_name', $ctaFile)->where('shop_id', $storeData->id)->first() : null;
            if ($existingImage) {
                $existingImage->is_used = 0;
                $existingImage->updated_at = now();
                $existingImage->update();
            }
        }

        return response()->json(['status' => 1]);

    }

    /**
     * Mandatory privacy webhooks (customers/data_request, customers/redact, shop/redact).
     */
    public function complianceWebhook(Request $request)
    {
        if (! $this->verifyWebhookHmac($request)) {
            return response()->json(['error' => 'Invalid webhook signature'], 401);
        }

        $topic = (string) $request->header('X-Shopify-Topic', '');
        $shop = (string) ($request->input('shop_domain') ?: $request->header('X-Shopify-Shop-Domain', ''));
        $storeData = AdminUser::where('shop_url', $shop)->first();

        if (! $storeData) {
            return response()->json(['ok' => true], 200);
        }

        if ($topic === 'customers/data_request') {
            // Submissions are held on the merchant's behalf; the merchant can export them from the app.
            Log::info('Shopify customers/data_request received', ['shop' => $shop]);
        } elseif ($topic === 'customers/redact') {
            $email = (string) data_get($request->all(), 'customer.email', '');
            if ($email !== '') {
                $like = '%'.addcslashes($email, '%_\\').'%';
                $widgetIds = Widget::where('user_id', $storeData->id)->pluck('id');
                Submission::whereIn('widget_id', $widgetIds)->where('fields', 'like', $like)->delete();
            }
        } elseif ($topic === 'shop/redact') {
            $widgets = Widget::where('user_id', $storeData->id)->get();
            foreach ($widgets as $widget) {
                Submission::where('widget_id', $widget->id)->delete();
                WidgetView::where('widget_id', $widget->id)->delete();
                WidgetSetting::where('widget_id', $widget->unique_id)->delete();
                $widget->delete();
            }
            CtaImage::where('shop_id', $storeData->id)->delete();
            UserToken::where('shop_id', $storeData->id)->delete();
            $storeData->token = '';
            $storeData->refresh_token = null;
            $storeData->email = null;
            $storeData->shop_owner_name = null;
            $storeData->name = null;
            $storeData->address1 = null;
            $storeData->address2 = null;
            $storeData->city = null;
            $storeData->zip = null;
            $storeData->is_active = 0;
            $storeData->update();
        }

        return response()->json(['ok' => true], 200);
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
