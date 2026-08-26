<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CtaImage extends Model
{
    use HasFactory;
    protected $fillable = [
        'img_name',
        'shop_id',
        'is_used',
        'created_at',
        'updated_at',
    ];
}
