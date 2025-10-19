# SACCO System - Implementation Quick Start Guide

**For Developers:** Step-by-step guide to begin implementing the SACCO extension

---

## Prerequisites

- Laravel 10.x environment
- Filament 3.x installed
- Database access (MySQL/PostgreSQL)
- Understanding of double-entry accounting
- Read the technical report first

---

## Step 1: Database Setup (Week 1, Day 1-2)

### Create Migration Files

Run these commands in order:

```bash
# Product Type System
php artisan make:migration create_sacco_product_types_table
php artisan make:migration create_sacco_product_attributes_table
php artisan make:migration create_sacco_products_table
php artisan make:migration create_sacco_product_attribute_values_table
php artisan make:migration create_sacco_product_chart_of_accounts_table

# Member Savings
php artisan make:migration create_member_savings_accounts_table
php artisan make:migration create_member_product_subscriptions_table

# Loan Enhancements
php artisan make:migration create_loan_guarantors_table
php artisan make:migration create_loan_product_rules_table

# Transaction Types Registry
php artisan make:migration create_product_transaction_types_table

# Modifications to Existing
php artisan make:migration add_sacco_fields_to_transactions_table
php artisan make:migration add_formation_date_to_groups_table
php artisan make:migration add_membership_fields_to_members_table
```

### Copy Migration Content

Copy the schema definitions from Section 4 of the technical report into each migration file.

**Example:** `create_sacco_products_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sacco_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_type_id')->constrained('sacco_product_types')->onDelete('restrict');
            $table->string('name');
            $table->string('code', 50)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_mandatory')->default(false);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamps();
            
            $table->index('is_active');
            $table->index('product_type_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sacco_products');
    }
};
```

### Run Migrations

```bash
php artisan migrate
```

**Troubleshooting:**
- If foreign key errors: Check table creation order
- If column exists: Review existing table structure
- Rollback: `php artisan migrate:rollback --step=1`

---

## Step 2: Create Model Classes (Week 1, Day 3-4)

### Model File Structure

```
app/Models/
├── SaccoProduct.php
├── SaccoProductType.php
├── SaccoProductAttribute.php
├── SaccoProductAttributeValue.php
├── SaccoProductChartOfAccount.php
├── MemberSavingsAccount.php
├── MemberProductSubscription.php
├── LoanGuarantor.php
├── LoanProductRule.php
└── ProductTransactionType.php
```

### Create Models

```bash
php artisan make:model SaccoProduct
php artisan make:model SaccoProductType
php artisan make:model SaccoProductAttribute
php artisan make:model SaccoProductAttributeValue
php artisan make:model SaccoProductChartOfAccount
php artisan make:model MemberSavingsAccount
php artisan make:model MemberProductSubscription
php artisan make:model LoanGuarantor
php artisan make:model LoanProductRule
php artisan make:model ProductTransactionType
```

### Copy Model Content

Copy the model implementations from Section 9 of the technical report.

**Quick Checklist for Each Model:**
- [ ] `$fillable` array defined
- [ ] `$casts` array for dates/booleans/decimals
- [ ] Relationships defined
- [ ] Custom accessors/methods added

---

## Step 3: Seed Initial Data (Week 1, Day 4)

### Create Seeder

```bash
php artisan make:seeder SaccoInitialDataSeeder
```

### Seeder Content

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SaccoProductType;
use App\Models\SaccoProductAttribute;

class SaccoInitialDataSeeder extends Seeder
{
    public function run(): void
    {
        // Product Types
        $productTypes = [
            ['name' => 'Member Savings', 'slug' => 'member-savings', 'category' => 'savings'],
            ['name' => 'Subscription Product', 'slug' => 'subscription-product', 'category' => 'subscription'],
            ['name' => 'One-Time Fee', 'slug' => 'one-time-fee', 'category' => 'fee'],
            ['name' => 'Penalty/Fine', 'slug' => 'penalty-fine', 'category' => 'fine'],
        ];
        
        foreach ($productTypes as $type) {
            SaccoProductType::firstOrCreate(['slug' => $type['slug']], $type);
        }
        
        // Product Attributes
        $attributes = [
            [
                'name' => 'Payment Frequency',
                'slug' => 'payment_frequency',
                'type' => 'select',
                'options' => json_encode(['daily', 'weekly', 'monthly', 'quarterly', 'yearly']),
                'applicable_product_types' => json_encode(['subscription-product']),
            ],
            [
                'name' => 'Amount Per Cycle',
                'slug' => 'amount_per_cycle',
                'type' => 'decimal',
                'applicable_product_types' => json_encode(['subscription-product']),
            ],
            [
                'name' => 'Total Cycles',
                'slug' => 'total_cycles',
                'type' => 'integer',
                'applicable_product_types' => json_encode(['subscription-product']),
            ],
            [
                'name' => 'Max Total Amount',
                'slug' => 'max_total_amount',
                'type' => 'decimal',
                'applicable_product_types' => json_encode(['subscription-product']),
            ],
            [
                'name' => 'Minimum Deposit',
                'slug' => 'minimum_deposit',
                'type' => 'decimal',
                'applicable_product_types' => json_encode(['member-savings']),
            ],
            [
                'name' => 'Allows Withdrawal',
                'slug' => 'allows_withdrawal',
                'type' => 'boolean',
                'applicable_product_types' => json_encode(['member-savings']),
            ],
            [
                'name' => 'Calculation Formula',
                'slug' => 'calculation_formula',
                'type' => 'json',
                'applicable_product_types' => json_encode(['one-time-fee']),
            ],
        ];
        
        foreach ($attributes as $attr) {
            SaccoProductAttribute::firstOrCreate(['slug' => $attr['slug']], $attr);
        }
        
        $this->command->info('SACCO initial data seeded successfully!');
    }
}
```

### Run Seeder

```bash
php artisan db:seed --class=SaccoInitialDataSeeder
```

---

## Step 4: Create Service Classes (Week 1, Day 5 - Week 2)

### Service Directory Structure

```
app/Services/
├── TransactionService.php
├── BalanceCalculationService.php
├── SavingsService.php
├── SubscriptionService.php
├── LoanEligibilityService.php
├── GuarantorService.php
└── FeeCalculationService.php
```

### Create Service Files

```bash
mkdir -p app/Services
touch app/Services/TransactionService.php
touch app/Services/BalanceCalculationService.php
touch app/Services/SavingsService.php
touch app/Services/SubscriptionService.php
touch app/Services/LoanEligibilityService.php
touch app/Services/GuarantorService.php
touch app/Services/FeeCalculationService.php
```

### Copy Service Implementations

Copy the service code from Sections 5, 6, and 7 of the technical report.

### Register Services

Create `app/Providers/SaccoServiceProvider.php`:

```php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\{
    TransactionService,
    BalanceCalculationService,
    SavingsService,
    SubscriptionService,
    LoanEligibilityService,
    GuarantorService,
    FeeCalculationService
};

class SaccoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TransactionService::class);
        $this->app->singleton(BalanceCalculationService::class);
        $this->app->singleton(SavingsService::class);
        $this->app->singleton(SubscriptionService::class);
        $this->app->singleton(LoanEligibilityService::class);
        $this->app->singleton(GuarantorService::class);
        $this->app->singleton(FeeCalculationService::class);
    }
}
```

Register in `bootstrap/providers.php` (Laravel 11) or `config/app.php` (Laravel 10):

```php
return [
    App\Providers\AppServiceProvider::class,
    App\Providers\SaccoServiceProvider::class, // Add this line
];
```

---

## Step 5: Update Existing Models (Week 2, Day 1)

### Update `app/Models/Member.php`

Add these relationships:

```php
public function savingsAccounts()
{
    return $this->hasMany(MemberSavingsAccount::class);
}

public function productSubscriptions()
{
    return $this->hasMany(MemberProductSubscription::class);
}

public function guaranteedLoans()
{
    return $this->hasMany(LoanGuarantor::class, 'guarantor_member_id');
}

public function getTotalSavingsAttribute(): float
{
    return app(SavingsService::class)->getCumulativeSavings($this);
}
```

### Update `app/Models/Loan.php`

Add this relationship:

```php
public function guarantors()
{
    return $this->hasMany(LoanGuarantor::class);
}

public function hasSufficientGuarantors(): bool
{
    $result = app(GuarantorService::class)->validateGuarantors($this);
    return $result['valid'];
}
```

### Update `app/Models/LoanProduct.php`

Add this relationship:

```php
public function rules()
{
    return $this->hasMany(LoanProductRule::class);
}
```

---

## Step 6: Create Filament Resources (Week 2, Day 2-5)

### Create Resources

```bash
php artisan make:filament-resource SaccoProduct --generate
php artisan make:filament-resource MemberSavingsAccount --generate
php artisan make:filament-resource MemberProductSubscription --generate
php artisan make:filament-resource LoanGuarantor --generate
php artisan make:filament-resource LoanProductRule --generate
```

### Create Custom Pages

```bash
php artisan make:filament-page SavingsDeposit
php artisan make:filament-page SavingsWithdrawal
php artisan make:filament-page SubscriptionPayment
```

### Example: Savings Deposit Page

`app/Filament/Pages/SavingsDeposit.php`:

```php
<?php

namespace App\Filament\Pages;

use Filament\Forms;
use Filament\Pages\Page;
use App\Models\Member;
use App\Models\MemberSavingsAccount;
use App\Models\SaccoProduct;
use App\Services\SavingsService;
use Filament\Notifications\Notification;

class SavingsDeposit extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static string $view = 'filament.pages.savings-deposit';
    protected static ?string $navigationGroup = 'SACCO Operations';
    protected static ?int $navigationSort = 1;
    
    public ?array $data = [];
    
    public function mount(): void
    {
        $this->form->fill();
    }
    
    protected function getFormSchema(): array
    {
        return [
            Forms\Components\Select::make('member_id')
                ->label('Member')
                ->options(Member::query()->pluck('name', 'id'))
                ->searchable()
                ->required()
                ->reactive()
                ->afterStateUpdated(fn ($state) => $this->loadMemberSavingsAccounts($state)),
            
            Forms\Components\Select::make('savings_account_id')
                ->label('Savings Account')
                ->options(function (callable $get) {
                    $memberId = $get('member_id');
                    if (!$memberId) return [];
                    
                    return MemberSavingsAccount::where('member_id', $memberId)
                        ->with('product')
                        ->get()
                        ->pluck('product.name', 'id');
                })
                ->required(),
            
            Forms\Components\TextInput::make('amount')
                ->label('Deposit Amount')
                ->numeric()
                ->required()
                ->minValue(1),
            
            Forms\Components\Select::make('payment_method')
                ->label('Payment Method')
                ->options([
                    'cash' => 'Cash',
                    'bank_transfer' => 'Bank Transfer',
                    'mobile_money' => 'Mobile Money',
                ])
                ->required(),
            
            Forms\Components\TextInput::make('reference_number')
                ->label('Reference Number')
                ->maxLength(100),
            
            Forms\Components\Textarea::make('notes')
                ->label('Notes')
                ->rows(3),
        ];
    }
    
    public function submit(): void
    {
        $data = $this->form->getState();
        
        $savingsAccount = MemberSavingsAccount::find($data['savings_account_id']);
        $savingsService = app(SavingsService::class);
        
        try {
            $result = $savingsService->deposit(
                $savingsAccount,
                $data['amount'],
                $data['payment_method'],
                $data['reference_number'] ?? null,
                $data['notes'] ?? null
            );
            
            Notification::make()
                ->title('Deposit Successful')
                ->success()
                ->body("Amount: KES " . number_format($data['amount'], 2) . ". New Balance: KES " . number_format($result['new_balance'], 2))
                ->send();
            
            $this->form->fill();
            
        } catch (\Exception $e) {
            Notification::make()
                ->title('Deposit Failed')
                ->danger()
                ->body($e->getMessage())
                ->send();
        }
    }
}
```

Create the view file `resources/views/filament/pages/savings-deposit.blade.php`:

```blade
<x-filament-panels::page>
    <form wire:submit.prevent="submit">
        {{ $this->form }}
        
        <div class="mt-4">
            <x-filament::button type="submit">
                Record Deposit
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
```

---

## Step 7: Write Tests (Throughout Development)

### Create Test Files

```bash
php artisan make:test Services/SavingsServiceTest --unit
php artisan make:test Services/SubscriptionServiceTest --unit
php artisan make:test Services/LoanEligibilityServiceTest --unit
php artisan make:test Features/SavingsDepositTest
php artisan make:test Features/LoanEligibilityTest
```

### Example: SavingsService Test

```php
<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Member;
use App\Models\Group;
use App\Models\SaccoProduct;
use App\Models\SaccoProductType;
use App\Models\MemberSavingsAccount;
use App\Services\SavingsService;

class SavingsServiceTest extends TestCase
{
    use RefreshDatabase;
    
    protected SavingsService $service;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SavingsService::class);
    }
    
    public function test_deposit_creates_double_entry_transactions()
    {
        // Arrange
        $group = Group::factory()->create();
        $member = Member::factory()->create(['group_id' => $group->id]);
        $productType = SaccoProductType::create([
            'name' => 'Member Savings',
            'slug' => 'member-savings',
            'category' => 'savings',
        ]);
        $product = SaccoProduct::create([
            'product_type_id' => $productType->id,
            'name' => 'Main Savings',
            'code' => 'MAIN_SAVINGS',
            'is_active' => true,
        ]);
        $savingsAccount = MemberSavingsAccount::create([
            'member_id' => $member->id,
            'sacco_product_id' => $product->id,
            'account_number' => 'SAV-001',
            'opening_date' => now(),
            'status' => 'active',
        ]);
        
        // Act
        $result = $this->service->deposit($savingsAccount, 1000, 'cash');
        
        // Assert
        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['transactions']);
        $this->assertEquals(1000, $result['new_balance']);
        
        // Check double entry
        $transactions = $result['transactions'];
        $this->assertEquals('dr', $transactions[0]->dr_cr);
        $this->assertEquals('cr', $transactions[1]->dr_cr);
        $this->assertEquals($transactions[0]->amount, $transactions[1]->amount);
    }
    
    public function test_withdrawal_requires_sufficient_balance()
    {
        // Arrange
        $savingsAccount = $this->createSavingsAccountWithBalance(500);
        
        // Act & Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Insufficient balance');
        
        $this->service->withdraw($savingsAccount, 1000, 'cash');
    }
    
    public function test_balance_calculation_is_accurate()
    {
        // Arrange
        $savingsAccount = $this->createSavingsAccountWithBalance(0);
        
        // Act
        $this->service->deposit($savingsAccount, 1000, 'cash');
        $this->service->deposit($savingsAccount, 500, 'cash');
        $this->service->withdraw($savingsAccount, 300, 'cash');
        
        // Assert
        $balance = $this->service->getBalance($savingsAccount);
        $this->assertEquals(1200, $balance);
    }
    
    private function createSavingsAccountWithBalance(float $balance): MemberSavingsAccount
    {
        $group = Group::factory()->create();
        $member = Member::factory()->create(['group_id' => $group->id]);
        $productType = SaccoProductType::create([
            'name' => 'Member Savings',
            'slug' => 'member-savings',
            'category' => 'savings',
        ]);
        $product = SaccoProduct::create([
            'product_type_id' => $productType->id,
            'name' => 'Main Savings',
            'code' => 'MAIN_SAVINGS',
            'is_active' => true,
        ]);
        $savingsAccount = MemberSavingsAccount::create([
            'member_id' => $member->id,
            'sacco_product_id' => $product->id,
            'account_number' => 'SAV-' . $member->id,
            'opening_date' => now(),
            'status' => 'active',
        ]);
        
        if ($balance > 0) {
            $this->service->deposit($savingsAccount, $balance, 'cash');
        }
        
        return $savingsAccount;
    }
}
```

### Run Tests

```bash
php artisan test
```

---

## Step 8: Create Example Products (Week 3)

### Seed Example Products

Create `database/seeders/SaccoProductExamplesSeeder.php`:

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\{SaccoProduct, SaccoProductType, SaccoProductAttribute};

class SaccoProductExamplesSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Main Savings Account
        $savingsType = SaccoProductType::where('slug', 'member-savings')->first();
        $mainSavings = SaccoProduct::create([
            'product_type_id' => $savingsType->id,
            'name' => 'Member Main Savings',
            'code' => 'MAIN_SAVINGS',
            'description' => 'Primary savings account for all members',
            'is_active' => true,
            'is_mandatory' => true,
        ]);
        
        // 2. Risk Fund
        $subscriptionType = SaccoProductType::where('slug', 'subscription-product')->first();
        $riskFund = SaccoProduct::create([
            'product_type_id' => $subscriptionType->id,
            'name' => 'Risk Fund',
            'code' => 'RISK_FUND',
            'description' => 'Monthly risk fund contribution',
            'is_active' => true,
            'is_mandatory' => true,
        ]);
        
        // Assign attributes
        $riskFund->attributeValues()->create([
            'attribute_id' => SaccoProductAttribute::where('slug', 'payment_frequency')->first()->id,
            'value' => 'monthly',
        ]);
        $riskFund->attributeValues()->create([
            'attribute_id' => SaccoProductAttribute::where('slug', 'amount_per_cycle')->first()->id,
            'value' => '30',
        ]);
        $riskFund->attributeValues()->create([
            'attribute_id' => SaccoProductAttribute::where('slug', 'total_cycles')->first()->id,
            'value' => '12',
        ]);
        $riskFund->attributeValues()->create([
            'attribute_id' => SaccoProductAttribute::where('slug', 'max_total_amount')->first()->id,
            'value' => '360',
        ]);
        
        // 3. Registration Fee
        $feeType = SaccoProductType::where('slug', 'one-time-fee')->first();
        $regFee = SaccoProduct::create([
            'product_type_id' => $feeType->id,
            'name' => 'Registration Fee',
            'code' => 'REG_FEE',
            'description' => 'One-time registration fee',
            'is_active' => true,
            'is_mandatory' => true,
        ]);
        
        $this->command->info('Example products created successfully!');
    }
}
```

Run:
```bash
php artisan db:seed --class=SaccoProductExamplesSeeder
```

---

## Step 9: Testing Checklist

Before moving to next phase:

- [ ] All migrations run successfully
- [ ] All models have correct relationships
- [ ] Services instantiate without errors
- [ ] Unit tests pass
- [ ] Can create a SACCO product via Filament
- [ ] Can record a savings deposit via Filament
- [ ] Balance calculation is accurate
- [ ] Transactions are double-entry balanced

---

## Step 10: Next Phase Planning

Once foundation is complete:
1. Move to Phase 2 (Member Savings) - Create full UI
2. Then Phase 3 (Subscription Products)
3. Then Phase 4 (Dynamic Loan Rules)

---

## Common Issues & Solutions

### Issue: Foreign Key Constraint Error
**Solution:** Check migration order. Parent tables must be created before child tables.

### Issue: Class Not Found
**Solution:** Run `composer dump-autoload`

### Issue: Service Not Injecting
**Solution:** Check `SaccoServiceProvider` is registered in `bootstrap/providers.php`

### Issue: Filament Resource Not Showing
**Solution:** Clear cache: `php artisan filament:clear-cached-components`

### Issue: Transactions Not Balancing
**Solution:** Always create debit and credit in same DB transaction. Use `DB::transaction()`

---

## Useful Commands

```bash
# Clear all caches
php artisan optimize:clear

# Recreate database
php artisan migrate:fresh --seed

# Run specific test
php artisan test --filter=SavingsServiceTest

# Generate IDE helper (for autocomplete)
php artisan ide-helper:models

# Check code style
./vendor/bin/pint

# Run static analysis
./vendor/bin/phpstan analyse
```

---

## Resources

- **Technical Report:** `/docs/SACCO_System_Extension_Technical_Report.md`
- **Executive Summary:** `/docs/SACCO_Extension_Executive_Summary.md`
- **Laravel Docs:** https://laravel.com/docs
- **Filament Docs:** https://filamentphp.com/docs

---

## Getting Help

1. Review technical report for detailed specifications
2. Check existing loan system patterns (great reference!)
3. Ask team members
4. Check Laravel/Filament documentation

---

**Happy Coding! 🚀**

