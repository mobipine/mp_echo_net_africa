<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UssdFlow extends Model
{
    protected $fillable = [
        'name',
        'description',
        'flow_type',
        'flow_definition',
        'is_active'
    ];

    protected $casts = [
        'flow_definition' => 'array',
        'is_active' => 'boolean'
    ];

    /**
     * Get all sessions for this flow
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(UssdSession::class, 'ussd_flow_id');
    }

    /**
     * Get the active flow for a specific type
     */
    public static function getActiveFlow(string $flowType = 'loan_repayment'): ?self
    {
        return self::where('flow_type', $flowType)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Find a node in the flow definition by ID
     */
    public function findNode(string $nodeId): ?array
    {
        $nodes = $this->flow_definition['nodes'] ?? [];

        // dd($nodes);

        foreach ($nodes as $node) {
            if ($node['id'] === $nodeId) {
                return $node;
            }
        }

        return null;
    }

    /**
     * Find the Start node in the flow
     */
    public function findStartNode(): ?array
    {
        $nodes = $this->flow_definition['nodes'] ?? [];

        foreach ($nodes as $node) {
            if (($node['type'] ?? '') === 'ussd-start') {
                return $node;
            }
        }

        return null;
    }

    /**
     * Get the next node based on current node and user input
     */
    public function getNextNode(string $currentNodeId, ?string $userInput = null): ?array
    {
        $edges = $this->flow_definition['edges'] ?? [];
        $nodes = $this->flow_definition['nodes'] ?? [];

        // Find edges that start from current node
        $outgoingEdges = array_filter($edges, function($edge) use ($currentNodeId) {
            return $edge['source'] === $currentNodeId;
        });

        // dd($outgoingEdges);

        if (empty($outgoingEdges)) {
            return null;
        }

        // If user input provided, try to match it with edge labels
        if ($userInput !== null) {
            foreach ($outgoingEdges as $edge) {
                if (isset($edge['label']) && $edge['label'] === $userInput) {
                    $targetNodeId = $edge['target'];
                    return $this->findNode($targetNodeId);
                }
            }
        }

        // Default: return first connected node
        $firstEdge = reset($outgoingEdges);
        $targetNodeId = $firstEdge['target'];
        return $this->findNode($targetNodeId);
    }
}
