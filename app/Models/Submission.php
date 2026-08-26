<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Submission extends Model
{
    use HasFactory;

    protected $fillable = [
        'fields',
        'page_url',
        'widget_id',
        'ip_address',
        'is_from_mobile',
    ];

    protected $casts = [
        'fields' => 'array',
        'is_from_mobile' => 'boolean',
    ];
}
