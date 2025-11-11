<?php

return [
    /**
     * Configure how guarantors are selected for loans
     *
     * Options:
     * - 'all_members': All group members must guarantee the loan
     * - 'selectable': Admin can select specific members to guarantee
     */
    // 'selection_mode' => env('GUARANTOR_SELECTION_MODE', 'selectable'),
    'selection_mode' => env('GUARANTOR_SELECTION_MODE', 'all_members'),

    /**
     * Whether guarantors are required by default
     */
    'required' => env('GUARANTORS_REQUIRED', true),

];

