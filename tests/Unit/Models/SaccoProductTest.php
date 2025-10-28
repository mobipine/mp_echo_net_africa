<?php

namespace Tests\Unit\Models;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\{SaccoProduct, SaccoProductType, SaccoProductAttribute, ChartofAccounts};

class SaccoProductTest extends TestCase
{
    use RefreshDatabase;
    
    public function test_product_has_product_type(): void
    {
        $productType = SaccoProductType::create([
            'name' => 'Test Type',
            'slug' => 'test-type',
            'category' => 'savings',
        ]);
        
        $product = SaccoProduct::create([
            'product_type_id' => $productType->id,
            'name' => 'Test Product',
            'code' => 'TEST_PROD',
            'is_active' => true,
        ]);
        
        $this->assertInstanceOf(SaccoProductType::class, $product->productType);
        $this->assertEquals('Test Type', $product->productType->name);
    }
    
    public function test_product_can_have_attribute_values(): void
    {
        $productType = SaccoProductType::create([
            'name' => 'Test Type',
            'slug' => 'test-type',
            'category' => 'savings',
        ]);
        
        $product = SaccoProduct::create([
            'product_type_id' => $productType->id,
            'name' => 'Test Product',
            'code' => 'TEST_PROD',
            'is_active' => true,
        ]);
        
        $attribute = SaccoProductAttribute::create([
            'name' => 'Test Attribute',
            'slug' => 'test-attribute',
            'type' => 'string',
        ]);
        
        $product->attributeValues()->create([
            'attribute_id' => $attribute->id,
            'value' => 'test value',
        ]);
        
        $this->assertCount(1, $product->attributeValues);
        $this->assertEquals('test value', $product->getProductAttributeValue('test-attribute'));
    }
    
    public function test_product_can_map_to_chart_of_accounts(): void
    {
        $chartAccount = ChartofAccounts::create([
            'name' => 'Test Account',
            'slug' => 'test-account',
            'account_code' => '1001',
            'account_type' => 'Asset',
        ]);
        
        $productType = SaccoProductType::create([
            'name' => 'Test Type',
            'slug' => 'test-type',
            'category' => 'savings',
        ]);
        
        $product = SaccoProduct::create([
            'product_type_id' => $productType->id,
            'name' => 'Test Product',
            'code' => 'TEST_PROD',
            'is_active' => true,
        ]);
        
        $product->chartOfAccounts()->create([
            'account_type' => 'bank',
            'account_number' => '1001',
        ]);
        
        $this->assertCount(1, $product->chartOfAccounts);
        $this->assertEquals('1001', $product->getAccountNumber('bank'));
        $this->assertEquals('Test Account', $product->getAccountName('bank'));
    }
    
    public function test_active_scope_filters_active_products(): void
    {
        $productType = SaccoProductType::create([
            'name' => 'Test Type',
            'slug' => 'test-type',
            'category' => 'savings',
        ]);
        
        SaccoProduct::create([
            'product_type_id' => $productType->id,
            'name' => 'Active Product',
            'code' => 'ACTIVE',
            'is_active' => true,
        ]);
        
        SaccoProduct::create([
            'product_type_id' => $productType->id,
            'name' => 'Inactive Product',
            'code' => 'INACTIVE',
            'is_active' => false,
        ]);
        
        $activeProducts = SaccoProduct::active()->get();
        
        $this->assertCount(1, $activeProducts);
        $this->assertEquals('Active Product', $activeProducts->first()->name);
    }
    
    public function test_of_type_scope_filters_by_product_type(): void
    {
        $savingsType = SaccoProductType::create([
            'name' => 'Savings',
            'slug' => 'savings',
            'category' => 'savings',
        ]);
        
        $feeType = SaccoProductType::create([
            'name' => 'Fee',
            'slug' => 'fee',
            'category' => 'fee',
        ]);
        
        SaccoProduct::create([
            'product_type_id' => $savingsType->id,
            'name' => 'Savings Product',
            'code' => 'SAVINGS',
            'is_active' => true,
        ]);
        
        SaccoProduct::create([
            'product_type_id' => $feeType->id,
            'name' => 'Fee Product',
            'code' => 'FEE',
            'is_active' => true,
        ]);
        
        $savingsProducts = SaccoProduct::ofType('savings')->get();
        
        $this->assertCount(1, $savingsProducts);
        $this->assertEquals('Savings Product', $savingsProducts->first()->name);
    }
}
