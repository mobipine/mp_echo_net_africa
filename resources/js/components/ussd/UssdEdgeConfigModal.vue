<script setup>
import { ref, computed, watch } from 'vue'
import { DialogTitle } from '@headlessui/vue'
import { ExclamationTriangleIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  edge: {
    type: Object,
    required: true
  },
  sourceNode: {
    type: Object,
    required: false
  },
  targetNode: {
    type: Object,
    required: false
  }
})

const emit = defineEmits(['close', 'update', 'remove'])

const edgeLabel = ref(props.edge.label || '')
const edgeColor = ref(props.edge.style?.stroke || '#3B82F6')

// Watch for edge changes
watch(() => props.edge, (newEdge) => {
  edgeLabel.value = newEdge.label || ''
  edgeColor.value = newEdge.style?.stroke || '#3B82F6'
}, { deep: true })

// Predefined colors
const colors = [
  { name: 'Blue', value: '#3B82F6' },
  { name: 'Green', value: '#10B981' },
  { name: 'Orange', value: '#F59E0B' },
  { name: 'Purple', value: '#8B5CF6' },
  { name: 'Pink', value: '#EC4899' },
  { name: 'Red', value: '#EF4444' },
  { name: 'Cyan', value: '#06B6D4' },
  { name: 'Indigo', value: '#6366F1' },
  { name: 'Gray', value: '#6B7280' }
]

function updateEdge() {
  const updates = {
    label: edgeLabel.value,
    style: {
      ...props.edge.style,
      stroke: edgeColor.value
    },
    labelBgStyle: {
      fill: edgeColor.value,
      color: '#fff',
      fillOpacity: 0.9
    }
  }
  emit('update', updates)
}

function handleRemove() {
  if (confirm('Are you sure you want to remove this connection?')) {
    emit('remove')
  }
}
</script>

<template>
  <div class="bg-white dark:bg-gray-800 px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
    <div class="sm:flex sm:items-start">
      <div
        class="mx-auto flex size-12 shrink-0 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900 sm:mx-0 sm:size-10">
        <ExclamationTriangleIcon class="size-6 text-blue-600 dark:text-blue-400" aria-hidden="true" />
      </div>
      <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left relative w-full">
        <DialogTitle as="h3" class="text-base font-semibold text-gray-900 dark:text-white">
          <div class="flex items-center justify-between">
            <span>Configure Connection</span>
            <span class="text-xs font-mono px-2 py-0.5 bg-gray-100 dark:bg-gray-700 rounded text-gray-600 dark:text-gray-300" :title="'Edge ID: ' + edge.id">
              {{ edge.id }}
            </span>
          </div>
        </DialogTitle>

        <div class="mt-4">
          <!-- Connection Info -->
          <div class="mb-4 p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
            <div class="text-sm text-gray-700 dark:text-white space-y-2">
              <div class="flex items-center gap-2">
                <span class="font-medium">From:</span>
                <span class="text-gray-600 dark:text-gray-300">{{ sourceNode?.label || 'Unknown' }}</span>
                <span class="text-xs font-mono px-1.5 py-0.5 bg-white dark:bg-gray-600 rounded text-gray-500 dark:text-gray-400" :title="'Source Node ID'">
                  {{ edge.source }}
                </span>
              </div>
              <div class="flex items-center gap-2">
                <span class="font-medium">To:</span>
                <span class="text-gray-600 dark:text-gray-300">{{ targetNode?.label || 'Unknown' }}</span>
                <span class="text-xs font-mono px-1.5 py-0.5 bg-white dark:bg-gray-600 rounded text-gray-500 dark:text-gray-400" :title="'Target Node ID'">
                  {{ edge.target }}
                </span>
              </div>
            </div>
          </div>

          <!-- Edge Label -->
          <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 dark:text-white mb-1">
              Connection Label
            </label>
            <input
              v-model="edgeLabel"
              @input="updateEdge()"
              type="text"
              class="block w-full rounded-md border-0 py-1.5 text-gray-900 dark:text-white dark:bg-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-gray-600 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
              placeholder="Enter connection label (e.g., Option 1, Yes, No)"
            />
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
              This label is used for navigation and display purposes.
            </p>
          </div>

          <!-- Edge Color -->
          <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 dark:text-white mb-2">
              Connection Color
            </label>
            <div class="grid grid-cols-9 gap-2">
              <button
                v-for="color in colors"
                :key="color.value"
                @click="edgeColor = color.value; updateEdge()"
                :style="{ backgroundColor: color.value }"
                :class="[
                  'w-8 h-8 rounded-full border-2 transition-all',
                  edgeColor === color.value
                    ? 'border-gray-900 dark:border-white scale-110 ring-2 ring-offset-2 ring-gray-400'
                    : 'border-gray-300 dark:border-gray-600 hover:scale-105'
                ]"
                :title="color.name"
              />
            </div>
          </div>

          <!-- Preview -->
          <div class="mb-4 p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
            <label class="block text-sm font-medium text-gray-700 dark:text-white mb-2">
              Preview
            </label>
            <div class="flex items-center gap-3">
              <div class="flex-1 h-1 rounded" :style="{ backgroundColor: edgeColor }"></div>
              <div
                class="px-3 py-1 rounded text-xs font-semibold text-white"
                :style="{ backgroundColor: edgeColor }"
              >
                {{ edgeLabel || 'Label' }}
              </div>
              <div class="flex-1 h-1 rounded" :style="{ backgroundColor: edgeColor }"></div>
            </div>
          </div>

          <!-- Remove Connection Button -->
          <div class="mt-6 pt-4 border-t border-gray-200 dark:border-gray-700">
            <button
              type="button"
              @click="handleRemove"
              class="inline-flex justify-center rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600"
            >
              Remove Connection
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

