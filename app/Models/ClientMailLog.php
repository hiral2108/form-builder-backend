<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientMailLog extends Model
{
    use HasFactory;
    protected $fillable = [
        'to_mail',
        'subject',
        'content',
        'unique_id'
    ];
}
