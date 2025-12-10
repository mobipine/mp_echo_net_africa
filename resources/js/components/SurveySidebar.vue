<script setup>
import { ref } from 'vue'
import useSurveyDragAndDrop from './useSurveyDnD'

const { onDragStart } = useSurveyDragAndDrop()

const props = defineProps({
  questions: {
    type: Array,
    default: () => []
  }
})

const isCollapsed = ref(false)

function toggleSidebar() {
  isCollapsed.value = !isCollapsed.value
}
</script>

<template>
  <aside :class="['sidebar-container', { 'collapsed': isCollapsed }]">
    <button
      @click="toggleSidebar"
      class="toggle-button"
      :title="isCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
      aria-label="Toggle sidebar"
    >
      <svg
        :class="['chevron-icon', { 'rotated': isCollapsed }]"
        width="20"
        height="20"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
      >
        <path d="M9 18l6-6-6-6" />
      </svg>
    </button>

    <div class="sidebar-content">
      <div class="flex flex-col" style="margin-bottom: 3rem;">
        <div class="description text-lg text-left w-full text-gray-700 dark:text-white">Survey Questions</div>
        <div class="nodes">
          <div v-for="question in questions" :key="question.id"
               class="vue-flow__node-default" style="width: auto;" :draggable="true"
               @dragstart="onDragStart($event, { type: 'default', question: question })">
            {{ question.question }}
          </div>
        </div>
      </div>

      <div class="flex flex-col" style="margin-bottom: 3rem;">
        <div class="description text-lg text-left w-full text-gray-700 dark:text-white">Special Nodes</div>
        <div class="nodes">
          <div class="vue-flow__node-input" style="width: auto;" :draggable="true"
               @dragstart="onDragStart($event, { type: 'input', text: 'Start' })">Start</div>

          <div class="vue-flow__node-output" style="width: auto;" :draggable="true"
               @dragstart="onDragStart($event, { type: 'output', text: 'End' })">End</div>

          <!-- <div class="vue-flow__node-default" style="width: auto;" :draggable="true"
               @dragstart="onDragStart($event, { type: 'default', text: 'Condition' })">Condition</div> -->
        </div>
      </div>
    </div>
  </aside>
</template>

<style scoped>
.sidebar-container {
  position: relative;
  border-bottom-left-radius: 0.5rem;
  border-top-left-radius: 0.5rem;
  color: #fff;
  font-weight: 700;
  border-right: 1px solid #eee;
  padding: 15px 10px;
  font-size: 12px;
  background: #f4f4f5;
  box-shadow: 0 2px 5px #0000004d;
  max-width: 300px;
  min-width: 200px;
  width: 300px;
  transition: width 0.3s ease, min-width 0.3s ease, max-width 0.3s ease;
  overflow: hidden;
}

.sidebar-container.collapsed {
  width: 50px;
  min-width: 50px;
  max-width: 50px;
  padding: 15px 5px;
}

.toggle-button {
  position: absolute;
  top: 10px;
  right: 10px;
  background: rgba(255, 255, 255, 0.9);
  border: 1px solid #ddd;
  border-radius: 4px;
  padding: 4px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 10;
  transition: background-color 0.2s ease;
  color: #374151;
}

.toggle-button:hover {
  background: rgba(255, 255, 255, 1);
  border-color: #999;
}

.sidebar-container.collapsed .toggle-button {
  right: 5px;
}

.chevron-icon {
  transition: transform 0.3s ease;
  color: #374151;
}

.chevron-icon.rotated {
  transform: rotate(180deg);
}

.sidebar-content {
  transition: opacity 0.2s ease;
  opacity: 1;
}

.sidebar-container.collapsed .sidebar-content {
  opacity: 0;
  pointer-events: none;
}

.nodes > * {
  margin-bottom: 10px;
  cursor: grab;
  font-weight: 500;
  -webkit-box-shadow: 5px 5px 10px 2px rgba(0, 0, 0, .25);
  border-radius: 15px;
  box-shadow: 5px 5px 10px 2px #00000040;
  padding: 8px 12px;
  word-wrap: break-word;
  overflow-wrap: break-word;
}
</style>
