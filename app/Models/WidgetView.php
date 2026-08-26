<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WidgetView extends Model
{
    use HasFactory;
    protected $fillable=[
        'mobile_view',
        'desktop_view',
        'mobile_click',
        'desktop_click',
        'created_date',
        'widget_id'
    ];
}
