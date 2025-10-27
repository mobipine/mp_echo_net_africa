<?php

namespace App\Services;

use App\Models\ChartofAccounts;
use App\Models\Group;
use App\Models\OrganizationGroupCapitalTransfer;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class CapitalTransferService
{
    protected GroupTransactionService $groupTransactionService;
    
    public function __construct(GroupTransactionService $groupTransactionService)
    {
        $this->groupTransactionService = $groupTransactionService;
    }
    
    /**
     * Transfer capital from organization to group
     */
    public function advanceCapitalToGroup(
        Group $group,
        float $amount,
        string $purpose,
        ?int $approvedBy = null,
        ?string $referenceNumber = null
    ): OrganizationGroupCapitalTransfer {
        return DB::transaction(function () use ($group, $amount, $purpose, $approvedBy, $referenceNumber) {
            // Create transfer record
            $transfer = OrganizationGroupCapitalTransfer::create([
                'group_id' => $group->id,
                'transfer_type' => 'advance',
                'amount' => $amount,
                'transfer_date' => now(),
                'reference_number' => $referenceNumber ?? 'ADV-' . time(),
                'purpose' => $purpose,
                'approved_by' => $approvedBy ?? auth()->id(),
                'created_by' => auth()->id(),
                'status' => 'completed',
            ]);
            
            // Get organization accounts
            $orgCapitalAdvancesAccount = ChartofAccounts::where('account_code', '1201')->first();
            $orgBankAccount = ChartofAccounts::where('account_code', '1001')->first();
            
            if (!$orgCapitalAdvancesAccount || !$orgBankAccount) {
                throw new \Exception('Organization accounts not found. Please seed chart of accounts first.');
            }
            
            // Organization-level transactions
            // Dr: Capital Advances to Groups (Asset) - increases asset
            Transaction::create([
                'account_name' => $orgCapitalAdvancesAccount->name,
                'account_number' => $orgCapitalAdvancesAccount->account_code,
                'group_id' => $group->id,
                'transaction_type' => 'capital_advance',
                'dr_cr' => 'dr',
                'amount' => $amount,
                'transaction_date' => now(),
                'description' => "Capital advance to {$group->name}: {$purpose}",
                'metadata' => ['transfer_id' => $transfer->id],
            ]);
            
            // Cr: Organization Bank Account (Asset) - decreases asset
            Transaction::create([
                'account_name' => $orgBankAccount->name,
                'account_number' => $orgBankAccount->account_code,
                'group_id' => $group->id,
                'transaction_type' => 'capital_advance',
                'dr_cr' => 'cr',
                'amount' => $amount,
                'transaction_date' => now(),
                'description' => "Capital advance to {$group->name}: {$purpose}",
                'metadata' => ['transfer_id' => $transfer->id],
            ]);
            
            // Group-level transactions
            // Dr: Group Bank Account (Asset) - increases group's cash
            // Cr: Group Capital Payable (Liability) - increases liability to org
            $this->groupTransactionService->createGroupTransaction(
                group: $group,
                debitAccountType: 'bank',
                creditAccountType: 'capital_payable',
                amount: $amount,
                transactionType: 'capital_received',
                references: [],
                description: "Capital received from organization: {$purpose}",
                metadata: ['transfer_id' => $transfer->id]
            );
            
            return $transfer;
        });
    }
    
    /**
     * Return capital from group to organization
     */
    public function returnCapitalToOrganization(
        Group $group,
        float $amount,
        ?int $initiatedBy = null,
        ?string $notes = null
    ): OrganizationGroupCapitalTransfer {
        // Check if group has sufficient funds
        $groupBankAccount = $this->groupTransactionService->getGroupAccount($group, 'bank');
        $availableBalance = $this->groupTransactionService->getGroupAccountBalance($groupBankAccount);
        
        if ($availableBalance < $amount) {
            throw new \Exception("Insufficient funds in group account. Available: {$availableBalance}, Requested: {$amount}");
        }
        
        return DB::transaction(function () use ($group, $amount, $initiatedBy, $notes) {
            // Create transfer record
            $transfer = OrganizationGroupCapitalTransfer::create([
                'group_id' => $group->id,
                'transfer_type' => 'return',
                'amount' => $amount,
                'transfer_date' => now(),
                'purpose' => 'Capital return to organization',
                'reference_number' => 'RET-' . time(),
                'created_by' => $initiatedBy ?? auth()->id(),
                'approved_by' => auth()->id(),
                'notes' => $notes,
                'status' => 'completed',
            ]);
            
            // Group-level transactions
            // Dr: Group Capital Payable (Liability) - decreases liability
            // Cr: Group Bank Account (Asset) - decreases cash
            $this->groupTransactionService->createGroupTransaction(
                group: $group,
                debitAccountType: 'capital_payable',
                creditAccountType: 'bank',
                amount: $amount,
                transactionType: 'capital_returned',
                references: [],
                description: "Capital returned to organization",
                metadata: ['transfer_id' => $transfer->id]
            );
            
            // Organization-level transactions
            $orgBankAccount = ChartofAccounts::where('account_code', '1001')->first();
            $orgCapitalAdvancesAccount = ChartofAccounts::where('account_code', '1201')->first();
            
            // Dr: Organization Bank Account (Asset) - increases cash
            Transaction::create([
                'account_name' => $orgBankAccount->name,
                'account_number' => $orgBankAccount->account_code,
                'group_id' => $group->id,
                'transaction_type' => 'capital_return',
                'dr_cr' => 'dr',
                'amount' => $amount,
                'transaction_date' => now(),
                'description' => "Capital returned from {$group->name}",
                'metadata' => ['transfer_id' => $transfer->id],
            ]);
            
            // Cr: Capital Advances to Groups (Asset) - decreases receivable
            Transaction::create([
                'account_name' => $orgCapitalAdvancesAccount->name,
                'account_number' => $orgCapitalAdvancesAccount->account_code,
                'group_id' => $group->id,
                'transaction_type' => 'capital_return',
                'dr_cr' => 'cr',
                'amount' => $amount,
                'transaction_date' => now(),
                'description' => "Capital returned from {$group->name}",
                'metadata' => ['transfer_id' => $transfer->id],
            ]);
            
            return $transfer;
        });
    }

    /**
     * Get capital transfer summary for a group
     */
    public function getCapitalTransferSummary(Group $group): array
    {
        $totalAdvanced = OrganizationGroupCapitalTransfer::where('group_id', $group->id)
            ->where('transfer_type', 'advance')
            ->where('status', 'completed')
            ->sum('amount');
        
        $totalReturned = OrganizationGroupCapitalTransfer::where('group_id', $group->id)
            ->where('transfer_type', 'return')
            ->where('status', 'completed')
            ->sum('amount');
        
        return [
            'total_advanced' => $totalAdvanced,
            'total_returned' => $totalReturned,
            'net_outstanding' => $totalAdvanced - $totalReturned,
        ];
    }

    /**
     * Get all capital transfers for a group
     */
    public function getGroupCapitalTransfers(Group $group, ?string $transferType = null)
    {
        $query = OrganizationGroupCapitalTransfer::where('group_id', $group->id)
            ->with(['approver', 'creator'])
            ->orderBy('transfer_date', 'desc');
        
        if ($transferType) {
            $query->where('transfer_type', $transferType);
        }
        
        return $query->get();
    }
}

