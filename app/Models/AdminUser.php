<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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
