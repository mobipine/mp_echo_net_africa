<script setup>
import useSurveyDragAndDrop from './useSurveyDnD'

const { onDragStart } = useSurveyDragAndDrop()

const props = defineProps({
  questions: {
    type: Array,
    default: () => []
  }
})
</script>

<template>
  <aside class="flex flex-col gap-y-16 space-y-16">
    <div class="flex flex-col" style="margin-bottom: 3rem;">
      <div class="description text-lg text-left w-full text-gray-700">Survey Questions</div>
      <div class="nodes">
        <div v-for="question in questions" :key="question.id" 
             class="vue-flow__node-default" style="width: auto;" :draggable="true"
             @dragstart="onDragStart($event, { type: 'default', question: question })">
          {{ question.question }}
        </div>
      </div>
    </div>

    <div class="flex flex-col" style="margin-bottom: 3rem;">
      <div class="description text-lg text-left w-full text-gray-700">Special Nodes</div>
      <div class="nodes">
        <div class="vue-flow__node-input" style="width: auto;" :draggable="true"
             @dragstart="onDragStart($event, { type: 'input', text: 'Start' })">Start</div>
        
        <div class="vue-flow__node-output" style="width: auto;" :draggable="true"
             @dragstart="onDragStart($event, { type: 'output', text: 'End' })">End</div>
        
        <!-- <div class="vue-flow__node-default" style="width: auto;" :draggable="true"
             @dragstart="onDragStart($event, { type: 'default', text: 'Condition' })">Condition</div> -->
      </div>
    </div>
  </aside>
</template>

<style scoped>
aside {
  border-bottom-left-radius: 0.5rem;
  border-top-left-radius: 0.5rem;
  color: #fff;
  font-weight: 700;
  border-right: 1px solid #eee;
  padding: 15px 10px;
  font-size: 12px;
  background: #f4f4f5;
  box-shadow: 0 2px 5px #0000004d
}

.nodes > * {
  margin-bottom: 10px;
  cursor: grab;
  font-weight: 500;
  -webkit-box-shadow: 5px 5px 10px 2px rgba(0, 0, 0, .25);
  border-radius: 15px;
  box-shadow: 5px 5px 10px 2px #00000040;
  padding: 8px 12px;
}
</style>