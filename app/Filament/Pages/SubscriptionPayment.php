<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use App\Models\{Member, SaccoProduct, MemberProductSubscription};
use App\Services\SubscriptionPaymentService;

class SubscriptionPayment extends Page implements HasForms
{
    use InteractsWithForms;
    
    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';
    
    protected static ?string $navigationGroup = 'SACCO Management';
    
    protected static ?int $navigationSort = 7;
    
    protected static ?string $navigationLabel = 'Subscription Payment';
    
    protected static string $view = 'filament.pages.subscription-payment';
    
    public ?array $data = [];
    
    public function mount(): void
    {
        $this->form->fill();
    }
    
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Subscription Payment Information')
                    ->description('Record a subscription payment for a member')
                    ->schema([
                        Forms\Components\Select::make('member_id')
                            ->label('Select Member')
                            ->options(Member::query()->pluck('name', 'id'))
                            ->required()
                            ->searchable()
                            ->reactive()
                            ->afterStateUpdated(function ($state, callable $set) {
                                $set('subscription_id', null);
                                $set('sacco_product_id', null);
                            })
                            ->helperText('Select the member making the payment'),
                        
                        Forms\Components\Select::make('subscription_id')
                            ->label('Existing Subscription')
                            ->options(function (callable $get) {
                                $memberId = $get('member_id');
                                if (!$memberId) {
                                    return [];
                                }
                                
                                $subscriptions = MemberProductSubscription::where('member_id', $memberId)
                                    ->whereIn('status', ['active', 'suspended'])
                                    ->with('saccoProduct')
                                    ->get();
                                
                                if ($subscriptions->isEmpty()) {
                                    return ['create_new' => '➕ Create New Subscription'];
                                }
                                
                                $options = $subscriptions->mapWithKeys(function ($sub) {
                                    $progress = $sub->total_expected 
                                        ? round(($sub->total_paid / $sub->total_expected) * 100, 1) 
                                        : 0;
                                    return [
                                        $sub->id => "{$sub->saccoProduct->name} - Paid: KES " . number_format($sub->total_paid, 2) . " ({$progress}%)"
                                    ];
                                })->toArray();
                                
                                $options['create_new'] = '➕ Create New Subscription';
                                
                                return $options;
                            })
                            ->required()
                            ->searchable()
                            ->reactive()
                            ->helperText('Select existing subscription or create new one'),
                        
                        Forms\Components\Select::make('sacco_product_id')
                            ->label('Subscription Product')
                            ->options(function () {
                                return SaccoProduct::active()
                                    ->whereHas('productType', function ($query) {
                                        $query->where('category', 'subscription');
                                    })
                                    ->pluck('name', 'id');
                            })
                            ->required()
                            ->searchable()
                            ->reactive()
                            ->visible(fn (callable $get) => $get('subscription_id') === 'create_new')
                            ->helperText('Select subscription product'),
                        
                        Forms\Components\Placeholder::make('subscription_info')
                            ->label('Subscription Details')
                            ->content(function (callable $get) {
                                $subscriptionId = $get('subscription_id');
                                if (!$subscriptionId || $subscriptionId === 'create_new') {
                                    return 'Select a subscription to see details';
                                }
                                
                                $subscription = MemberProductSubscription::find($subscriptionId);
                                if (!$subscription) {
                                    return 'Subscription not found';
                                }
                                
                                $service = app(SubscriptionPaymentService::class);
                                $expectedAmount = $service->getExpectedAmount($subscription);
                                
                                return new \Illuminate\Support\HtmlString("
                                    <div class='space-y-2'>
                                        <div><strong>Product:</strong> {$subscription->saccoProduct->name}</div>
                                        <div><strong>Total Paid:</strong> <span class='text-success-600 font-bold'>KES " . number_format($subscription->total_paid, 2) . "</span></div>
                                        <div><strong>Total Expected:</strong> KES " . number_format($subscription->total_expected ?? 0, 2) . "</div>
                                        <div><strong>Outstanding:</strong> <span class='text-warning-600 font-bold'>KES " . number_format($subscription->outstanding_amount, 2) . "</span></div>
                                        <div><strong>Payments Made:</strong> {$subscription->payment_count}</div>
                                        <div><strong>Expected Amount:</strong> KES " . number_format($expectedAmount, 2) . "</div>
                                        <div><strong>Next Payment Due:</strong> " . ($subscription->next_payment_date ? $subscription->next_payment_date->format('d M Y') : 'N/A') . "</div>
                                    </div>
                                ");
                            })
                            ->visible(fn (callable $get) => $get('subscription_id') && $get('subscription_id') !== 'create_new'),
                        
                        Forms\Components\TextInput::make('amount')
                            ->label('Payment Amount')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->prefix('KES')
                            ->placeholder('300.00')
                            ->reactive()
                            ->afterStateUpdated(function ($state, callable $get, callable $set) {
                                // Auto-fill with expected amount
                                if (!$state) {
                                    $subscriptionId = $get('subscription_id');
                                    if ($subscriptionId && $subscriptionId !== 'create_new') {
                                        $subscription = MemberProductSubscription::find($subscriptionId);
                                        if ($subscription) {
                                            $service = app(SubscriptionPaymentService::class);
                                            $set('amount', $service->getExpectedAmount($subscription));
                                        }
                                    }
                                }
                            })
                            ->helperText('Enter the amount being paid'),
                        
                        Forms\Components\Select::make('payment_method')
                            ->label('Payment Method')
                            ->options([
                                'cash' => 'Cash',
                                'bank_transfer' => 'Bank Transfer',
                                'mobile_money' => 'Mobile Money (M-PESA/Airtel)',
                                'cheque' => 'Cheque',
                                'standing_order' => 'Standing Order',
                            ])
                            ->required()
                            ->default('cash'),
                        
                        Forms\Components\TextInput::make('reference_number')
                            ->label('Reference Number')
                            ->maxLength(100)
                            ->placeholder('e.g., MPESA-ABC123')
                            ->helperText('Optional: Transaction reference'),
                        
                        Forms\Components\Textarea::make('notes')
                            ->label('Notes')
                            ->rows(3)
                            ->placeholder('Optional notes about this payment')
                            ->columnSpanFull(),
                    ])->columns(2),
            ])
            ->statePath('data');
    }
    
    public function submit(): void
    {
        $data = $this->form->getState();
        
        try {
            $service = app(SubscriptionPaymentService::class);
            $member = Member::findOrFail($data['member_id']);
            
            // Handle subscription creation if needed
            if ($data['subscription_id'] === 'create_new') {
                if (empty($data['sacco_product_id'])) {
                    Notification::make()
                        ->title('Error')
                        ->danger()
                        ->body('Please select a subscription product.')
                        ->send();
                    return;
                }
                
                $product = SaccoProduct::findOrFail($data['sacco_product_id']);
                $subscription = $service->getOrCreateSubscription($member, $product);
                
                Notification::make()
                    ->title('Subscription Created')
                    ->success()
                    ->body("New subscription for {$product->name} created successfully.")
                    ->send();
            } else {
                $subscription = MemberProductSubscription::findOrFail($data['subscription_id']);
            }
            
            // Record payment
            $result = $service->recordPayment(
                $subscription,
                $data['amount'],
                $data['payment_method'],
                $data['reference_number'] ?? null,
                $data['notes'] ?? null
            );
            
            $message = "Payment of KES " . number_format($data['amount'], 2) . " recorded successfully.";
            if ($result['subscription']->status === 'completed') {
                $message .= " Subscription completed!";
            } else {
                $message .= " Outstanding: KES " . number_format($result['outstanding'], 2);
            }
            
            Notification::make()
                ->title('Payment Successful')
                ->success()
                ->body($message)
                ->send();
            
            // Reset form
            $this->form->fill();
            
        } catch (\Exception $e) {
            Notification::make()
                ->title('Payment Failed')
                ->danger()
                ->body($e->getMessage())
                ->send();
        }
    }
}

