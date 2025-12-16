<?php

namespace App\Http\Controllers;

use App\Models\UssdFlow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class UssdFlowController extends Controller
{
    /**
     * Get all USSD flows
     */
    public function index(Request $request)
    {
        $query = UssdFlow::query();

        // Filter by flow type if provided
        if ($request->has('flow_type')) {
            $query->where('flow_type', $request->flow_type);
        }

        // Filter by active status if provided
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $flows = $query->orderBy('created_at', 'desc')->get();

        return response()->json($flows);
    }

    /**
     * Get a specific USSD flow
     */
    public function show($id)
    {
        $flow = UssdFlow::findOrFail($id);
        return response()->json($flow);
    }

    /**
     * Create a new USSD flow
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'flow_type' => 'required|in:loan_repayment,member_search,custom',
            'flow_definition' => 'required|array',
            'flow_definition.nodes' => 'required|array',
            'flow_definition.edges' => 'required|array',
            'is_active' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // If this is set as active, deactivate other flows of the same type
        if ($request->boolean('is_active', false)) {
            UssdFlow::where('flow_type', $request->flow_type)
                ->where('is_active', true)
                ->update(['is_active' => false]);
        }

        $flow = UssdFlow::create([
            'name' => $request->name,
            'description' => $request->description,
            'flow_type' => $request->flow_type,
            'flow_definition' => $request->flow_definition,
            'is_active' => $request->boolean('is_active', false)
        ]);

        Log::info('USSD Flow created', ['flow_id' => $flow->id, 'name' => $flow->name]);

        return response()->json([
            'message' => 'USSD flow created successfully',
            'data' => $flow
        ], 201);
    }

    /**
     * Update an existing USSD flow
     */
    public function update(Request $request, $id)
    {
        $flow = UssdFlow::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'flow_type' => 'sometimes|required|in:loan_repayment,member_search,custom',
            'flow_definition' => 'sometimes|required|array',
            'flow_definition.nodes' => 'required_with:flow_definition|array',
            'flow_definition.edges' => 'required_with:flow_definition|array',
            'is_active' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // If this is set as active, deactivate other flows of the same type
        if ($request->has('is_active') && $request->boolean('is_active')) {
            UssdFlow::where('flow_type', $request->input('flow_type', $flow->flow_type))
                ->where('id', '!=', $id)
                ->where('is_active', true)
                ->update(['is_active' => false]);
        }

        $flow->update($request->only([
            'name',
            'description',
            'flow_type',
            'flow_definition',
            'is_active'
        ]));

        Log::info('USSD Flow updated', ['flow_id' => $flow->id, 'name' => $flow->name]);

        return response()->json([
            'message' => 'USSD flow updated successfully',
            'data' => $flow
        ]);
    }

    /**
     * Delete a USSD flow
     */
    public function destroy($id)
    {
        $flow = UssdFlow::findOrFail($id);
        $flow->delete();

        Log::info('USSD Flow deleted', ['flow_id' => $id]);

        return response()->json([
            'message' => 'USSD flow deleted successfully'
        ]);
    }

    /**
     * Activate a USSD flow (deactivates others of the same type)
     */
    public function activate($id)
    {
        $flow = UssdFlow::findOrFail($id);

        // Deactivate other flows of the same type
        UssdFlow::where('flow_type', $flow->flow_type)
            ->where('id', '!=', $id)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        // Activate this flow
        $flow->update(['is_active' => true]);

        Log::info('USSD Flow activated', ['flow_id' => $id, 'flow_type' => $flow->flow_type]);

        return response()->json([
            'message' => 'USSD flow activated successfully',
            'data' => $flow
        ]);
    }

    /**
     * Get flow definition (nodes and edges)
     */
    public function getFlow($id)
    {
        $flow = UssdFlow::findOrFail($id);
        return response()->json($flow->flow_definition);
    }

    /**
     * Save flow definition (nodes and edges)
     */
    public function saveFlow(Request $request, $id)
    {
        $flow = UssdFlow::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'nodes' => 'required|array',
            'edges' => 'required|array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $flow->flow_definition = [
            'nodes' => $request->nodes,
            'edges' => $request->edges
        ];
        $flow->save();

        Log::info('USSD Flow definition saved', ['flow_id' => $id]);

        return response()->json([
            'message' => 'Flow definition saved successfully',
            'data' => $flow->flow_definition
        ]);
    }
}
