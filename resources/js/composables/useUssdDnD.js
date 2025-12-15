import { useVueFlow } from '@vue-flow/core'
import { ref, watch } from 'vue'

const state = {
  draggedType: ref(null),
  draggedData: ref(null),
  isDragOver: ref(false),
  isDragging: ref(false),
}

export default function useUssdDragAndDrop() {
  const { draggedType, draggedData, isDragOver, isDragging } = state
  const { addNodes, screenToFlowCoordinate, onNodesInitialized, updateNode } = useVueFlow()

  watch(isDragging, (dragging) => {
    document.body.style.userSelect = dragging ? 'none' : ''
  })

  function onDragStart(event, data) {
    if (event.dataTransfer) {
      event.dataTransfer.setData('application/vueflow', JSON.stringify(data))
      event.dataTransfer.effectAllowed = 'move'
    }

    draggedType.value = data.type
    draggedData.value = data
    isDragging.value = true

    document.addEventListener('drop', onDragEnd)
  }

  function onDragOver(event) {
    event.preventDefault()

    if (draggedType.value) {
      isDragOver.value = true
      if (event.dataTransfer) {
        event.dataTransfer.dropEffect = 'move'
      }
    }
  }

  function onDragLeave() {
    isDragOver.value = false
  }

  function onDragEnd() {
    isDragging.value = false
    isDragOver.value = false
    draggedType.value = null
    draggedData.value = null
    document.removeEventListener('drop', onDragEnd)
  }

  function onDrop(event) {
    const position = screenToFlowCoordinate({
      x: event.clientX,
      y: event.clientY,
    })

    const nodeId = `ussd-${Date.now()}`
    let data = draggedData.value

    // Fallback to event.dataTransfer if draggedData is null
    if (!data && event.dataTransfer) {
      try {
        const dataString = event.dataTransfer.getData('application/vueflow')
        if (dataString) {
          data = JSON.parse(dataString)
        }
      } catch (e) {
        console.error('Error parsing drag data:', e)
        onDragEnd()
        return
      }
    }

    // If still no data, return early
    if (!data || !data.type) {
      console.warn('No drag data available')
      onDragEnd()
      return
    }

    // Default data structure for USSD nodes
    const defaultNodeData = {
      toolbarVisible: true,
      nodeType: data.type,
      nodeLabel: data.text || data.nodeType?.label || 'Node',
      hasBack: false,
      hasReturnToMainMenu: false
    }

    // Node-specific default data
    let nodeData = { ...defaultNodeData }

    switch (data.type) {
      case 'ussd-menu':
        nodeData.menuTitle = 'Menu'
        nodeData.menuOptions = []
        break
      case 'ussd-search':
        nodeData.searchPrompt = 'Enter member first name:'
        nodeData.searchField = 'name'
        nodeData.searchType = 'starts_with'
        nodeData.resultsLimit = 10
        break
      case 'ussd-display':
        nodeData.displayContent = ''
        nodeData.requiresInput = false
        nodeData.inputPrompt = ''
        nodeData.inputDataKey = ''
        nodeData.inputType = 'text'
        nodeData.inputValidation = {}
        break
      case 'ussd-action':
        nodeData.actionType = 'record_loan_repayment'
        nodeData.endpoint = '/api/ussd/record-repayment'
        break
      case 'ussd-end':
        nodeData.endMessage = 'Thank you for using our service. Goodbye!'
        break
    }

    let newNode = {
      id: nodeId,
      type: data.type,
      position,
      data: nodeData,
      label: data.text || data.nodeType?.label || 'Node'
    }

    const { off } = onNodesInitialized(() => {
      updateNode(nodeId, (node) => ({
        position: { x: node.position.x - node.dimensions.width / 2, y: node.position.y - node.dimensions.height / 2 },
      }))
      off()
    })

    addNodes(newNode)
  }

  return {
    draggedType,
    isDragOver,
    isDragging,
    onDragStart,
    onDragLeave,
    onDragOver,
    onDrop,
  }
}
