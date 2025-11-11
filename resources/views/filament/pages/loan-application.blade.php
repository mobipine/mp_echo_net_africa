<x-filament-panels::page>
    <div class="space-y-6" x-data="{ showModal: @entangle('showKycModal') }">
        @if($this->showKycModal)
            <div
                class="fixed inset-0 z-50 overflow-y-auto"
                x-show="showModal"
                x-transition:enter="ease-out duration-300"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="ease-in duration-200"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                style="display: none;"
                x-cloak
            >
                <div class="flex min-h-screen items-center justify-center p-4">
                    <!-- Backdrop -->
                    <div
                        class="fixed inset-0 bg-gray-900 bg-opacity-50 transition-opacity"
                        x-show="showModal"
                        x-transition:enter="ease-out duration-300"
                        x-transition:enter-start="opacity-0"
                        x-transition:enter-end="opacity-100"
                        x-transition:leave="ease-in duration-200"
                        x-transition:leave-start="opacity-100"
                        x-transition:leave-end="opacity-0"
                    ></div>

                    <!-- Modal -->
                    <div
                        class="relative z-50 w-full max-w-md transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 shadow-xl transition-all"
                        x-show="showModal"
                        x-transition:enter="ease-out duration-300"
                        x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave="ease-in duration-200"
                        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                    >

                        <!-- Modal Body -->
                        <div class="px-6 py-4">
                            <div class="mb-2 flex flex-col gap-3">
                                <p class="text-sm text-gray-700 dark:text-white mb-3">
                                    This member has not uploaded all required KYC documents. The loan application process cannot proceed until all required documents are uploaded.
                                </p>

                                <div class="bg-danger-50 dark:bg-danger-900/20 border border-danger-200 dark:border-danger-800 rounded-lg p-4 mb-4">
                                    <p class="text-sm font-semibold text-danger-800 dark:text-danger-200 mb-2">
                                        Missing Documents:
                                    </p>
                                    <ul class="list-disc list-inside space-y-1">
                                        @foreach($this->missingKycDocs as $docName)
                                            <li class="text-sm text-danger-700 dark:text-danger-300">{{ $docName }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <!-- Modal Footer -->
                        <div class="bg-gray-50 dark:bg-gray-700 px-6 py-4 flex justify-between gap-3">
                            <button
                                type="button"
                                onclick="window.location.reload()"
                                class="inline-flex items-center px-4 py-2 bg-gray-600 hover:bg-gray-700 text-red font-medium rounded-lg transition-colors"
                            >
                                Close
                            </button>
                            <a
                                href="{{ $this->kycMemberId ? route('filament.admin.resources.members.edit', ['record' => $this->kycMemberId, 'activeRelationManager' => 1]) : '#' }}"
                                target="_blank"
                                class="inline-flex items-center px-4 py-2 bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-lg transition-colors"
                            >
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                </svg>
                                Go to KYC Upload
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <!-- Disable overlay when modal is shown -->
        @if($this->showKycModal)
            <div class="fixed inset-0 z-40 bg-gray-900 bg-opacity-50 pointer-events-auto"></div>
        @endif

        <div class="{{ $this->showKycModal ? 'pointer-events-none opacity-50' : '' }}">
            {{ $this->form }}
        </div>
    </div>
</x-filament-panels::page>
