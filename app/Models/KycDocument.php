<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KycDocument extends Model
{
    protected $fillable = [
        'member_id',
        'document_type',
        'file_path',
        'description',
    ];
    
}
