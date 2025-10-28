<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationGroupCapitalTransfer extends Model
{
    protected $fillable = [
        'group_id',
        'transfer_type',
        'amount',
        'transfer_date',
        'reference_number',
        'purpose',
        'approved_by',
        'status',
        'created_by',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transfer_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the group
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * Get the user who approved the transfer
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the user who created the transfer
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get all transactions related to this transfer
     */
    public function transactions()
    {
        return Transaction::where('metadata->transfer_id', $this->id)->get();
    }

    /**
     * Scope to filter by transfer type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('transfer_type', $type);
    }

    /**
     * Scope to filter by status
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to get pending transfers
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope to get completed transfers
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }
}

