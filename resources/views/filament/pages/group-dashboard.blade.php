<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Group Selector --}}
        <div class="bg-white rounded-lg shadow p-6">
            {{ $this->form }}
        </div>

        @if($selectedGroup)
            {{-- Financial Summary Cards --}}
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                {{-- Total Assets --}}
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Total Assets</p>
                            <p class="text-2xl font-bold text-green-600">
                                KES {{ number_format($financialSummary['total_assets'] ?? 0, 2) }}
                            </p>
                        </div>
                        <div class="p-3 bg-green-100 rounded-full">
                            <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                    </div>
                </div>

                {{-- Total Liabilities --}}
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Total Liabilities</p>
                            <p class="text-2xl font-bold text-red-600">
                                KES {{ number_format($financialSummary['total_liabilities'] ?? 0, 2) }}
                            </p>
                        </div>
                        <div class="p-3 bg-red-100 rounded-full">
                            <svg class="w-8 h-8 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>
                            </svg>
                        </div>
                    </div>
                </div>

                {{-- Net Income --}}
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Net Income</p>
                            <p class="text-2xl font-bold text-blue-600">
                                KES {{ number_format($financialSummary['net_income'] ?? 0, 2) }}
                            </p>
                        </div>
                        <div class="p-3 bg-blue-100 rounded-full">
                            <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                            </svg>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Additional Metrics --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                {{-- Revenue vs Expenses --}}
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold mb-4">Income Statement</h3>
                    <div class="space-y-3">
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">Total Revenue</span>
                            <span class="font-semibold text-green-600">
                                KES {{ number_format($financialSummary['total_revenue'] ?? 0, 2) }}
                            </span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">Total Expenses</span>
                            <span class="font-semibold text-red-600">
                                KES {{ number_format($financialSummary['total_expenses'] ?? 0, 2) }}
                            </span>
                        </div>
                        <div class="border-t pt-3 flex justify-between items-center">
                            <span class="font-semibold text-gray-700">Net Income</span>
                            <span class="font-bold text-blue-600">
                                KES {{ number_format($financialSummary['net_income'] ?? 0, 2) }}
                            </span>
                        </div>
                    </div>
                </div>

                {{-- Balance Sheet --}}
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold mb-4">Balance Sheet</h3>
                    <div class="space-y-3">
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">Total Assets</span>
                            <span class="font-semibold text-green-600">
                                KES {{ number_format($financialSummary['total_assets'] ?? 0, 2) }}
                            </span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">Total Liabilities</span>
                            <span class="font-semibold text-red-600">
                                KES {{ number_format($financialSummary['total_liabilities'] ?? 0, 2) }}
                            </span>
                        </div>
                        <div class="border-t pt-3 flex justify-between items-center">
                            <span class="font-semibold text-gray-700">Equity</span>
                            <span class="font-bold text-blue-600">
                                KES {{ number_format($financialSummary['equity_balance'] ?? 0, 2) }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Group Information --}}
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-semibold mb-4">Group Information</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <p class="text-sm text-gray-600">Group Name</p>
                        <p class="font-semibold">{{ $selectedGroup->name }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Total Members</p>
                        <p class="font-semibold">{{ $selectedGroup->members()->count() }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Formation Date</p>
                        <p class="font-semibold">{{ $selectedGroup->formation_date?->format('M d, Y') ?? 'N/A' }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Capital Advanced</p>
                        <p class="font-semibold text-blue-600">
                            KES {{ number_format($selectedGroup->total_capital_advanced, 2) }}
                        </p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Capital Returned</p>
                        <p class="font-semibold text-green-600">
                            KES {{ number_format($selectedGroup->total_capital_returned, 2) }}
                        </p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Net Capital Outstanding</p>
                        <p class="font-semibold text-purple-600">
                            KES {{ number_format($selectedGroup->net_capital_outstanding, 2) }}
                        </p>
                    </div>
                </div>
            </div>

            {{-- Group Accounts Table --}}
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-semibold mb-4">Group Accounts</h3>
                {{ $this->table }}
            </div>
        @else
            <div class="bg-white rounded-lg shadow p-6 text-center text-gray-500">
                No groups available. Please create a group first.
            </div>
        @endif
    </div>
</x-filament-panels::page>

