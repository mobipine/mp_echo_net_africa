<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class UssdSession extends Model
{
    protected $fillable = [
        'session_id',
        'phone_number',
        'ussd_flow_id',
        'current_node_id',
        'session_data',
        'authenticated_user_id',
        'group_id',
        'status',
        'expires_at'
    ];

    protected $casts = [
        'session_data' => 'array',
        'expires_at' => 'datetime'
    ];

    /**
     * Get the flow associated with this session
     */
    public function flow(): BelongsTo
    {
        return $this->belongsTo(UssdFlow::class, 'ussd_flow_id');
    }

    /**
     * Get the authenticated user (official)
     */
    public function authenticatedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authenticated_user_id');
    }

    /**
     * Get the group associated with this session
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * Get a value from session data
     */
    public function getData(string $key, $default = null)
    {
        return data_get($this->session_data, $key, $default);
    }

    /**
     * Set a value in session data
     */
    public function setData(string $key, $value): void
    {
        $data = $this->session_data ?? [];
        data_set($data, $key, $value);
        $this->session_data = $data;
        $this->save();
    }

    /**
     * Add node to navigation history
     */
    public function addToHistory(string $nodeId): void
    {
        $history = $this->getData('navigation_history', []);
        // Don't add if it's the same as the last node
        if (empty($history) || end($history) !== $nodeId) {
            $history[] = $nodeId;
            // Limit history to 20 nodes
            if (count($history) > 20) {
                $history = array_slice($history, -20);
            }
            $this->setData('navigation_history', $history);
        }
    }

    /**
     * Get previous node from navigation history
     */
    public function getPreviousNode(): ?string
    {
        $history = $this->getData('navigation_history', []);
        if (count($history) < 2) {
            return null;
        }
        // Remove current node and return previous
        array_pop($history);
        return end($history);
    }

    /**
     * Clear navigation history
     */
    public function clearHistory(): void
    {
        $this->setData('navigation_history', []);
    }

    /**
     * Check if session is expired
     */
    public function isExpired(): bool
    {
        if (!$this->expires_at) {
            return false;
        }

        return Carbon::now()->greaterThan($this->expires_at);
    }

    /**
     * Extend session expiration
     */
    public function extendExpiration(int $minutes = 5): void
    {
        $this->expires_at = Carbon::now()->addMinutes($minutes);
        $this->save();
    }

    /**
     * Mark session as completed
     */
    public function complete(): void
    {
        $this->status = 'completed';
        $this->save();
    }

    /**
     * Mark session as expired
     */
    public function expire(): void
    {
        $this->status = 'expired';
        $this->save();
    }

    /**
     * Mark session as cancelled
     */
    public function cancel(): void
    {
        $this->status = 'cancelled';
        $this->save();
    }

    /**
     * Find or create a session by session ID
     */
    public static function findOrCreateBySessionId(
        string $sessionId,
        string $phoneNumber,
        ?int $flowId = null
    ): self {
        $session = self::where('session_id', $sessionId)->first();

        if (!$session) {
            // Find the actual Start node ID from the flow
            $startNodeId = null;
            if ($flowId) {
                $flow = \App\Models\UssdFlow::find($flowId);
                if ($flow) {
                    $startNode = $flow->findStartNode();
                    if ($startNode) {
                        $startNodeId = $startNode['id'];
                    }
                }
            }

            $session = self::create([
                'session_id' => $sessionId,
                'phone_number' => $phoneNumber,
                'ussd_flow_id' => $flowId,
                'current_node_id' => $startNodeId ?? 'start', // Use actual Start node ID or fallback
                'session_data' => [],
                'status' => 'active',
                'expires_at' => Carbon::now()->addMinutes(5)
            ]);
        } else {
            // Extend expiration on each request
            $session->extendExpiration();

            // If current_node_id is 'start' (old format), update it to actual Start node ID
            if ($session->current_node_id === 'start' && $session->ussd_flow_id) {
                $flow = $session->flow;
                if ($flow) {
                    $startNode = $flow->findStartNode();
                    if ($startNode) {
                        $session->current_node_id = $startNode['id'];
                        $session->save();
                    }
                }
            }
        }

        return $session;
    }

    /**
     * Clean up expired sessions
     */
    public static function cleanupExpired(): int
    {
        return self::where('status', 'active')
            ->where('expires_at', '<', Carbon::now())
            ->update(['status' => 'expired']);
    }
}
