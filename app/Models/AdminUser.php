<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Contracts\JWTSubject;

#[Fillable([
    'name',
    'email',
    'password',
    'identifier',
    'next_reset_date',
    'is_sent_visitor_limit_mail',
    'visitors',
    'plan_id',
    'platform',
    'shop_owner_name',
    'shop_url',
    'shop_hash',
    'domain',
    'address1',
    'address2',
    'city',
    'state',
    'zip',
    'country',
    'mail_status',
    'main_domain',
    'shopify_plan',
    'token',
    'refresh_token',
    'token_expires_at',
    'is_active',
    'is_closed',
    'charge_id',
    'plan_type',
    'plan_created_at',
    'max_visitors_at',
    'current_charges',
    'show_maxvisit_hitpopup',
    'review_notice_at',
    'show_review',
    'rating_star',
    'host',
    'last_login_at',
    'plan_secret_key',
    'widget_mail_status',
    'is_expired'
])]

class AdminUser extends Authenticatable implements JWTSubject
{
    use Notifiable;

    protected $table = 'admin_users';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'next_reset_date' => 'date',
            'last_login_at' => 'date',
            'review_notice_at' => 'date',
        ];
    }

    /**
     * Shopify "expiring" offline tokens (see Shopifyapi::getAccessToken, expiring=1)
     * last about one hour. Refresh transparently whenever the token is read.
     */
    public function getTokenAttribute($value)
    {
        if (empty($value) || empty($this->attributes['refresh_token'] ?? null) || empty($this->attributes['token_expires_at'] ?? null)) {
            return $value;
        }

        // Still valid for at least another minute
        if (strtotime($this->attributes['token_expires_at'].' UTC') > time() + 60) {
            return $value;
        }

        return $this->refreshShopifyToken() ?? $value;
    }

    public function refreshShopifyToken(): ?string
    {
        return Cache::lock('shopify-token-refresh-'.$this->getKey(), 15)->block(10, function () {
            // Another request may have refreshed it while we waited for the lock
            $fresh = static::query()->whereKey($this->getKey())->first(['token', 'refresh_token', 'token_expires_at']);
            $freshRaw = $fresh ? $fresh->getRawOriginal() : [];
            if (! empty($freshRaw['token_expires_at']) && strtotime($freshRaw['token_expires_at'].' UTC') > time() + 60) {
                $this->attributes['token'] = $freshRaw['token'];
                $this->attributes['refresh_token'] = $freshRaw['refresh_token'];
                $this->attributes['token_expires_at'] = $freshRaw['token_expires_at'];

                return $freshRaw['token'];
            }

            $refreshToken = $freshRaw['refresh_token'] ?? $this->attributes['refresh_token'];

            $response = Http::asForm()->post("https://{$this->shop_url}/admin/oauth/access_token", [
                'client_id' => config('services.shopify.key'),
                'client_secret' => config('services.shopify.secret'),
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ]);

            if (! $response->successful() || ! $response->json('access_token')) {
                Log::error('Shopify token refresh failed', [
                    'shop' => $this->shop_url,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $expiresIn = $response->json('expires_in');
            $values = [
                'token' => $response->json('access_token'),
                'refresh_token' => $response->json('refresh_token') ?? $refreshToken,
                'token_expires_at' => $expiresIn ? gmdate('Y-m-d H:i:s', time() + (int) $expiresIn) : null,
            ];

            static::query()->whereKey($this->getKey())->update($values);
            foreach ($values as $key => $val) {
                $this->attributes[$key] = $val;
                $this->original[$key] = $val;
            }

            return $values['token'];
        });
    }

    /**
     * Get the identifier that will be stored in the subject claim of the JWT.
     *
     * @return mixed
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     *
     * @return array
     */
    public function getJWTCustomClaims()
    {
        return [];
    }
}
