<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'month_price',
        'year_price',
        'avg_price',
        'visitors',
        'upgraded_mail_title',
        'upgraded_mail_text',
        'downgraded_mail_title',
        'downgraded_mail_text',
        'limit_title',
        'limit_text',
        'subscription_title',
        'subscription_text',
        'is_deleted',
        'created_by',
        'updated_by',
        'deleted_by',
        'created_at',
        'updated_at',
        'deleted_at'
    ];
}
