<script setup>
import { onMounted, ref } from 'vue'
import { VueFlow, useVueFlow } from '@vue-flow/core'
import DropzoneBackground from './DropzoneBackground.vue'
import { ControlButton, Controls } from '@vue-flow/controls'
import '@vue-flow/core/dist/style.css'
import '@vue-flow/core/dist/theme-default.css'
import { Dialog, DialogPanel, DialogTitle, TransitionChild, TransitionRoot } from '@headlessui/vue'
import { ExclamationTriangleIcon } from '@heroicons/vue/24/outline'
import Sidebar from './SurveySidebar.vue'
import useSurveyDragAndDrop from './useSurveyDnD'
import { useToast } from 'vue-toastification';

const { onDragOver, onDrop, onDragLeave, isDragOver } = useSurveyDragAndDrop()


const { setViewport, onConnect } = useVueFlow()

const props = defineProps({
  surveyId: {
    type: Number,
    required: true
  }
})

const toast = useToast();
const elements = ref([]) // Nodes and edges combined
const edges = ref([]) // Only edges
const selected = ref(null)
const open = ref(false)
const answerFlows = ref({})

// Fetch survey questions
const surveyQuestions = ref([])

onMounted(async () => {
  try {
    // Load survey questions
    const response = await axios.get(`/api/surveys/${props.surveyId}/questions`)
    surveyQuestions.value = response.data

    // Load saved flow if exists
    const flowResponse = await axios.get(`/api/surveys/${props.surveyId}/flow`)
    if (flowResponse.data) {
      elements.value = flowResponse.data.elements || []
      edges.value = flowResponse.data.edges || []
    }

    resetTransform()
  } catch (error) {
    console.error('Error:', error)
  }
})

function onNodeClick({ node }) {
  if (!node) {
    console.warn('Invalid node click event')
    return
  }
  
  selected.value = node
  open.value = true
}

function resetTransform() {
  setViewport({ x: 0, y: 0, zoom: 1 })
}

async function saveFlow() {
  const data = {
    edges: edges.value,
    elements: elements.value,
  };

  try {
    const response = await axios.post(`/api/surveys/${props.surveyId}/flow`, data);
    console.log('Flow saved:', response.data);
    toast.success('Survey flow saved successfully!');
  } catch (error) {
    console.error('Error saving flow:', error);
    toast.error('Error saving survey flow.');
  }
}

function removeNode(nodeId) {
  elements.value = elements.value.filter(el => el.id !== nodeId) // Remove node
  edges.value = edges.value.filter(edge => edge.source !== nodeId && edge.target !== nodeId) // Remove edges
  open.value = false
}

function removeConnection(flowId, sourceId, targetId) {
  console.log('Removing connection to:', targetId);

  edges.value = edges.value.filter(edge => !(edge.source === sourceId && edge.target === targetId));
  open.value = false
}

function updateEdgeLabel(flowId, sourceId, targetId, newLabel) {
    const edge = edges.value.find(edge => edge.source === sourceId && edge.target === targetId);
    if (edge) {
      edge.label = newLabel;
    }  
}

function getSelectedNode(id) {
  const filtered = elements.value.filter(br => br.id === id);
  return filtered[0];
}


function getLinkedFlow(id) {
  // br.type === 'default'
  const filtered = elements.value.filter(br => br.source === id);

  if (filtered.length === 0) {
    return [];
  }

  // console.log('Filtered Linked Flow:', filtered);
  return filtered;
}

function getNodeAnswers(id) {
  const node = getSelectedNode(id);
  return node?.data?.possibleAnswers || [];
}

function getLinkedFlowValue(index) {
  console.log('Getting linked flow for index:', index);
  if (!selected.value || !elements.value) return '';
  
  const selectedElement = elements.value.find(el => el.id === selected.value.id);
  return selectedElement?.data?.possibleAnswers?.[index]?.linkedFlow || '';
}

function setLinkedFlowValue(index, value) {
  console.log('Setting linked flow for index:', index, 'to value:', value);
  
  if (!selected.value || !elements.value) return;
  
  const selectedElement = elements.value.find(el => el.id === selected.value.id);
  if (selectedElement?.data?.possibleAnswers?.[index]) {
    selectedElement.data.possibleAnswers[index].linkedFlow = value;
  }
}

// Handle connections between questions based on answers
onConnect((params) => {
  if (!params || !params.source || !params.target) {
    console.warn('Invalid connection params:', params)
    return
  }

  const sourceElement = elements.value.find(el => el.id === params.source)
  const sourceEdges = edges.value.filter(edge => edge.source === params.source)

  if (sourceElement?.data?.answerStrictness === 'Multiple Choice') {
    if (sourceEdges.length < sourceElement.data.possibleAnswers.length) {
      edges.value.push({
        id: `${params.source}-${params.target}`,
        source: params.source,
        target: params.target,
        type: 'smoothstep',
        label: `${params.source} to ${params.target}`,
        style: { stroke: 'orange' },
        labelBgPadding: [8, 4],
        labelBgBorderRadius: 4,
        labelBgStyle: { fill: 'orange', color: '#fff', fillOpacity: 0.9 }
      })
    }
  } else if (sourceEdges.length === 0) {
    edges.value.push({
      id: `${params.source}-${params.target}`,
      source: params.source,
      target: params.target,
      type: 'smoothstep',
      label: `${params.source} to ${params.target}`,
      style: { stroke: 'blue' },
      labelBgPadding: [8, 4],
      labelBgBorderRadius: 4,
      labelBgStyle: { fill: 'blue', color: '#fff', fillOpacity: 0.9 }
    })
  }
})
</script>

<template>
  <div class="dnd-flow" @drop="onDrop">
    <TransitionRoot as="template" :show="open">
      <Dialog class="relative z-10" @close="open = false">
        <TransitionChild as="template" enter="ease-out duration-300" enter-from="opacity-0" enter-to="opacity-100"
          leave="ease-in duration-200" leave-from="opacity-100" leave-to="opacity-0">
          <div class="fixed inset-0 bg-black/75 transition-opacity" />
        </TransitionChild>

        <div class="fixed inset-0 z-10 w-[200px]">
          <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
            <TransitionChild as="template" enter="ease-out duration-300"
              enter-from="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
              enter-to="opacity-100 translate-y-0 sm:scale-100" leave="ease-in duration-200"
              leave-from="opacity-100 translate-y-0 sm:scale-100"
              leave-to="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95">
              <DialogPanel
                class="relative transform overflow-hidden rounded-lg bg-white text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-2xl"
                style="width: 600px;">
                <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                  <div class="sm:flex sm:items-start">
                    <div
                      class="mx-auto flex size-12 shrink-0 items-center justify-center rounded-full bg-red-100 sm:mx-0 sm:size-10">
                      <ExclamationTriangleIcon class="size-6 text-red-600" aria-hidden="true" />
                    </div>
                    <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left relative">

                      <!-- title -->
                      <DialogTitle as="h3" class="text-base font-semibold text-gray-900">
                        <div class="text-base font-semibold leading-6 text-gray-900" style="color: #000000 !important;">
                          <span class="relative">{{ selected?.label || 'Unknown Node' }}</span>
                          <!-- <span class="relative">{{ selected.label }}
                            <span class="absolute -top-1 -right-3 text-xs">{{
                            selected.id
                              }}</span></span> -->
                        </div>
                      </DialogTitle>



                      <div class="mt-2">

                        <!-- setup for mcqs -->
                        <template
                          v-if="(selected?.data?.answerStrictness == 'Multiple Choice' && getNodeAnswers(selected?.id).length > 0)">
                          <!-- create an interface with inputs in which one can set the possible answers to the linked flows -->
                          <!-- an answer can go to one flow only but a flow can be connected to multiple answers -->
                          <div class="grid grid-cols-1 gap-x-6 gap-y-8 sm:grid-cols-6">
                            <div class="col-span-6 sm:col-span-3" v-for="(answer, index) in getNodeAnswers(selected?.id)"
                              :key="`answer-${index}`">
                              <label :for="`answer-${index}`"
                                class="block text-sm font-medium leading-6 text-gray-900 dark:text-slate-500">
                                Answer {{ index + 1 }}: {{ answer.answer }}
                              </label>


                              <label :for="`flow-${index}`"
                                class="block text-sm font-medium leading-6 text-gray-900 dark:text-slate-500 mt-2">Linked
                                Flow for Answer {{ index + 1 }}</label>

                              <select :id="`flow-${index}`" :value="getLinkedFlowValue(index)"
                                @input="setLinkedFlowValue(index, $event.target.value)"
                                class="p-3 mt-2 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6">
                                <option value="" disabled>Select a flow</option>
                                <option v-if="getLinkedFlow(selected?.id).length > 0"
                                  v-for="node in getLinkedFlow(selected?.id)" :key="node.id" :value="node.id">
                                  {{ node.label }}
                                </option>
                              </select>

                            </div>

                          </div>
                        </template>

                        <!-- this will be available for all nodes -->
                        <div class="col-span-6 sm:col-span-3 mt-4">
                          <button type="button"
                            class="inline-flex justify-center rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600"
                            @click="removeNode(selected?.id)">Remove Node & Connections</button>
                        </div>

                        <!-- add a template that enabled one to be able to edit the link labels or remove them entirely -->
                        <template v-if="getLinkedFlow(selected?.id).length > 0">
                          <div class="mt-6">
                            <h4 class="text-sm font-medium text-gray-900 mb-2">Connected Flows:</h4>

                            <div class="space-y-4 max-h-60 overflow-y-auto">
                              <div v-for="(flow, index) in getLinkedFlow(selected?.id)" :key="flow.id"
                                class="flex items-center space-x-2">
                                <!-- {{ flow }} -->
                                <!-- <span class="text-sm text-gray-700">{{ flow.label }} (ID: {{ flow.id }})</span> -->
                                <span class="text-sm text-gray-700">{{ flow.label }}</span>
                                <input type="text" v-model="flow.label"
                                  @input="updateEdgeLabel(flow.id, flow.source, flow.target, flow.label)"
                                  placeholder="Edit connection label"
                                  class="ml-2 block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6" />

                                <div class="col-span-2 sm:col-span-1"
                                  @click="removeConnection(flow.id, flow.source, flow.target)">
                                  <svg class="" width="32" height="32" viewBox="0 0 32 32" fill="none"
                                    xmlns="http://www.w3.org/2000/svg">
                                    <path
                                      d="M3.55539 16C3.55539 9.14421 9.14409 3.55551 15.9998 3.55551C22.8556 3.55551 28.4443 9.14421 28.4443 16C28.4443 22.8557 22.8556 28.4444 15.9998 28.4444C9.14409 28.4444 3.55539 22.8557 3.55539 16ZM19.7732 21.6622C20.2495 21.6622 20.7329 21.484 21.1084 21.1085C21.8422 20.3747 21.8422 19.1719 21.1084 18.4381L18.6702 16L21.1084 13.5618C21.8422 12.828 21.8422 11.6252 21.1084 10.8914C20.3746 10.1576 19.1718 10.1576 18.438 10.8914L15.9998 13.3295L13.5617 10.8914C12.8279 10.1576 11.6251 10.1576 10.8913 10.8914C10.1575 11.6252 10.1575 12.828 10.8913 13.5618L13.3294 16L10.8913 18.4381C10.1575 19.1719 10.1575 20.3747 10.8913 21.1085C11.2668 21.484 11.7501 21.6622 12.2265 21.6622C12.7029 21.6622 13.1862 21.484 13.5617 21.1085L15.9998 18.6704L18.438 21.1085C18.8134 21.484 19.2968 21.6622 19.7732 21.6622Z"
                                      fill="#CA4B3D" stroke="#CA4B3D" stroke-width="1.77778" />
                                  </svg>
                                </div>
                              </div>
                            </div>
                          </div>
                        </template>


                      </div>
                    </div>
                  </div>
                </div>
                <div class="bg-gray-50 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6">
                  <button type="button"
                    class="mt-3 inline-flex justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 ring-1 shadow-xs ring-gray-300 ring-inset hover:bg-gray-50 sm:mt-0 sm:w-auto w-full"
                    @click="open = false" ref="cancelButtonRef" style="color: #000000 !important;">Close</button>
                </div>
              </DialogPanel>
            </TransitionChild>
          </div>
        </div>
      </Dialog>
    </TransitionRoot>




    <VueFlow v-model="elements" @node-click="onNodeClick" @edge-click="onEdgeClick" :edges="edges"
      @dragover="onDragOver" @dragleave="onDragLeave" fit-view-on-init>
      <DropzoneBackground :style="{
        backgroundColor: isDragOver ? '#e7f3ff' : 'transparent',
        transition: 'background-color 0.2s ease',
      }">
        <p v-if="isDragOver">Drop here</p>
      </DropzoneBackground>

      <Controls position="top-left" class="flex flex-row gap-3">
        <ControlButton title="Reset Transform" @click="resetTransform">
          <svg width="16" height="16" viewBox="0 0 32 32">
            <path d="M18 28A12 12 0 1 0 6 16v6.2l-3.6-3.6L1 20l6 6l6-6l-1.4-1.4L8 22.2V16a10 10 0 1 1 10 10Z" />
          </svg>
        </ControlButton>

        <ControlButton title="Save Flow" @click="saveFlow" class="dark:text-white">
          Save
        </ControlButton>
      </Controls>
    </VueFlow>

    <Sidebar :questions="surveyQuestions" />
  </div>
</template>

<style scoped>
.dnd-flow {
  flex-direction: column;
  display: flex;
  height: 100%
}

@media screen and (min-width: 640px) {
  .dnd-flow {
    flex-direction: row
  }
}
</style>