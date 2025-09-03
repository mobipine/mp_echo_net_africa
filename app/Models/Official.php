<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Official extends Model
{
    protected $fillable = [
        'group_id',
        'member_id',
        'official_position_id',
    ];

    /**
     * 
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * 
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * The position the member holds.
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(OfficialPosition::class, 'official_position_id');
    }
}
