<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OfficialPosition extends Model
{
    use HasFactory;

    protected $fillable = ['position_name'];

    public function officials(): HasMany
    {
        return $this->hasMany(Official::class);
    }
}
