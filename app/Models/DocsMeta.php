<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocsMeta extends Model
{
    protected $table = 'docs_meta';
    protected $fillable = ['name', 'tags', 'expiry', 'description', 'max_file_count'];

    protected $casts = [
        'tags' => 'array',
    ];
}
