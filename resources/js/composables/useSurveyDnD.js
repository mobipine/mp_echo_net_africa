import { useVueFlow } from '@vue-flow/core'
import { ref, watch } from 'vue'

const state = {
  draggedType: ref(null),
  draggedData: ref(null),
  isDragOver: ref(false),
  isDragging: ref(false),
}

export default function useSurveyDragAndDrop() {
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

    const nodeId = Date.now().toString()
    const data = draggedData.value

    let newNode = {
      id: nodeId,
      type: data.type,
      position,
      data: {
        toolbarVisible: true,
        questionId: data.question ? data.question.id : null,
        questionType: data.question ? data.question.answer_data_type : 'custom',
        questionText: data.question ? data.question.question : data.text,
        possibleAnswers: data.question ? data.question.possible_answers : [],
        answerStrictness: data.question ? data.question.answer_strictness : 'flexible',
        violationResponse: data.question ? data.question.data_type_violation_response : ''
      }
    }

    if (data.question) {
      // newNode.label = data.question.question.substring(0, 20) + (data.question.question.length > 20 ? '...' : '')
      newNode.label = data.question.question
    } else {
      newNode.label = data.text
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