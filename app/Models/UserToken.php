<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_token', 'ip', 'shop_id'])]
class UserToken extends Model
{
    protected $table = 'user_tokens';

    public function adminUser()
    {
        return $this->belongsTo(AdminUser::class, 'shop_id');
    }
}
