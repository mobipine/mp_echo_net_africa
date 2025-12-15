<script setup>
import { ref, computed, watch } from 'vue'
import { DialogTitle } from '@headlessui/vue'
import { ExclamationTriangleIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  node: {
    type: Object,
    required: true
  }
})

const emit = defineEmits(['close', 'update', 'remove'])

const nodeData = ref({ ...props.node.data })
const nodeLabel = ref(props.node.label || '')

// Navigation options (common to all nodes)
const hasBack = ref(nodeData.value.hasBack || false)
const hasReturnToMainMenu = ref(nodeData.value.hasReturnToMainMenu || false)

// Watch for node changes
watch(() => props.node, (newNode) => {
  nodeData.value = { ...newNode.data }
  nodeLabel.value = newNode.label || ''
  hasBack.value = newNode.data.hasBack || false
  hasReturnToMainMenu.value = newNode.data.hasReturnToMainMenu || false
}, { deep: true })

const nodeType = computed(() => props.node.type)

function updateData() {
  emit('update', {
    ...nodeData.value,
    label: nodeLabel.value,
    hasBack: hasBack.value,
    hasReturnToMainMenu: hasReturnToMainMenu.value
  })
}

function updateNavigationOptions() {
  nodeData.value.hasBack = hasBack.value
  nodeData.value.hasReturnToMainMenu = hasReturnToMainMenu.value
  updateData()
}

function handleRemove() {
  if (confirm('Are you sure you want to remove this node and all its connections?')) {
    emit('remove')
  }
}

// Menu node specific
const menuOptions = ref(nodeData.value.menuOptions || [])
const menuTitle = ref(nodeData.value.menuTitle || 'Menu')

function addMenuOption() {
  menuOptions.value.push({
    option: String(menuOptions.value.length + 1),
    label: '',
    nextNode: ''
  })
  updateMenuData()
}

function removeMenuOption(index) {
  menuOptions.value.splice(index, 1)
  // Renumber options
  menuOptions.value.forEach((opt, idx) => {
    opt.option = String(idx + 1)
  })
  updateMenuData()
}

function updateMenuData() {
  nodeData.value.menuOptions = menuOptions.value
  nodeData.value.menuTitle = menuTitle.value
  updateData()
}

// Search node specific
const searchPrompt = ref(nodeData.value.searchPrompt || 'Enter member first name:')
const searchField = ref(nodeData.value.searchField || 'name')
const searchType = ref(nodeData.value.searchType || 'starts_with')
const resultsLimit = ref(nodeData.value.resultsLimit || 10)

function updateSearchData() {
  nodeData.value.searchPrompt = searchPrompt.value
  nodeData.value.searchField = searchField.value
  nodeData.value.searchType = searchType.value
  nodeData.value.resultsLimit = resultsLimit.value
  updateData()
}

// Display & Input node specific
const displayTemplate = ref(nodeData.value.displayTemplate || 'default')
const requiresInput = ref(nodeData.value.requiresInput || false)
const inputPrompt = ref(nodeData.value.inputPrompt || '')
const inputDataKey = ref(nodeData.value.inputDataKey || '')
const inputType = ref(nodeData.value.inputType || 'text')
const inputValidation = ref({
  required: nodeData.value.inputValidation?.required || false,
  numeric: nodeData.value.inputValidation?.numeric || false,
  min: nodeData.value.inputValidation?.min || '',
  max: nodeData.value.inputValidation?.max || ''
})

function updateDisplayData() {
  nodeData.value.displayTemplate = displayTemplate.value
  nodeData.value.requiresInput = requiresInput.value
  nodeData.value.inputPrompt = inputPrompt.value
  nodeData.value.inputDataKey = inputDataKey.value
  nodeData.value.inputType = inputType.value
  nodeData.value.inputValidation = {
    required: inputValidation.value.required,
    numeric: inputValidation.value.numeric,
    min: inputValidation.value.min ? parseFloat(inputValidation.value.min) : undefined,
    max: inputValidation.value.max ? parseFloat(inputValidation.value.max) : undefined
  }
  updateData()
}

// Action node specific
const actionType = ref(nodeData.value.actionType || 'record_loan_repayment')
const endpoint = ref(nodeData.value.endpoint || '/api/ussd/record-repayment')

function updateActionData() {
  nodeData.value.actionType = actionType.value
  nodeData.value.endpoint = endpoint.value
  updateData()
}

// End node specific
const endMessage = ref(nodeData.value.endMessage || 'Thank you for using our service. Goodbye!')

function updateEndData() {
  nodeData.value.endMessage = endMessage.value
  updateData()
}
</script>

<template>
  <div class="bg-white dark:bg-gray-800 px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
    <div class="sm:flex sm:items-start">
      <div
        class="mx-auto flex size-12 shrink-0 items-center justify-center rounded-full bg-red-100 dark:bg-red-900 sm:mx-0 sm:size-10">
        <ExclamationTriangleIcon class="size-6 text-red-600 dark:text-red-400" aria-hidden="true" />
      </div>
      <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left relative w-full">
        <DialogTitle as="h3" class="text-base font-semibold text-gray-900 dark:text-white">
          <div class="flex items-center justify-between">
            <span>{{ nodeLabel || 'Configure Node' }}</span>
            <div class="flex items-center gap-2">
              <span class="text-xs text-gray-500 dark:text-gray-400">{{ nodeType }}</span>
              <span class="text-xs font-mono px-2 py-0.5 bg-gray-100 dark:bg-gray-700 rounded text-gray-600 dark:text-gray-300" :title="'Node ID: ' + node.id">
                {{ node.id }}
              </span>
            </div>
          </div>
        </DialogTitle>

        <div class="mt-4">
          <!-- Node Label -->
          <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 dark:text-white mb-1">
              Node Name / Label
            </label>
            <input
              v-model="nodeLabel"
              @input="updateData()"
              type="text"
              class="block w-full rounded-md border-0 py-1.5 text-gray-900 dark:text-white dark:bg-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-gray-600 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
              placeholder="E.g., Main Menu, Member Search, Balance Check"
            />
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
              Give this node a custom name to easily identify it in your flow.
            </p>
          </div>

          <!-- Menu Node Configuration -->
          <template v-if="nodeType === 'ussd-menu'">
            <div class="mb-4">
              <label class="block text-sm font-medium text-gray-700 dark:text-white mb-1">
                Menu Title
              </label>
              <input
                v-model="menuTitle"
                @input="updateMenuData()"
                type="text"
                class="block w-full rounded-md border-0 py-1.5 text-gray-900 dark:text-white dark:bg-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-gray-600 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                placeholder="Enter menu title"
              />
            </div>

            <div class="mb-4">
              <div class="flex items-center justify-between mb-2">
                <label class="block text-sm font-medium text-gray-700 dark:text-white">
                  Menu Options
                </label>
                <button
                  @click="addMenuOption"
                  class="text-sm text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                >
                  + Add Option
                </button>
              </div>
              <div class="space-y-2 max-h-60 overflow-y-auto">
                <div
                  v-for="(option, index) in menuOptions"
                  :key="index"
                  class="flex items-center gap-2 p-2 border border-gray-200 dark:border-gray-700 rounded"
                >
                  <input
                    v-model="option.option"
                    @input="updateMenuData()"
                    type="text"
                    class="w-12 rounded-md border-0 py-1 text-gray-900 dark:text-white dark:bg-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-gray-600 text-sm"
                    placeholder="1"
                  />
                  <input
                    v-model="option.label"
                    @input="updateMenuData()"
                    type="text"
                    class="flex-1 rounded-md border-0 py-1 text-gray-900 dark:text-white dark:bg-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-gray-600 text-sm"
                    placeholder="Option label"
                  />
                  <button
                    @click="removeMenuOption(index)"
                    class="text-red-600 hover:text-red-700 dark:text-red-400"
                  >
                    ×
                  </button>
                </div>
              </div>
            </div>
          </template>

          <!-- Search Node Configuration -->
          <template v-if="nodeType === 'ussd-search'">
            <div class="mb-4">
              <label class="block text-sm font-medium text-gray-700 dark:text-white mb-1">
                Search Prompt
              </label>
              <input
                v-model="searchPrompt"
                @input="updateSearchData()"
                type="text"
                class="block w-full rounded-md border-0 py-1.5 text-gray-900 dark:text-white dark:bg-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-gray-600 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                placeholder="Enter member first name:"
              />
            </div>

            <div class="mb-4">
              <label class="block text-sm font-medium text-gray-700 dark:text-white mb-1">
                Search Field
              </label>
              <select
                v-model="searchField"
                @change="updateSearchData()"
                class="block w-full rounded-md border-0 py-1.5 text-gray-900 dark:text-white dark:bg-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-gray-600 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
              >
                <option value="name">Name</option>
                <option value="phone">Phone</option>
                <option value="national_id">National ID</option>
              </select>
            </div>

            <div class="mb-4">
              <label class="block text-sm font-medium text-gray-700 dark:text-white mb-1">
                Search Type
              </label>
              <select
                v-model="searchType"
                @change="updateSearchData()"
                class="block w-full rounded-md border-0 py-1.5 text-gray-900 dark:text-white dark:bg-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-gray-600 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
              >
                <option value="starts_with">Starts With</option>
                <option value="contains">Contains</option>
                <option value="exact">Exact Match</option>
              </select>
            </div>

            <div class="mb-4">
              <label class="block text-sm font-medium text-gray-700 dark:text-white mb-1">
                Results Limit
              </label>
              <input
                v-model.number="resultsLimit"
                @input="updateSearchData()"
                type="number"
                min="1"
                max="20"
                class="block w-full rounded-md border-0 py-1.5 text-gray-900 dark:text-white dark:bg-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-gray-600 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
              />
            </div>
          </template>

          <!-- Display & Input Node Configuration -->
          <template v-if="nodeType === 'ussd-display'">
            <div class="mb-4">
              <label class="block text-sm font-medium text-gray-700 dark:text-white mb-1">
                Display Template
              </label>
              <select
                v-model="displayTemplate"
                @change="updateDisplayData()"
                class="block w-full rounded-md border-0 py-1.5 px-2 text-gray-900 dark:text-white dark:bg-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-gray-600 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
              >
                <option value="default">Default</option>
                <option value="loan_details">Loan Details (with selection)</option>
                <option value="loan_balance">Member Loan Balance</option>
                <option value="member_info">Member Info</option>
                <option value="selected_loan_details">Selected Loan Details</option>
                <option value="loan_repayment_confirmation">Loan Repayment Confirmation</option>
              </select>
            </div>

            <!-- Input Configuration -->
            <div class="mb-4 p-4 border border-gray-200 dark:border-gray-700 rounded-lg">
              <label class="flex items-center mb-3">
                <input
                  v-model="requiresInput"
                  @change="updateDisplayData()"
                  type="checkbox"
                  class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                />
                <span class="ml-2 text-sm font-medium text-gray-700 dark:text-white">Requires User Input</span>
              </label>

              <div v-if="requiresInput" class="space-y-3">
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-white mb-1">
                    Input Prompt
                  </label>
                  <input
                    v-model="inputPrompt"
                    @input="updateDisplayData()"
                    type="text"
                    class="block w-full rounded-md border-0 py-1.5 px-2 text-gray-900 dark:text-white dark:bg-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-gray-600 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                    placeholder="E.g., 'Enter loan number:', 'Enter amount:'"
                  />
                  <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Prompt shown after display content (optional)
                  </p>
                </div>

                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-white mb-1">
                    Data Key (where to store input)
                  </label>
                  <input
                    v-model="inputDataKey"
                    @input="updateDisplayData()"
                    type="text"
                    class="block w-full rounded-md border-0 py-1.5 px-2 text-gray-900 dark:text-white dark:bg-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-gray-600 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                    placeholder="E.g., selected_loan_number, repayment_amount"
                  />
                  <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Session key for storing the input value
                  </p>
                </div>

                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-white mb-1">
                    Input Type
                  </label>
                  <select
                    v-model="inputType"
                    @change="updateDisplayData()"
                    class="block w-full rounded-md border-0 py-1.5 px-2 text-gray-900 dark:text-white dark:bg-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-gray-600 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                  >
                    <option value="text">Text</option>
                    <option value="numeric">Numeric</option>
                    <option value="selection">Selection (1, 2, 3...)</option>
                  </select>
                </div>

                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-white mb-2">
                    Validation Rules
                  </label>
                  <div class="space-y-2">
                    <label class="flex items-center">
                      <input
                        v-model="inputValidation.required"
                        @change="updateDisplayData()"
                        type="checkbox"
                        class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                      />
                      <span class="ml-2 text-sm text-gray-700 dark:text-white">Required</span>
                    </label>
                    <label class="flex items-center">
                      <input
                        v-model="inputValidation.numeric"
                        @change="updateDisplayData()"
                        type="checkbox"
                        class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                      />
                      <span class="ml-2 text-sm text-gray-700 dark:text-white">Must be numeric</span>
                    </label>
                    <div class="grid grid-cols-2 gap-2">
                      <div>
                        <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Min Value</label>
                        <input
                          v-model="inputValidation.min"
                          @input="updateDisplayData()"
                          type="number"
                          class="block w-full rounded-md border-0 py-1 px-2 text-gray-900 dark:text-white dark:bg-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-gray-600 text-sm"
                          placeholder="Min"
                        />
                      </div>
                      <div>
                        <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Max Value</label>
                        <input
                          v-model="inputValidation.max"
                          @input="updateDisplayData()"
                          type="number"
                          class="block w-full rounded-md border-0 py-1 px-2 text-gray-900 dark:text-white dark:bg-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-gray-600 text-sm"
                          placeholder="Max"
                        />
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </template>

          <!-- Action Node Configuration -->
          <template v-if="nodeType === 'ussd-action'">
            <div class="mb-4">
              <label class="block text-sm font-medium text-gray-700 dark:text-white mb-1">
                Action Type
              </label>
              <select
                v-model="actionType"
                @change="updateActionData()"
                class="block w-full rounded-md border-0 py-1.5 text-gray-900 dark:text-white dark:bg-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-gray-600 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
              >
                <option value="record_loan_repayment">Record Loan Repayment</option>
                <option value="custom">Custom Action</option>
              </select>
            </div>

            <div class="mb-4">
              <label class="block text-sm font-medium text-gray-700 dark:text-white mb-1">
                Endpoint
              </label>
              <input
                v-model="endpoint"
                @input="updateActionData()"
                type="text"
                class="block w-full rounded-md border-0 py-1.5 text-gray-900 dark:text-white dark:bg-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-gray-600 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                placeholder="/api/ussd/record-repayment"
              />
            </div>
          </template>

          <!-- End Node Configuration -->
          <template v-if="nodeType === 'ussd-end'">
            <div class="mb-4">
              <label class="block text-sm font-medium text-gray-700 dark:text-white mb-1">
                End Message
              </label>
              <textarea
                v-model="endMessage"
                @input="updateEndData()"
                rows="3"
                class="block w-full rounded-md border-0 py-1.5 text-gray-900 dark:text-white dark:bg-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-gray-600 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                placeholder="Thank you for using our service. Goodbye!"
              />
            </div>
          </template>

          <!-- Navigation Options (Common to all nodes except Start and End) -->
          <template v-if="nodeType !== 'ussd-start' && nodeType !== 'ussd-end'">
            <div class="mt-6 pt-4 border-t border-gray-200 dark:border-gray-700">
              <label class="block text-sm font-medium text-gray-700 dark:text-white mb-3">
                Navigation Options
              </label>
              <div class="space-y-3">
                <label class="flex items-start">
                  <input
                    v-model="hasBack"
                    @change="updateNavigationOptions()"
                    type="checkbox"
                    class="mt-1 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                  />
                  <div class="ml-3">
                    <span class="text-sm font-medium text-gray-700 dark:text-white">Enable "0. Back"</span>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                      Allows users to go back to the previous node. You must connect an edge labeled "back" to specify where to return.
                    </p>
                  </div>
                </label>
                <label class="flex items-start">
                  <input
                    v-model="hasReturnToMainMenu"
                    @change="updateNavigationOptions()"
                    type="checkbox"
                    class="mt-1 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                  />
                  <div class="ml-3">
                    <span class="text-sm font-medium text-gray-700 dark:text-white">Enable "00. Main Menu"</span>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                      Allows users to return to the main menu (first menu node in the flow).
                    </p>
                  </div>
                </label>
              </div>
            </div>
          </template>

          <!-- Remove Node Button -->
          <div class="mt-6 pt-4 border-t border-gray-200 dark:border-gray-700">
            <button
              type="button"
              @click="handleRemove"
              class="inline-flex justify-center rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600"
            >
              Remove Node & Connections
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="bg-gray-50 dark:bg-gray-700 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6">
    <button
      type="button"
      @click="$emit('close')"
      class="mt-3 inline-flex justify-center rounded-md bg-white dark:bg-gray-600 px-3 py-2 text-sm font-semibold text-gray-700 dark:text-white ring-1 shadow-xs ring-gray-300 dark:ring-gray-500 ring-inset hover:bg-gray-50 dark:hover:bg-gray-500 sm:mt-0 sm:w-auto w-full"
    >
      Close
    </button>
  </div>
</template>

