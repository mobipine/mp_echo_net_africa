<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditReportExport extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'uuid',
        'user_id',
        'status',
        'filters',
        'disk',
        'file_path',
        'file_name',
        'row_count',
        'file_size',
        'started_at',
        'completed_at',
        'failed_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'row_count' => 'integer',
            'file_size' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDownloadable(): bool
    {
        return $this->status === self::STATUS_COMPLETED
            && filled($this->file_path);
    }
}
