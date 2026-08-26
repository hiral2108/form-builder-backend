<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WidgetSetting extends Model
{
    use HasFactory;
    protected $fillable = [
        'form_field_setting',
        'form_style_setting',
        'display_rule_setting',
        'submission_setting',
        'time_delay_setting',
        'scroll_based_setting',
        'page_rule_setting',
        'date_time_setting',
        'day_hour_setting',
        'country_rule_setting',
        'widget_id'
    ];
}
