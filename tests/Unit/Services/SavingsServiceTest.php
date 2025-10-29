<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\{Member, Group, SaccoProduct, SaccoProductType, MemberSavingsAccount, ChartofAccounts};
use App\Services\SavingsService;

class SavingsServiceTest extends TestCase
{
    use RefreshDatabase;
    
    protected SavingsService $service;
    protected Member $member;
    protected SaccoProduct $product;
    protected MemberSavingsAccount $savingsAccount;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SavingsService::class);
        
        // Create test data
        $this->createTestData();
    }
    
    private function createTestData(): void
    {
        // Create chart of accounts
        ChartofAccounts::create([
            'name' => 'Bank Account',
            'slug' => 'bank-account',
            'account_code' => '1001',
            'account_type' => 'Asset',
        ]);
        
        ChartofAccounts::create([
            'name' => 'Savings Liability',
            'slug' => 'savings-liability',
            'account_code' => '2201',
            'account_type' => 'Liability',
        ]);
        
        // Create group
        $group = Group::create([
            'name' => 'Test Group',
            'formation_date' => now()->subMonths(2),
        ]);
        
        // Create member
        $this->member = Member::create([
            'group_id' => $group->id,
            'name' => 'Test Member',
            'email' => 'test@example.com',
            'member_since' => now()->subMonths(1),
        ]);
        
        // Create product type
        $productType = SaccoProductType::create([
            'name' => 'Member Savings',
            'slug' => 'member-savings',
            'category' => 'savings',
        ]);
        
        // Create product
        $this->product = SaccoProduct::create([
            'product_type_id' => $productType->id,
            'name' => 'Test Savings',
            'code' => 'TEST_SAVINGS',
            'is_active' => true,
        ]);
        
        // Map chart of accounts
        $this->product->chartOfAccounts()->create([
            'account_type' => 'bank',
            'account_number' => '1001',
        ]);
        
        $this->product->chartOfAccounts()->create([
            'account_type' => 'savings_account',
            'account_number' => '2201',
        ]);
        
        // Open savings account
        $this->savingsAccount = $this->service->openSavingsAccount($this->member, $this->product);
    }
    
    public function test_open_savings_account_creates_account(): void
    {
        $member = Member::create([
            'group_id' => $this->member->group_id,
            'name' => 'Another Member',
            'email' => 'another@example.com',
        ]);
        
        $account = $this->service->openSavingsAccount($member, $this->product);
        
        $this->assertInstanceOf(MemberSavingsAccount::class, $account);
        $this->assertEquals($member->id, $account->member_id);
        $this->assertEquals('active', $account->status);
        $this->assertStringContainsString('SAV-TEST_SAVINGS', $account->account_number);
    }
    
    public function test_open_savings_account_returns_existing(): void
    {
        $account1 = $this->service->openSavingsAccount($this->member, $this->product);
        $account2 = $this->service->openSavingsAccount($this->member, $this->product);
        
        $this->assertEquals($account1->id, $account2->id);
    }
    
    public function test_deposit_creates_double_entry_transactions(): void
    {
        $result = $this->service->deposit($this->savingsAccount, 1000, 'cash');
        
        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['transactions']);
        $this->assertEquals(1000, $result['new_balance']);
        
        // Check double entry
        $transactions = $result['transactions'];
        $this->assertEquals('dr', $transactions[0]->dr_cr);
        $this->assertEquals('cr', $transactions[1]->dr_cr);
        $this->assertEquals($transactions[0]->amount, $transactions[1]->amount);
    }
    
    public function test_multiple_deposits_accumulate_balance(): void
    {
        $this->service->deposit($this->savingsAccount, 1000, 'cash');
        $this->service->deposit($this->savingsAccount, 500, 'cash');
        $this->service->deposit($this->savingsAccount, 250, 'cash');
        
        $balance = $this->service->getBalance($this->savingsAccount);
        
        $this->assertEquals(1750, $balance);
    }
    
    public function test_withdrawal_reduces_balance(): void
    {
        // Set allows_withdrawal attribute
        $attr = \App\Models\SaccoProductAttribute::create([
            'name' => 'Allows Withdrawal',
            'slug' => 'allows_withdrawal',
            'type' => 'boolean',
        ]);
        
        $this->product->attributeValues()->create([
            'attribute_id' => $attr->id,
            'value' => 'true',
        ]);
        
        // Deposit first
        $this->service->deposit($this->savingsAccount, 1000, 'cash');
        
        // Then withdraw
        $result = $this->service->withdraw($this->savingsAccount, 300, 'cash');
        
        $this->assertTrue($result['success']);
        $this->assertEquals(700, $result['new_balance']);
    }
    
    public function test_withdrawal_fails_with_insufficient_balance(): void
    {
        // Set allows_withdrawal attribute
        $attr = \App\Models\SaccoProductAttribute::create([
            'name' => 'Allows Withdrawal',
            'slug' => 'allows_withdrawal',
            'type' => 'boolean',
        ]);
        
        $this->product->attributeValues()->create([
            'attribute_id' => $attr->id,
            'value' => 'true',
        ]);
        
        $this->service->deposit($this->savingsAccount, 500, 'cash');
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Insufficient balance');
        
        $this->service->withdraw($this->savingsAccount, 1000, 'cash');
    }
    
    public function test_get_cumulative_savings(): void
    {
        $this->service->deposit($this->savingsAccount, 1000, 'cash');
        $this->service->deposit($this->savingsAccount, 500, 'cash');
        
        $cumulative = $this->service->getCumulativeSavings($this->member);
        
        $this->assertEquals(1500, $cumulative);
    }
    
    public function test_get_cumulative_savings_for_period(): void
    {
        // Old deposit
        $this->service->deposit($this->savingsAccount, 1000, 'cash');
        
        // Update transaction date to be older
        \App\Models\Transaction::where('member_id', $this->member->id)
            ->update(['transaction_date' => now()->subMonths(3)]);
        
        // New deposit
        $this->service->deposit($this->savingsAccount, 500, 'cash');
        
        $cumulative = $this->service->getCumulativeSavings($this->member, 2);
        
        $this->assertEquals(500, $cumulative); // Only last 2 months
    }
}
