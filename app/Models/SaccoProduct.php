<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaccoProduct extends Model
{
    protected $fillable = [
        'product_type_id',
        'name',
        'code',
        'description',
        'is_active',
        'is_mandatory',
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_mandatory' => 'boolean',
        'start_date' => 'date',
        'end_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the product type
     */
    public function productType()
    {
        return $this->belongsTo(SaccoProductType::class, 'product_type_id');
    }

    /**
     * Get all attribute values for this product
     */
    public function attributeValues()
    {
        return $this->hasMany(SaccoProductAttributeValue::class, 'sacco_product_id');
    }

    /**
     * Get chart of accounts mappings
     */
    public function chartOfAccounts()
    {
        return $this->hasMany(SaccoProductChartOfAccount::class, 'sacco_product_id');
    }

    /**
     * Get all savings accounts using this product
     */
    public function savingsAccounts()
    {
        return $this->hasMany(MemberSavingsAccount::class, 'sacco_product_id');
    }

    /**
     * Get all subscriptions to this product
     */
    public function subscriptions()
    {
        return $this->hasMany(MemberProductSubscription::class, 'sacco_product_id');
    }

    /**
     * Get product attribute value by slug
     */
    public function getProductAttributeValue(string $slug)
    {
        $attributeValue = $this->attributeValues()
            ->whereHas('attribute', fn($q) => $q->where('slug', $slug))
            ->first();
        
        return $attributeValue?->value;
    }

    /**
     * Get account number for a specific account type
     */
    public function getAccountNumber(string $accountType): ?string
    {
        return $this->chartOfAccounts()
            ->where('account_type', $accountType)
            ->first()?->account_number;
    }

    /**
     * Get account name for a specific account type
     */
    public function getAccountName(string $accountType): ?string
    {
        $accountNumber = $this->getAccountNumber($accountType);
        if (!$accountNumber) {
            return null;
        }
        
        return ChartofAccounts::where('account_code', $accountNumber)->first()?->name;
    }

    /**
     * Scope to get only active products
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get products by type
     */
    public function scopeOfType($query, string $typeSlug)
    {
        return $query->whereHas('productType', fn($q) => $q->where('slug', $typeSlug));
    }
}
