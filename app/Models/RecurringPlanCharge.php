<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RecurringPlanCharge extends Model
{
    use HasFactory;
    protected $fillable = [
        'store',
        'charge_id',
        'plan_id',
        'api_client_id',
        'plan_type',
        'price',
        'status',
        'return_url',
        'billing_on',
        'created_at',
        'updated_at',
        'test',
        'activated_on',
        'trial_ends_on',
        'cancelled_on',
        'trial_days',
        'decorated_return_url',
        'confirmation_url',
        'is_deleted',
        'created_date',
        'updated_date'
    ];
}
