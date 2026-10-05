<?php

namespace App\Events;

use App\Models\CreditReportExport;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CreditReportCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly CreditReportExport $export)
    {
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('App.Models.User.'.$this->export->user_id),
            new PrivateChannel('reports.'.$this->export->user_id),
        ];
    }

    /**
     * Broadcast only the lightweight payload needed by the UI.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->export->id,
            'uuid' => $this->export->uuid,
            'status' => $this->export->status,
            'row_count' => $this->export->row_count,
            'file_size' => $this->export->file_size,
            'file_name' => $this->export->file_name,
            'is_consolidated' => ($this->export->filters['report_mode'] ?? null) === 'consolidated',
        ];
    }

    /**
     * The event name clients will listen for.
     */
    public function broadcastAs(): string
    {
        return 'report.completed';
    }
}
