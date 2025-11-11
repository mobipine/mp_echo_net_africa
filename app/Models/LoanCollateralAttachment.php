<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanCollateralAttachment extends Model
{
    protected $fillable = [
        'loan_id',
        'document_type',
        'file_path',
    ];

    /**
     * Get the loan this collateral attachment belongs to
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /**
     * Get the document type (DocsMeta)
     */
    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocsMeta::class, 'document_type');
    }
}
