<?php

namespace App\Http\Controllers;

use App\Models\UssdFlow;
use App\Models\UssdSession;
use App\Models\Official;
use App\Models\Member;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UssdWebHookController extends Controller
{
    /**
     * Handle incoming USSD webhook requests
     *
     * Expected request parameters:
     * - session_id: Unique session identifier from USSD gateway
     * - phone_number: Phone number initiating the USSD session
     * - user_input: User's input (can be empty for first request)
     * - service_code: USSD service code (optional)
     */
    public function handleUssdWebhook(Request $request)
    {
        try {
        Log::info('USSD Webhook Received:', $request->all());

            $sessionId = $request->input('session_id');
            // $phoneNumber = $request->input('phone_number');
            $phoneNumber = $request->input('mobile_number');
            // $userInput = $request->input('user_input', '');
            $userInput = $request->input('message');
            $serviceCode = $request->input('service_code');

            // Validate required parameters
            if (!$sessionId || !$phoneNumber) {
                return response()->json([
                    'message' => 'Invalid request. Missing required parameters.',
                    'continue_session' => false
                ], 400);
            }

            // Get active flow (default to loan_repayment)
            $flow = UssdFlow::getActiveFlow('loan_repayment');
            // dd($flow);

            if (!$flow) {
                return response()->json([
                    'message' => 'USSD service is currently unavailable. Please try again later.',
                    'continue_session' => false
                ], 503);
            }

            // Find or create session
            $session = UssdSession::findOrCreateBySessionId(
                $sessionId,
                $phoneNumber,
                $flow->id
            );

            // Check if session is expired
            if ($session->isExpired() && $session->status === 'active') {
                $session->expire();
        return response()->json([
                    'message' => 'Session expired. Please start again.',
                    'continue_session' => false
                ]);
            }

            if($userInput == null) {
                $userInput = '';
            }
            // dd($session, $userInput);

            // Process the flow
            $response = $this->processFlow($session, $userInput);



            $message = $response['message'];
            $continue = $response['continue'] ?? false;
            $sessionId = $sessionId;


            if(Str::contains($message, "END")){

                $message = ltrim(str_replace("END", "", $message));

                echo "END ".$message;

            }else{

                echo "CON ".$message;
            }



            Log::info($message);

            // return $message;

        } catch (\Exception $e) {
            Log::error('USSD Webhook Error:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);

            return response()->json([
                'message' => 'An error occurred. Please try again later.',
                'continue_session' => false
            ], 500);
        }
    }

    /**
     * Process USSD flow based on current node and user input
     */
    private function processFlow(UssdSession $session, string $userInput): array
    {
        $flow = $session->flow;

        if (!$flow) {
            return [
                'message' => 'Flow not found.',
                'continue' => false
            ];
        }

        $currentNodeId = $session->current_node_id ?? 'start';

        // If current_node_id is 'start' (old format), find the actual Start node
        if ($currentNodeId === 'start') {
            $startNode = $flow->findStartNode();
            if ($startNode) {
                $currentNodeId = $startNode['id'];
                $session->current_node_id = $currentNodeId;
                $session->save();
            }
        }

        $node = $flow->findNode($currentNodeId);

        if (!$node) {
            // Try to find Start node as fallback
            $startNode = $flow->findStartNode();
            if ($startNode) {
                $session->current_node_id = $startNode['id'];
                $session->save();
                $node = $startNode;
            } else {
                return [
                    'message' => 'Invalid session. Please start again.',
                    'continue' => false
                ];
            }
        }

        // Check for back navigation (0 or 00)
        $userInputTrimmed = trim($userInput);
        if ($userInputTrimmed === '0' || $userInputTrimmed === '00') {
            // Store the input for back navigation logic
            $session->setData('last_input', $userInputTrimmed);
            $session->save();
            return $this->handleBackNavigation($session, $node);
        }

        // Track navigation history
        $session->addToHistory($currentNodeId);

        // Route to appropriate node handler based on node type
        $nodeType = $node['type'] ?? 'ussd-start';

        $response = match($nodeType) {
            'ussd-start' => $this->handleStartNode($session, $node),
            'ussd-auth' => $this->handleAuthNode($session, $node, $userInput),
            'ussd-menu' => $this->handleMenuNode($session, $node, $userInput),
            'ussd-search' => $this->handleSearchNode($session, $node, $userInput),
            'ussd-display' => $this->handleDisplayNode($session, $node, $userInput),
            'ussd-action' => $this->handleActionNode($session, $node),
            'ussd-end' => $this->handleEndNode($session, $node),
            default => [
                'message' => 'Unknown node type.',
                'continue' => false
            ]
        };

        // Ensure continue=true only when there's a next node
        if (isset($response['continue']) && $response['continue'] === true) {
            // Check if there's actually a next node available
            $hasNextNode = $this->hasNextNode($session, $node);
            if (!$hasNextNode) {
                $response['continue'] = false;
            }
        }

        return $response;
    }

    /**
     * Handle back navigation
     */
    private function handleBackNavigation(UssdSession $session, array $currentNode): array
    {
        $flow = $session->flow;
        $nodeType = $currentNode['type'] ?? '';
        $userInputTrimmed = trim($session->getData('last_input', ''));

        // If on start node, can't go back
        if ($nodeType === 'ussd-start') {
            return [
                'message' => 'Cannot go back from start. Please start again.',
                'continue' => false
            ];
        }

        // Handle "00" - Return to Main Menu
        if ($userInputTrimmed === '00') {
            $mainMenuNode = $this->findMainMenuNode($flow);
            if ($mainMenuNode) {
                $session->current_node_id = $mainMenuNode['id'];
                $session->clearHistory();
                $session->addToHistory($mainMenuNode['id']);
                $session->save();
                return $this->processFlow($session, '');
            }
        }

        // Handle "0" - Go back
        if ($userInputTrimmed === '0') {
            // First, check if node has a "back" edge defined
            $edges = $flow->flow_definition['edges'] ?? [];
            foreach ($edges as $edge) {
                if ($edge['source'] === $currentNode['id'] &&
                    strtolower($edge['label'] ?? '') === 'back') {
                    $targetNode = $flow->findNode($edge['target']);
                    if ($targetNode) {
                        $session->current_node_id = $targetNode['id'];
                        $session->save();
                        return $this->processFlow($session, '');
                    }
                }
            }

            // Fallback: Try to get previous node from history
            $previousNodeId = $session->getPreviousNode();
            if ($previousNodeId) {
                $previousNode = $flow->findNode($previousNodeId);
                if ($previousNode) {
                    $session->current_node_id = $previousNodeId;
                    $session->save();
                    return $this->processFlow($session, '');
                }
            }

            // Last fallback: go to main menu
            $mainMenuNode = $this->findMainMenuNode($flow);
            if ($mainMenuNode) {
                $session->current_node_id = $mainMenuNode['id'];
                $session->clearHistory();
                $session->addToHistory($mainMenuNode['id']);
                $session->save();
                return $this->processFlow($session, '');
            }
        }

        // Last resort: go to start
        $startNode = $flow->findStartNode();
        if ($startNode) {
            $session->current_node_id = $startNode['id'];
            $session->clearHistory();
            $session->addToHistory($startNode['id']);
            $session->save();
            return $this->processFlow($session, '');
        }

        return [
            'message' => 'Cannot go back. Please start again.',
            'continue' => false
        ];
    }

    /**
     * Find the main menu node (first menu node in the flow)
     */
    private function findMainMenuNode(UssdFlow $flow): ?array
    {
        $nodes = $flow->flow_definition['nodes'] ?? [];

        foreach ($nodes as $node) {
            if (($node['type'] ?? '') === 'ussd-menu') {
                return $node;
            }
        }

        return null;
    }

    /**
     * Append navigation options to message based on node configuration
     */
    private function appendNavigationOptions(string $message, array $node): string
    {
        $hasBack = $node['data']['hasBack'] ?? false;
        $hasReturnToMainMenu = $node['data']['hasReturnToMainMenu'] ?? false;

        $options = [];

        if ($hasBack) {
            $options[] = "0. Back";
        }

        if ($hasReturnToMainMenu) {
            $options[] = "00. Main Menu";
        }

        if (!empty($options)) {
            $message .= "\n" . implode("\n", $options);
        }

        return $message;
    }

    /**
     * Check if there's a next node available
     */
    private function hasNextNode(UssdSession $session, array $node): bool
    {
        $flow = $session->flow;
        $edges = $flow->flow_definition['edges'] ?? [];

        // Check if there are any outgoing edges from this node
        foreach ($edges as $edge) {
            if ($edge['source'] === $node['id']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Handle start node - entry point of the flow
     */
    private function handleStartNode(UssdSession $session, array $node): array
    {

        // dd($node);
        // Move to next node (usually authentication)
        $nextNode = $session->flow->getNextNode($node['id']);

        if ($nextNode) {
            $session->current_node_id = $nextNode['id'];
            $session->save();
            return $this->processFlow($session, '');
        }

        return [
            'message' => 'Welcome to Loan Repayment Service',
            'continue' => true
        ];
    }

    /**
     * Handle authentication node
     */
    private function handleAuthNode(UssdSession $session, array $node, string $userInput): array
    {
        // If already authorized for this phone number in this session, proceed
        $isAuthorized = $session->getData('authorized') === true;
        $authorizedPhone = $session->getData('authorized_phone');
        if ($isAuthorized && $authorizedPhone === $session->phone_number) {
            $nextNode = $session->flow->getNextNode($node['id']);
            if ($nextNode) {
                $session->current_node_id = $nextNode['id'];
                $session->save();
                return $this->processFlow($session, '');
            }
        }

        // Prompt for National ID if no input yet
        if (trim($userInput) === '') {
            $message = $this->appendNavigationOptions('Enter your National ID number:', $node);
            return [
                'message' => $message,
                'continue' => true
            ];
        }

        // Basic National ID validation
        if (!is_numeric($userInput) || strlen($userInput) < 5 || strlen($userInput) > 12) {
            $message = $this->appendNavigationOptions('Invalid ID. Please enter a valid National ID number.', $node);
            return [
                'message' => $message,
                'continue' => true
            ];
        }

        // Authenticate by National ID
        $official = Official::whereHas('member', function($query) use ($userInput) {
                $query->where('national_id', $userInput);
            })
            ->where('is_active', true)
            ->with(['member.user', 'group'])
            ->first();

        if (!$official) {
            $session->cancel();
            return [
                'message' => 'Access denied. National ID not found for an active group official.',
                'continue' => false
            ];
        }

        // Mark session as authorized for this phone number
        $session->authenticated_user_id = $official->member->user->id ?? null;
        $session->group_id = $official->group_id;
        $session->setData('authorized', true);
        $session->setData('authorized_phone', $session->phone_number);
        $session->setData('authorized_national_id', $userInput);
        // Track multiple phones that have been authorized in this session
        $authorizedPhones = $session->getData('authorized_phones', []);
        if (!in_array($session->phone_number, $authorizedPhones, true)) {
            $authorizedPhones[] = $session->phone_number;
            $session->setData('authorized_phones', $authorizedPhones);
        }
        $session->save();

        // Move to next node (primary menu)
        $nextNode = $session->flow->getNextNode($node['id']);
        if ($nextNode) {
            $session->current_node_id = $nextNode['id'];
            $session->save();
            return $this->processFlow($session, '');
        }

        return [
            'message' => 'Authentication successful.',
            'continue' => true
        ];
    }

    /**
     * Handle menu node - display menu options
     */
    private function handleMenuNode(UssdSession $session, array $node, string $userInput): array
    {
        $menuOptions = $node['data']['menuOptions'] ?? [];

        // If no user input, display menu
        if (empty($userInput)) {
            $menuText = ($node['data']['menuTitle'] ?? 'Menu') . "\n";
            foreach ($menuOptions as $option) {
                $menuText .= $option['option'] . ". " . $option['label'] . "\n";
            }
            $menuText = $this->appendNavigationOptions($menuText, $node);
            return [
                'message' => $menuText,
                'continue' => true
            ];
        }

        // User selected an option
        $selectedOption = collect($menuOptions)->firstWhere('option', $userInput);

        if (!$selectedOption) {
            $message = $this->appendNavigationOptions('Invalid option. Please try again.', $node);
            return [
                'message' => $message,
                'continue' => true
            ];
        }

        // dd($selectedOption);

        // Move to next node based on selection using edges
        // The flow builder uses edges to connect nodes, not nextNode in menu options
        // Match the selected option with edge labels
        $nextNode = $session->flow->getNextNode($node['id'], $userInput);

        // dd($nextNode, $userInput);

        if ($nextNode) {
            $session->current_node_id = $nextNode['id'];
            $session->save();
            return $this->processFlow($session, '');
        }

        // If no edge found, try to find edge by matching option number with edge label
        // Edge labels might be like "Option 1", "Option 2", etc.
        $edges = $session->flow->flow_definition['edges'] ?? [];
        $matchingEdge = null;

        foreach ($edges as $edge) {
            if ($edge['source'] === $node['id']) {
                // Check if edge label matches the option (e.g., "Option 1" or "1")
                $edgeLabel = $edge['label'] ?? '';
                if ($edgeLabel === $userInput ||
                    $edgeLabel === "Option {$userInput}" ||
                    stripos($edgeLabel, $userInput) !== false) {
                    $matchingEdge = $edge;
                    break;
                }
            }
        }

        if ($matchingEdge) {
            $targetNode = $session->flow->findNode($matchingEdge['target']);
            if ($targetNode) {
                $session->current_node_id = $targetNode['id'];
                $session->save();
                return $this->processFlow($session, '');
            }
        }

        $message = $this->appendNavigationOptions('Invalid selection. Please try again.', $node);
        return [
            'message' => $message,
            'continue' => true
        ];
    }

    /**
     * Handle input node - collect user input
     */
    /**
     * Handle search node - search for members
     */
    private function handleSearchNode(UssdSession $session, array $node, string $userInput): array
    {
        $prompt = $node['data']['searchPrompt'] ?? 'Enter member first name:';
        $resultsLimit = $node['data']['resultsLimit'] ?? 10;
        $groupId = $session->group_id;

        // If no input yet, prompt
        if (trim($userInput) === '') {
            $message = $this->appendNavigationOptions($prompt, $node);
            return [
                'message' => $message,
                'continue' => true
            ];
        }

        // If we already have search results stored and the input is numeric, treat as selection
        $storedResults = $session->getData('search_results', []);
        if (!empty($storedResults) && is_numeric($userInput)) {
            $index = (int)$userInput - 1;
            if ($index >= 0 && $index < count($storedResults)) {
                $selected = $storedResults[$index];
                // dd($selected, $session->current_node_id);
                // Persist selection in session
                $session->setData('selected_member_id', $selected['id']);
                $session->setData('selected_member_name', $selected['name']);
                $session->save();

                // Move to next node using edges (same pattern as menu node)
                $nextNode = $session->flow->getNextNode($node['id']);

                // dd($nextNode);

                if ($nextNode) {
                    $session->current_node_id = $nextNode['id'];
                    $session->save();
                    return $this->processFlow($session, '');
                }

                // dd($nextNode);

                // Fallback: try to find edge manually
                $edges = $session->flow->flow_definition['edges'] ?? [];
                foreach ($edges as $edge) {
                    if ($edge['source'] === $node['id']) {
                        $targetNode = $session->flow->findNode($edge['target']);
                        if ($targetNode) {
                            $session->current_node_id = $targetNode['id'];
                            $session->save();
                            return $this->processFlow($session, '');
                        }
                    }
                }

                return [
                    'message' => 'Member selected.',
                    'continue' => true
                ];
            }

            // Invalid selection, redisplay list
            $listText = $this->formatMemberResults($storedResults);
            $message = $this->appendNavigationOptions("Invalid selection. Please choose a number:\n" . $listText, $node);
            return [
                'message' => $message,
                'continue' => true
            ];
        }

        // Perform search by name (prefix)
        $query = Member::query()
            ->select('id', 'name', 'phone')
            ->where('name', 'like', $userInput . '%')
            ->orderBy('name')
            ->limit($resultsLimit);

        if ($groupId) {
            $query->where('group_id', $groupId);
        }

        $results = $query->get();

        if ($results->isEmpty()) {
            $message = $this->appendNavigationOptions("No members found for \"{$userInput}\". Try again:", $node);
            return [
                'message' => $message,
                'continue' => true
            ];
        }

        // Store results in session
        $session->setData('search_results', $results->map(function ($m) {
            return [
                'id' => $m->id,
                'name' => $m->name,
                'phone' => $m->phone,
            ];
        })->values()->all());
        $session->save();

        // Present results for selection
        $listText = $this->formatMemberResults($session->getData('search_results'));
        $message = $this->appendNavigationOptions("Select member:\n" . $listText, $node);
        return [
            'message' => $message,
            'continue' => true
        ];
    }

    /**
     * Handle display node - display information
     * Can be used as confirmation by setting continue=true and handling user input
     */
    private function handleDisplayNode(UssdSession $session, array $node, string $userInput = ''): array
    {
        $displayContent = $node['data']['displayContent'] ?? '';
        $requiresInput = $node['data']['requiresInput'] ?? false;
        $inputPrompt = $node['data']['inputPrompt'] ?? '';
        $inputDataKey = $node['data']['inputDataKey'] ?? '';
        $inputType = $node['data']['inputType'] ?? 'text';
        $inputValidation = $node['data']['inputValidation'] ?? [];

        // Process display content with placeholders
        $message = $this->processDisplayContent($session, $displayContent);

        // If display content is empty or has configuration error
        if (empty(trim($message)) || $message === 'No display content configured.') {
            return [
                'message' => $message ?: 'No display content configured for this node.',
                'continue' => false
            ];
        }

        // Handle input if required
        if ($requiresInput && !empty($inputDataKey)) {
            // Check if we already have input for this node
            $storedInput = $session->getData($inputDataKey);

            // If no input yet, show display + prompt
            if (empty($userInput) && empty($storedInput)) {
                if (!empty($inputPrompt)) {
                    $message .= "\n\n" . $inputPrompt;
                }
                $message = $this->appendNavigationOptions($message, $node);
                return [
                    'message' => $message,
                    'continue' => true
                ];
            }

            // If we have input, validate and store it
            if (!empty($userInput)) {
                // Validate input
                $validationResult = $this->validateInput($userInput, $inputType, $inputValidation);

                if (!$validationResult['valid']) {
                    // Show error and re-prompt
                    $errorMessage = $message . "\n\n";
                    $errorMessage .= $validationResult['error'] . "\n";
                    if (!empty($inputPrompt)) {
                        $errorMessage .= $inputPrompt;
                    }
                    $errorMessage = $this->appendNavigationOptions($errorMessage, $node);
                    return [
                        'message' => $errorMessage,
                        'continue' => true
                    ];
                }

                // Store valid input
                $session->setData($inputDataKey, $userInput);

                // Special handling: if inputDataKey is 'selected_loan_number', also store the actual loan ID
                if ($inputDataKey === 'selected_loan_number') {
                    $loansList = $session->getData('loans_list', []);
                    $loanNumber = (int)$userInput;

                    if (isset($loansList[$loanNumber])) {
                        $session->setData('selected_loan_id', $loansList[$loanNumber]);
                    }
                }

                // Special handling: if inputDataKey is 'repayment_amount', calculate new balance
                if ($inputDataKey === 'repayment_amount') {
                    $loanId = $session->getData('selected_loan_id');
                    if ($loanId) {
                        $loan = \App\Models\Loan::find($loanId);
                        if ($loan) {
                            $repaymentAmount = floatval($userInput);
                            $newBalance = $loan->getRemainingBalanceAttribute() - $repaymentAmount;
                            $session->setData('calculated_new_balance', $newBalance);
                        }
                    }
                }

                $session->save();

                // Move to next node
                $nextNode = $session->flow->getNextNode($node['id']);
                if ($nextNode) {
                    $session->current_node_id = $nextNode['id'];
                    $session->save();
                    return $this->processFlow($session, '');
                }
            }
        }

        // No input required, just display with continue option
        if (!empty($userInput)) {
            if ($userInput === '1') {
                // Continue to next node
                $nextNode = $session->flow->getNextNode($node['id']);
                if ($nextNode) {
                    $session->current_node_id = $nextNode['id'];
                    $session->save();
                    return $this->processFlow($session, '');
                }
            }
        }

        // Show message with continue option if there's a next node
        $hasNextNode = $this->hasNextNode($session, $node);
        if ($hasNextNode) {
            $message .= "\n1. Continue";
        }

        // Append navigation options
        $message = $this->appendNavigationOptions($message, $node);

        return [
            'message' => $message,
            'continue' => $hasNextNode
        ];
    }

    /**
     * Validate user input based on type and rules
     */
    private function validateInput(string $input, string $type, array $validation): array
    {
        // Required validation
        if (($validation['required'] ?? false) && trim($input) === '') {
            return [
                'valid' => false,
                'error' => 'Input is required.'
            ];
        }

        // Numeric validation
        if (($validation['numeric'] ?? false) || $type === 'numeric' || $type === 'selection') {
            if (!is_numeric($input)) {
                return [
                    'valid' => false,
                    'error' => 'Please enter a valid number.'
                ];
            }

            $numValue = floatval($input);

            // Min validation
            if (isset($validation['min']) && $numValue < $validation['min']) {
                return [
                    'valid' => false,
                    'error' => "Value must be at least {$validation['min']}."
                ];
            }

            // Max validation
            if (isset($validation['max']) && $numValue > $validation['max']) {
                return [
                    'valid' => false,
                    'error' => "Value must not exceed {$validation['max']}."
                ];
            }
        }

        return ['valid' => true];
    }

    /**
     * Process display content by replacing placeholders with actual data
     */
    private function processDisplayContent(UssdSession $session, string $content): string
    {
        if (empty($content)) {
            return 'No display content configured.';
        }

        // Get member data if available
        $memberId = $session->getData('selected_member_id');
        $member = $memberId ? Member::with('group')->find($memberId) : null;

        // Get loan data if available
        $loanId = $session->getData('selected_loan_id');
        $selectedLoan = $loanId ? \App\Models\Loan::find($loanId) : null;

        // Build replacement map
        $replacements = [
            '{member_name}' => $member ? $member->name : '[No member selected]',
            '{member_phone}' => $member ? $member->phone : '[No phone]',
            '{member_id}' => $member ? $member->id : '[No ID]',
            '{group_name}' => $member && $member->group ? $member->group->name : '[No group]',
            '{repayment_amount}' => $session->getData('repayment_amount') ? 'KES ' . number_format($session->getData('repayment_amount'), 2) : '[No amount]',
            '{new_balance}' => $session->getData('calculated_new_balance') ? 'KES ' . number_format($session->getData('calculated_new_balance'), 2) : '[Not calculated]',
        ];

        // Add selected loan placeholders
        if ($selectedLoan) {
            $replacements['{selected_loan_details}'] = $this->formatSelectedLoanDetails($selectedLoan);
            $replacements['{selected_loan_balance}'] = 'KES ' . number_format($selectedLoan->getRemainingBalanceAttribute(), 2);
            $replacements['{selected_loan_amount}'] = 'KES ' . number_format($selectedLoan->principal_amount, 2);
        } else {
            $replacements['{selected_loan_details}'] = '[No loan selected]';
            $replacements['{selected_loan_balance}'] = '[No loan selected]';
            $replacements['{selected_loan_amount}'] = '[No loan selected]';
        }

        // Add all loans placeholder
        if ($member) {
            $replacements['{loan_details}'] = $this->formatAllLoansDetails($member);
        } else {
            $replacements['{loan_details}'] = '[No member selected]';
        }

        // Replace all placeholders
        $message = str_replace(array_keys($replacements), array_values($replacements), $content);

        return $message;
    }

    /**
     * Format all loans for a member
     */
    private function formatAllLoansDetails(Member $member): string
    {
        $loans = $member->loans()->where('status', 'Approved')->get();

        if ($loans->isEmpty()) {
            return "No active loans found.";
        }

        // $output = "Active Loans:\n\n";
        foreach ($loans as $index => $loan) {

            // dd($loan->getRemainingBalanceAttribute(), $loan->balance, $loan->loan_number);
            $balance = $loan->getRemainingBalanceAttribute();
            // $loanNumber = $index + 1;
            $output = $index + 1 . ". ";
            $output .= "Loan {$loan->loan_number} - KES " . number_format($loan->principal_amount, 2) . "\n";
            $output .= "Balance: KES " . number_format($balance, 2) . "\n";

            if ($loan->due_date) {
                $output .= "Due: " . $loan->due_at->format('Y-m-d') . "\n";
            }

            $output .= "\n";

            // dd($output);

            // Store loan in an indexed array for later selection
            $session = UssdSession::where('session_id', request()->input('session_id'))->first();
            if ($session) {
                $loansList = $session->getData('loans_list', []);
                $loansList[$index + 1] = $loan->id;
                $session->setData('loans_list', $loansList);
                $session->save();
            }
        }

        return rtrim($output);
    }

    /**
     * Format selected loan details
     */
    private function formatSelectedLoanDetails(\App\Models\Loan $loan): string
    {
        // dd($loan->getRemainingBalanceAttribute(), $loan->balance, $loan->loan_number);
        $balance = $loan->getRemainingBalanceAttribute();

        $output = "Loan {$loan->loan_number} - KES " . number_format($loan->principal_amount, 2) . "\n";
        $output .= "Balance: KES " . number_format($balance, 2) . "\n";
        $output .= "Due: " . $loan->due_at->format('Y-m-d') . "\n";
        // dd($output);
        return $output;

    }

    /**
     * Handle action node - perform backend action
     */
    private function handleActionNode(UssdSession $session, array $node): array
    {
        $actionType = $node['data']['actionType'] ?? 'record_loan_repayment';

        // Execute the action based on type
        try {
            $result = $this->executeAction($session, $actionType, $node);

            if (!$result['success']) {
                return [
                    'message' => $result['message'] ?? 'Action failed. Please try again.',
                    'continue' => false
                ];
            }

            // Action succeeded, move to next node
            $nextNode = $session->flow->getNextNode($node['id']);
            if ($nextNode) {
                $session->current_node_id = $nextNode['id'];
                $session->save();
                return $this->processFlow($session, '');
            }

            // Fallback: try to find edge manually
            $edges = $session->flow->flow_definition['edges'] ?? [];
            foreach ($edges as $edge) {
                if ($edge['source'] === $node['id']) {
                    $targetNode = $session->flow->findNode($edge['target']);
                    if ($targetNode) {
                        $session->current_node_id = $targetNode['id'];
                        $session->save();
                        return $this->processFlow($session, '');
                    }
                }
            }

            // No next node found, end session
            return [
                'message' => $result['message'] ?? 'Action completed successfully.',
                'continue' => false
            ];

        } catch (\Exception $e) {
            Log::error('Action execution failed', [
                'action_type' => $actionType,
                'session_id' => $session->session_id,
                'error' => $e->getMessage()
            ]);

            return [
                'message' => 'An error occurred. Please try again later.',
                'continue' => false
            ];
        }
    }

    /**
     * Execute specific action based on action type
     */
    private function executeAction(UssdSession $session, string $actionType, array $node): array
    {
        switch ($actionType) {
            case 'record_loan_repayment':
                return $this->actionRecordLoanRepayment($session);

                //in case we need to create another action e.g
            // case 'disburse_loan':
            //     return $this->actionDisburseLoan($session);



            default:
                return [
                    'success' => false,
                    'message' => "Unknown action type: {$actionType}"
                ];
        }
    }

    /**
     * Action: Record Loan Repayment
     * Uses the same logic as Filament LoanRepaymentPage
     */
    private function actionRecordLoanRepayment(UssdSession $session): array
    {
        try {
            // Get required data from session
            $loanId = $session->getData('selected_loan_id');
            $repaymentAmount = $session->getData('repayment_amount');
            $memberId = $session->getData('selected_member_id');
            $authorizedNationalId = $session->getData('authorized_national_id');

            if (!$loanId || !$repaymentAmount) {
                return [
                    'success' => false,
                    'message' => 'Missing loan or repayment amount information.'
                ];
            }

            // Get the loan
            $loan = \App\Models\Loan::find($loanId);
            if (!$loan) {
                return [
                    'success' => false,
                    'message' => 'Loan not found.'
                ];
            }

            // Validate repayment amount (same validation as Filament)
            $amount = floatval($repaymentAmount);

            if ($amount <= 0) {
                return [
                    'success' => false,
                    'message' => 'Invalid repayment amount. Must be greater than zero.'
                ];
            }

            $currentAmountOwed = $loan->remaining_balance;
            if ($amount > $currentAmountOwed) {
                return [
                    'success' => false,
                    'message' => 'Repayment amount exceeds current amount owed (KES ' . number_format($currentAmountOwed, 2) . ').'
                ];
            }

            // dd($loan->remaining_balance, $amount);

            // Create the loan repayment record (same as Filament)
            $repayment = \App\Models\LoanRepayment::create([
                'loan_id' => $loan->id,
                'member_id' => $memberId,
                'amount' => $amount,
                'repayment_date' => now(),
                'payment_method' => 'ussd',
                'reference_number' => 'USSD_' . $session->session_id . '_' . time(),
                'notes' => 'Recorded via USSD by official (National ID: ' . $authorizedNationalId . ')',
                'recorded_by' => 1, // No user auth in USSD context
            ]);

            // dd($repayment);

            // Create transactions using the RepaymentAllocationService (same as Filament)
            $this->createUssdRepaymentTransactions($repayment);

            Log::info('USSD loan repayment recorded successfully', [
                'repayment_id' => $repayment->id,
                'loan_id' => $loan->id,
                'amount' => $amount,
                'session_id' => $session->session_id
            ]);

            return [
                'success' => true,
                'message' => 'Loan repayment recorded successfully.'
            ];

        } catch (\Exception $e) {
            Log::error('USSD loan repayment action failed', [
                'session_id' => $session->session_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
// dd($e);
            return [
                'success' => false,
                'message' => 'Failed to record repayment. Please try again.'
            ];
        }
    }

    /**
     * Create transactions for USSD loan repayment
     * Uses the same RepaymentAllocationService as Filament
     */
    private function createUssdRepaymentTransactions(\App\Models\LoanRepayment $repayment)
    {
        $loan = $repayment->loan;
        $amount = $repayment->amount;

        // Get account name for USSD payment method (typically mobile money)
        $accountName = config('repayment_priority.accounts.mobile_money', 'Mobile Money Account');

        // Use the repayment allocation service (same as Filament)
        $allocationService = new \App\Services\RepaymentAllocationService();
        $transactionData = $allocationService->createRepaymentTransactions(
            $loan,
            (float) $amount,
            'ussd',
            $accountName
        );

        // Create transactions
        foreach ($transactionData as $data) {
            \App\Models\Transaction::create(array_merge($data, [
                'repayment_id' => $repayment->id,
                'transaction_date' => $repayment->repayment_date,
            ]));
        }

        // Update loan status if fully repaid (same as Filament)
        if ($loan->remaining_balance <= 0) {
            $loan->update(['status' => 'Fully Repaid']);
        }
    }

    /**
     * Handle end node - terminate session
     */
    private function handleEndNode(UssdSession $session, array $node): array
    {
        $endMessage = $node['data']['endMessage'] ?? 'Thank you for using our service. Goodbye!';
        $session->complete();
        // Optionally clear session data
        $session->session_data = [];
        $session->save();

        return [
            'message' => $endMessage . "KILL",
            'continue' => false
        ];
    }

    /**
     * Validate user input based on rules
     */
    /**
     * Format member search results for display
     */
    private function formatMemberResults(array $results): string
    {
        $lines = [];
        foreach ($results as $idx => $row) {
            $num = $idx + 1;
            $name = $row['name'] ?? 'Unknown';
            $phone = $row['phone'] ?? '';
            $lines[] = "{$num}. {$name}" . ($phone ? " ({$phone})" : '');
        }
        return implode("\n", $lines);
    }
}
