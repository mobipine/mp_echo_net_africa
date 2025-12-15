<script setup>
import { ref } from 'vue'
import useUssdDragAndDrop from '../../composables/useUssdDnD'

const { onDragStart } = useUssdDragAndDrop()

const isCollapsed = ref(false)

function toggleSidebar() {
  isCollapsed.value = !isCollapsed.value
}

// USSD Node Types Configuration
const ussdNodeTypes = [
  {
    type: 'ussd-start',
    label: 'Start',
    color: '#10B981',
    icon: '▶',
    description: 'Entry point for USSD flow'
  },
  {
    type: 'ussd-auth',
    label: 'Authentication',
    color: '#F59E0B',
    icon: '🔒',
    description: 'Authenticate user by phone number'
  },
  {
    type: 'ussd-menu',
    label: 'Menu',
    color: '#3B82F6',
    icon: '📋',
    description: 'Display menu with options'
  },
  {
    type: 'ussd-search',
    label: 'Member Search',
    color: '#EC4899',
    icon: '🔍',
    description: 'Search for members by name'
  },
  {
    type: 'ussd-display',
    label: 'Display & Input',
    color: '#06B6D4',
    icon: '📱',
    description: 'Display information and collect input'
  },
  {
    type: 'ussd-action',
    label: 'Action',
    color: '#6366F1',
    icon: '⚡',
    description: 'Perform backend action'
  },
  {
    type: 'ussd-end',
    label: 'End',
    color: '#6B7280',
    icon: '■',
    description: 'End USSD session'
  }
]
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
      <div class="flex flex-col" style="margin-bottom: 1rem;">
        <div class="description text-lg text-left w-full text-gray-700 dark:text-white mb-2">
          USSD Node Types
        </div>
        <div class="text-xs text-gray-500 dark:text-gray-400 mb-3">
          Drag nodes to canvas
        </div>
      </div>

      <div class="nodes">
        <div
          v-for="nodeType in ussdNodeTypes"
          :key="nodeType.type"
          class="ussd-node-item"
          :style="{ borderLeftColor: nodeType.color }"
          :draggable="true"
          @dragstart="onDragStart($event, {
            type: nodeType.type,
            text: nodeType.label,
            nodeType: nodeType
          })"
          :title="nodeType.description"
        >
          <span class="node-icon">{{ nodeType.icon }}</span>
          <span class="node-label">{{ nodeType.label }}</span>
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
  z-index: 10;
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

.nodes {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.ussd-node-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 12px;
  background: white;
  border-left: 4px solid;
  border-radius: 6px;
  cursor: grab;
  font-weight: 500;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
  transition: all 0.2s ease;
  user-select: none;
}

.ussd-node-item:hover {
  transform: translateX(4px);
  box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
}

.ussd-node-item:active {
  cursor: grabbing;
}

.node-icon {
  font-size: 18px;
  flex-shrink: 0;
}

.node-label {
  color: #374151;
  font-size: 13px;
  flex: 1;
}

.dark .ussd-node-item {
  background: #1f2937;
}

.dark .node-label {
  color: #f3f4f6;
}
</style>

