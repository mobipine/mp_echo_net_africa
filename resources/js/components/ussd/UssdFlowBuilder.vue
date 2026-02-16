<script setup>
import { onMounted, onUnmounted, ref, computed, nextTick } from 'vue'
import { VueFlow, useVueFlow } from '@vue-flow/core'
import { MiniMap } from '@vue-flow/minimap'
import DropzoneBackground from '../common/DropzoneBackground.vue'
import { ControlButton, Controls } from '@vue-flow/controls'
import '@vue-flow/core/dist/style.css'
import '@vue-flow/core/dist/theme-default.css'
import '@vue-flow/minimap/dist/style.css'
import { Dialog, DialogPanel, DialogTitle, TransitionChild, TransitionRoot } from '@headlessui/vue'
import { ExclamationTriangleIcon } from '@heroicons/vue/24/outline'
import UssdNodeSidebar from './UssdNodeSidebar.vue'
import useUssdDragAndDrop from '../../composables/useUssdDnD'
import UssdNodeConfigModal from './UssdNodeConfigModal.vue'
import UssdEdgeConfigModal from './UssdEdgeConfigModal.vue'
import { useToast } from 'vue-toastification'

const { onDragOver, onDrop, onDragLeave, isDragOver } = useUssdDragAndDrop()

const { setViewport, onConnect, updateNode, findNode } = useVueFlow()

const props = defineProps({
  flowId: {
    type: Number,
    required: false
  },
  flowType: {
    type: String,
    default: 'loan_repayment'
  }
})

const toast = useToast()
const elements = ref([]) // Nodes and edges combined
const edges = ref([]) // Only edges
const selected = ref(null)
const selectedEdge = ref(null)
const open = ref(false)
const edgeModalOpen = ref(false)
const isLoading = ref(false)
const history = ref([]) // For undo/redo
const historyIndex = ref(-1)

// Computed properties for edge modal
const selectedEdgeSourceNode = computed(() => {
  if (!selectedEdge.value) return null
  return elements.value.find(el => el.id === selectedEdge.value.source)
})

const selectedEdgeTargetNode = computed(() => {
  if (!selectedEdge.value) return null
  return elements.value.find(el => el.id === selectedEdge.value.target)
})

// Keyboard shortcuts
function handleKeyDown(event) {
  if ((event.ctrlKey || event.metaKey) && event.key === 'z' && !event.shiftKey) {
    event.preventDefault()
    undo()
  } else if ((event.ctrlKey || event.metaKey) && (event.key === 'y' || (event.key === 'z' && event.shiftKey))) {
    event.preventDefault()
    redo()
  } else if ((event.ctrlKey || event.metaKey) && event.key === 's') {
    event.preventDefault()
    saveFlow()
  }
}

onMounted(async () => {
  isLoading.value = true
  try {
    if (props.flowId) {
      // Load existing flow
      const flowResponse = await axios.get(`/api/ussd-flows/${props.flowId}/flow`)
      if (flowResponse.data) {
        const flowData = flowResponse.data
        elements.value = flowData.nodes || []
        edges.value = flowData.edges || []
        // If no nodes were found, seed a default Start node
        if (!elements.value.length) {
          addDefaultStartNode()
        }
        saveToHistory()
      } else {
        // Initialize with a default Start node for new/empty flows
        addDefaultStartNode()
        saveToHistory()
      }
    } else {
      // Initialize with a default Start node
      addDefaultStartNode()
      saveToHistory()
    }

    resetTransform()

    // Add keyboard shortcuts
    window.addEventListener('keydown', handleKeyDown)
  } catch (error) {
    console.error('Error loading flow:', error)
    toast.error('Failed to load USSD flow. Please refresh the page.')
    // Initialize with empty flow on error
    saveToHistory()
  } finally {
    isLoading.value = false
  }
})

onUnmounted(() => {
  window.removeEventListener('keydown', handleKeyDown)
})

function onNodeClick({ node }) {
  if (!node) {
    console.warn('Invalid node click event')
    return
  }

  selected.value = node
  selectedEdge.value = null
  open.value = true
}

function onEdgeClick({ edge }) {
  if (!edge) {
    console.warn('Invalid edge click event')
    return
  }

  selectedEdge.value = edge
  selected.value = null
  edgeModalOpen.value = true
}

function resetTransform() {
  setViewport({ x: 0, y: 0, zoom: 1 })
}

function saveToHistory() {
  // Remove any future history if we're not at the end
  if (historyIndex.value < history.value.length - 1) {
    history.value = history.value.slice(0, historyIndex.value + 1)
  }

  const snapshot = {
    elements: JSON.parse(JSON.stringify(elements.value)),
    edges: JSON.parse(JSON.stringify(edges.value))
  }

  history.value.push(snapshot)
  historyIndex.value = history.value.length - 1

  // Limit history to 50 items
  if (history.value.length > 50) {
    history.value.shift()
    historyIndex.value--
  }
}

function undo() {
  if (historyIndex.value > 0) {
    historyIndex.value--
    const snapshot = history.value[historyIndex.value]
    elements.value = JSON.parse(JSON.stringify(snapshot.elements))
    edges.value = JSON.parse(JSON.stringify(snapshot.edges))
    toast.info('Undone')
  } else {
    toast.warning('Nothing to undo')
  }
}

function redo() {
  if (historyIndex.value < history.value.length - 1) {
    historyIndex.value++
    const snapshot = history.value[historyIndex.value]
    elements.value = JSON.parse(JSON.stringify(snapshot.elements))
    edges.value = JSON.parse(JSON.stringify(snapshot.edges))
    toast.info('Redone')
  } else {
    toast.warning('Nothing to redo')
  }
}

function addDefaultStartNode() {
  const hasStart = elements.value.some((node) => node.type === 'ussd-start')
  if (hasStart) return

  const nodeId = `ussd-${Date.now()}`
  elements.value.push({
    id: nodeId,
    type: 'ussd-start',
    position: { x: 100, y: 100 },
    data: {
      toolbarVisible: true,
      nodeType: 'ussd-start',
      nodeLabel: 'Start',
    },
    label: 'Start',
  })
}

async function saveFlow() {
  isLoading.value = true

  // Validate flow before saving
  const validation = validateFlow()
  if (!validation.valid) {
    toast.error(validation.message)
    isLoading.value = false
    return
  }

  const data = {
    nodes: elements.value,
    edges: edges.value,
  }

  try {
    if (props.flowId) {
      // Update existing flow
      const response = await axios.post(`/api/ussd-flows/${props.flowId}/flow`, data)
      console.log('Flow saved:', response.data)
      toast.success('USSD flow saved successfully!')
      saveToHistory()
    } else {
      toast.warning('Flow ID is required. Please create a flow first.')
    }
  } catch (error) {
    console.error('Error saving flow:', error)
    const errorMessage = error.response?.data?.message || 'Error saving USSD flow.'
    toast.error(errorMessage)
  } finally {
    isLoading.value = false
  }
}

function validateFlow() {
  // Check if there's at least one start node
  const startNodes = elements.value.filter(node => node.type === 'ussd-start')
  if (startNodes.length === 0) {
    return {
      valid: false,
      message: 'Flow must have at least one Start node. A default Start node has been added.'
    }
  }

  if (startNodes.length > 1) {
    return {
      valid: false,
      message: 'Flow can only have one Start node'
    }
  }

  // Check if all nodes are connected (optional validation)
  // Check if there's at least one end node
  const endNodes = elements.value.filter(node => node.type === 'ussd-end')
  if (endNodes.length === 0) {
    return {
      valid: false,
      message: 'Flow must have at least one End node'
    }
  }

  // Check if menu nodes have options
  const menuNodes = elements.value.filter(node => node.type === 'ussd-menu')
  for (const menuNode of menuNodes) {
    const options = menuNode.data?.menuOptions || []
    if (options.length === 0) {
      return {
        valid: false,
        message: `Menu node "${menuNode.label}" must have at least one option`
      }
    }
  }

  return { valid: true }
}

function removeNode(nodeId) {
  elements.value = elements.value.filter(el => el.id !== nodeId) // Remove node
  edges.value = edges.value.filter(edge => edge.source !== nodeId && edge.target !== nodeId) // Remove edges
  saveToHistory()
  open.value = false
  toast.info('Node removed')
}

function removeEdge(edgeId) {
  edges.value = edges.value.filter(edge => edge.id !== edgeId)
  saveToHistory()
  edgeModalOpen.value = false
  toast.info('Connection removed')
}

async function updateEdge(edgeId, updates) {
  const edgeIndex = edges.value.findIndex(e => e.id === edgeId)
  if (edgeIndex !== -1) {
    // Create updated edge object
    const updatedEdge = {
      ...edges.value[edgeIndex],
      ...updates
    }

    // Update the edge in the array
    edges.value[edgeIndex] = updatedEdge

    // Trigger reactivity by creating a new array reference
    edges.value = [...edges.value]

    // Update selected edge if it's the one being edited
    if (selectedEdge.value && selectedEdge.value.id === edgeId) {
      selectedEdge.value = updatedEdge
    }

    // Wait for DOM update
    await nextTick()

    saveToHistory()
  }
}

function getLinkedFlow(id) {
  // Get edges that originate from this node
  const connectedEdges = edges.value.filter(edge => edge.source === id)

  if (connectedEdges.length === 0) {
    return []
  }

  // Map edges to their target nodes with edge information
  return connectedEdges.map(edge => {
    const targetNode = elements.value.find(el => el.id === edge.target)
    return {
      ...edge,
      label: edge.label || `${edge.source} to ${edge.target}`,
      targetLabel: targetNode?.label || 'Unknown'
    }
  })
}

async function updateNodeData(nodeId, data) {
  const node = elements.value.find(el => el.id === nodeId)
  if (node) {
    // Extract label if present in data and update both node.label and node.data
    const { label, ...restData } = data

    // Create updated node object
    const updatedNode = {
      ...node,
      data: { ...node.data, ...restData },
      label: label !== undefined ? label : node.label
    }

    // If label is provided, also update nodeLabel in data
    if (label !== undefined) {
      updatedNode.data.nodeLabel = label
    }

    // Update in elements array
    const index = elements.value.findIndex(el => el.id === nodeId)
    if (index !== -1) {
      elements.value[index] = updatedNode
      // Trigger reactivity by creating a new array reference
      elements.value = [...elements.value]

      // Update selected node if it's the one being edited
      if (selected.value && selected.value.id === nodeId) {
        selected.value = updatedNode
      }

      // Wait for DOM update
      await nextTick()

      // Use Vue Flow's updateNode for proper reactivity after array update
      updateNode(nodeId, updatedNode)
    }

    saveToHistory()
  }
}

// Handle connections between nodes
onConnect((params) => {
  if (!params || !params.source || !params.target) {
    console.warn('Invalid connection params:', params)
    return
  }

  const sourceElement = elements.value.find(el => el.id === params.source)
  const sourceEdges = edges.value.filter(edge => edge.source === params.source)

  // Determine edge label and color based on node type
  let edgeLabel = 'Connection'
  let edgeColor = '#3B82F6' // Default blue

  // For menu nodes, allow multiple connections
  if (sourceElement?.type === 'ussd-menu') {
    const menuOptions = sourceElement?.data?.menuOptions || []
    const optionNumber = sourceEdges.length + 1
    edgeLabel = `Option ${optionNumber}`

    // Color code by option number
    const colors = ['#F59E0B', '#10B981', '#3B82F6', '#8B5CF6', '#EC4899', '#EF4444']
    edgeColor = colors[(optionNumber - 1) % colors.length]
  }

  // Check if connection already exists
  const existingEdge = edges.value.find(
    edge => edge.source === params.source && edge.target === params.target
  )

  if (existingEdge) {
    toast.warning('Connection already exists')
    return
  }

  edges.value.push({
    id: `${params.source}-${params.target}-${Date.now()}`,
    source: params.source,
    target: params.target,
    type: 'smoothstep',
    label: edgeLabel,
    style: { stroke: edgeColor },
    labelBgPadding: [8, 4],
    labelBgBorderRadius: 4,
    labelBgStyle: { fill: edgeColor, color: '#fff', fillOpacity: 0.9 }
  })
  saveToHistory()
})
</script>

<template>
  <div class="dnd-flow" @drop="onDrop">
    <!-- Node Configuration Modal -->
    <TransitionRoot as="template" :show="open">
      <Dialog class="relative z-10" @close="open = false">
        <TransitionChild as="template" enter="ease-out duration-300" enter-from="opacity-0" enter-to="opacity-100"
          leave="ease-in duration-200" leave-from="opacity-100" leave-to="opacity-0">
          <div class="fixed inset-0 bg-black/75 transition-opacity" />
        </TransitionChild>

        <div class="fixed inset-0 z-10 flex items-center justify-center p-4">
          <TransitionChild as="template" enter="ease-out duration-300"
            enter-from="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
            enter-to="opacity-100 translate-y-0 sm:scale-100" leave="ease-in duration-200"
            leave-from="opacity-100 translate-y-0 sm:scale-100"
            leave-to="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95">
            <DialogPanel
              class="relative transform  rounded-lg  dark:bg-gray-800 text-left shadow-xl transition-all w-full max-w-2xl">
              <UssdNodeConfigModal
                v-if="selected"
                :node="selected"
                @close="open = false"
                @update="updateNodeData(selected.id, $event)"
                @remove="removeNode(selected.id)"
              />
            </DialogPanel>
          </TransitionChild>
        </div>
      </Dialog>
    </TransitionRoot>

    <!-- Edge Configuration Modal -->
    <TransitionRoot as="template" :show="edgeModalOpen">
      <Dialog class="relative z-10" @close="edgeModalOpen = false">
        <TransitionChild as="template" enter="ease-out duration-300" enter-from="opacity-0" enter-to="opacity-100"
          leave="ease-in duration-200" leave-from="opacity-100" leave-to="opacity-0">
          <div class="fixed inset-0 bg-black/75 transition-opacity" />
        </TransitionChild>

        <div class="fixed inset-0 z-10 flex items-center justify-center p-4">
          <TransitionChild as="template" enter="ease-out duration-300"
            enter-from="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
            enter-to="opacity-100 translate-y-0 sm:scale-100" leave="ease-in duration-200"
            leave-from="opacity-100 translate-y-0 sm:scale-100"
            leave-to="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95">
            <DialogPanel
              class="relative transform rounded-lg bg-white dark:bg-gray-800 text-left shadow-xl transition-all w-full max-w-2xl">
              <UssdEdgeConfigModal
                v-if="selectedEdge"
                :edge="selectedEdge"
                :sourceNode="selectedEdgeSourceNode"
                :targetNode="selectedEdgeTargetNode"
                @close="edgeModalOpen = false"
                @update="updateEdge(selectedEdge.id, $event)"
                @remove="removeEdge(selectedEdge.id)"
              />
            </DialogPanel>
          </TransitionChild>
        </div>
      </Dialog>
    </TransitionRoot>

    <VueFlow v-model="elements" @node-click="onNodeClick" @edge-click="onEdgeClick" :edges="edges"
      @dragover="onDragOver" @dragleave="onDragLeave" fit-view-on-init>
      <DropzoneBackground :style="{
        backgroundColor: isDragOver ? '#e7f3ff' : 'transparent',
        transition: 'background-color 0.2s ease',
      }">
        <p v-if="isDragOver" class="text-gray-700 dark:text-white">Drop here</p>
      </DropzoneBackground>

      <MiniMap
        nodeColor="#3B82F6"
        maskColor="rgba(0, 0, 0, 0.1)"
        position="bottom-right"
        :pannable="true"
        :zoomable="true"
      />

      <Controls position="top-left" class="flex flex-row gap-3">
        <ControlButton title="Reset Transform" @click="resetTransform">
          <svg width="16" height="16" viewBox="0 0 32 32">
            <path d="M18 28A12 12 0 1 0 6 16v6.2l-3.6-3.6L1 20l6 6l6-6l-1.4-1.4L8 22.2V16a10 10 0 1 1 10 10Z" />
          </svg>
        </ControlButton>

        <ControlButton title="Undo (Ctrl+Z)" @click="undo" :disabled="historyIndex <= 0">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M3 7v6h6" />
            <path d="M21 17a9 9 0 00-9-9 9 9 0 00-6 2.3L3 13" />
          </svg>
        </ControlButton>

        <ControlButton title="Redo (Ctrl+Y)" @click="redo" :disabled="historyIndex >= history.length - 1">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M21 7v6h-6" />
            <path d="M3 17a9 9 0 019-9 9 9 0 016 2.3L21 11" />
          </svg>
        </ControlButton>

        <ControlButton title="Save Flow (Ctrl+S)" @click="saveFlow" :disabled="isLoading" class="dark:text-white">
          <span v-if="isLoading">Saving...</span>
          <span v-else>Save</span>
        </ControlButton>
      </Controls>
    </VueFlow>

    <UssdNodeSidebar />
  </div>
</template>

<style scoped>
.dnd-flow {
  flex-direction: column;
  display: flex;
  height: 100%
}

/* Node styling for USSD nodes */
:deep(.vue-flow__node) {
  min-width: 150px;
  padding: 10px 15px;
  border-radius: 8px;
  overflow: visible;
  font-size: 14px;
  line-height: 1.4;
  text-align: center;
}

:deep(.vue-flow__node-label) {
  word-wrap: break-word;
  white-space: normal;
  max-width: 200px;
}

:deep(.vue-flow__node-ussd-start) {
  background: linear-gradient(135deg, #10B981 0%, #059669 100%);
  color: white;
  border: 2px solid #047857;
  font-weight: 600;
  box-shadow: 0 4px 6px -1px rgba(16, 185, 129, 0.3);
}

:deep(.vue-flow__node-ussd-auth) {
  background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
  color: white;
  border: 2px solid #B45309;
  font-weight: 600;
  box-shadow: 0 4px 6px -1px rgba(245, 158, 11, 0.3);
}

:deep(.vue-flow__node-ussd-menu) {
  background: linear-gradient(135deg, #3B82F6 0%, #2563EB 100%);
  color: white;
  border: 2px solid #1D4ED8;
  font-weight: 500;
  box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.3);
}

:deep(.vue-flow__node-ussd-search) {
  background: linear-gradient(135deg, #EC4899 0%, #DB2777 100%);
  color: white;
  border: 2px solid #BE185D;
  font-weight: 500;
  box-shadow: 0 4px 6px -1px rgba(236, 72, 153, 0.3);
}

:deep(.vue-flow__node-ussd-display) {
  background: linear-gradient(135deg, #06B6D4 0%, #0891B2 100%);
  color: white;
  border: 2px solid #0E7490;
  font-weight: 500;
  box-shadow: 0 4px 6px -1px rgba(6, 182, 212, 0.3);
}

:deep(.vue-flow__node-ussd-action) {
  background: linear-gradient(135deg, #6366F1 0%, #4F46E5 100%);
  color: white;
  border: 2px solid #4338CA;
  font-weight: 500;
  box-shadow: 0 4px 6px -1px rgba(99, 102, 241, 0.3);
}

:deep(.vue-flow__node-ussd-end) {
  background: linear-gradient(135deg, #6B7280 0%, #4B5563 100%);
  color: white;
  border: 2px solid #374151;
  font-weight: 600;
  box-shadow: 0 4px 6px -1px rgba(107, 114, 128, 0.3);
}

:deep(.vue-flow__node-default) {
  background: linear-gradient(135deg, #3B82F6 0%, #2563EB 100%);
  color: white;
  border: 2px solid #1D4ED8;
  font-weight: 500;
  max-width: 250px;
  word-wrap: break-word;
  overflow-wrap: break-word;
  padding: 10px 15px;
  box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
}

:deep(.vue-flow__node-default:hover) {
  box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
  transform: translateY(-1px);
  transition: all 0.2s ease;
}

/* Node hover effects */
:deep(.vue-flow__node:hover) {
  box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.2), 0 4px 6px -2px rgba(0, 0, 0, 0.1);
  transform: translateY(-2px);
  transition: all 0.2s ease;
  cursor: pointer;
}

:deep(.vue-flow__node.selected) {
  outline: 3px solid #3B82F6;
  outline-offset: 2px;
}

/* Edge styling */
:deep(.vue-flow__edge) {
  cursor: pointer;
}

:deep(.vue-flow__edge:hover .vue-flow__edge-path) {
  stroke-width: 3;
}

:deep(.vue-flow__edge.selected .vue-flow__edge-path) {
  stroke-width: 4;
}

:deep(.vue-flow__edge-label) {
  font-size: 12px;
  font-weight: 600;
  padding: 4px 8px;
  border-radius: 4px;
  background: inherit !important;
  color: white;
  cursor: pointer;
}

:deep(.vue-flow__edge-label:hover) {
  transform: scale(1.1);
  transition: transform 0.2s ease;
}

@media screen and (min-width: 640px) {
  .dnd-flow {
    flex-direction: row
  }
}
</style>

