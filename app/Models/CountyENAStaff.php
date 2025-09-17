<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CountyENAStaff extends Model
{
    protected $table = 'county_ENA_staffs';
    
    protected $fillable = ['name', 'county'];

    // Optional: Relationship to get county details
    public function county()
    {
        $counties = config('counties');
        return collect($counties)->firstWhere('code', $this->county);
    }
    public function groups(){
        return $this->hasMany(\App\Models\Group::class);
    }
}