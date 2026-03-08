<script setup>
import { useCustomMutation } from '@duxweb/dvha-core'
import { createDynamicFlowNodes, DuxFlowEditor } from '@duxweb/dvha-pro'
import { whenever } from '@vueuse/core'
import { NInput, NInputNumber, NSwitch, useMessage } from 'naive-ui'
import { computed, h, markRaw, onMounted, ref, shallowRef, watch, watchEffect } from 'vue'
import { useRoute } from 'vue-router'
import SchemaTreeField from './Comp/SchemaTreeField.vue'
import StableFieldConfig from './Comp/StableFieldConfig.vue'
import FieldConfigModalCard from './Comp/FieldConfigModalCard.vue'
import OutputFieldPreviewCard from './Comp/OutputFieldPreviewCard.vue'
import PromptModalCard from './Comp/PromptModalCard.vue'
import GlobalSettingNode from './GlobalSettingNode.vue'
import { buildGlobalSettingsPayload, createDefaultGlobalSettings, normalizeGlobalSettings, resolveGlobalSettingsFromDetail } from './Lib/globalSetting.js'

const route = useRoute()
const id = computed(() => route.params.id)
const message = useMessage()
const requestClient = useCustomMutation()
const optionClient = useCustomMutation()

function createEmptyFlowState() {
  return {
    nodes: [],
    edges: [],
    globalSettings: createDefaultGlobalSettings(),
  }
}

const flowState = shallowRef(createEmptyFlowState())
const globalSettingsState = ref(createDefaultGlobalSettings())

const optionState = shallowRef({
  providers: [],
  models: [],
  knowledges: [],
  parseProviders: [],
  functions: [],
  nodes: [],
})

function isNeuronFlowV1(payload) {
  if (!payload || typeof payload !== 'object')
    return false
  return Number(payload.schema_version || 0) === 1 && String(payload.engine || '') === 'neuron-ai'
}

function toEditorNode(node, index) {
  const id = String(node?.id || `__node_${index}`)
  const type = String(node?.type || 'process')
  const name = String(node?.name || node?.label || id)
  const config = (node?.config && typeof node.config === 'object') ? node.config : {}
  const uiPos = node?.ui?.position || {}
  const x = Number(uiPos?.x || 0)
  const y = Number(uiPos?.y || 0)

  return {
    id,
    type,
    position: { x, y },
    data: {
      label: name,
      config,
    },
  }
}

function toSchemaV1Node(node, index) {
  const id = String(node?.id || `__node_${index}`)
  const type = String(node?.type || 'process')

  const data = (node?.data && typeof node.data === 'object') ? node.data : {}
  const name = String(data?.label || node?.name || node?.label || id)

  const config = (data?.config && typeof data.config === 'object')
    ? data.config
    : ((node?.config && typeof node.config === 'object') ? node.config : {})

  // 清理前端编辑用的临时字段，避免污染存储（例如 StableFieldConfig 生成的 __key）。
  const nextConfig = { ...config }
  if (nextConfig.fields && typeof nextConfig.fields === 'object' && Array.isArray(nextConfig.fields.items)) {
    // fields 只保留 json items（不再支持 mode/text/code 等模式字段）
    nextConfig.fields = {
      items: nextConfig.fields.items.map((item) => {
        if (!item || typeof item !== 'object')
          return item
        const { __key, ...rest } = item
        return rest
      }),
    }
  }
  const position = node?.position || {}
  const uiPos = node?.ui?.position || {}
  const x = Number((position?.x ?? uiPos?.x) ?? 0)
  const y = Number((position?.y ?? uiPos?.y) ?? 0)

  const order = Number(data?.order ?? node?.order ?? x ?? index)

  return {
    id,
    type,
    name,
    order,
    config: nextConfig,
    ui: {
      position: { x, y },
    },
  }
}

function buildFlowNodes(options = {}) {
  const definitions = Array.isArray(options.nodes) ? options.nodes : []
  const modelLabelMap = new Map()
  const modelVideoCapabilityMap = new Map()
  ;(Array.isArray(options.models) ? options.models : []).forEach((item) => {
    const label = String(item?.label || '').trim()
    if (!label) {
      return
    }
    if (item?.id !== undefined && item?.id !== null && item.id !== '') {
      modelLabelMap.set(String(item.id), label)
    }
    if (item?.value !== undefined && item?.value !== null && item.value !== '') {
      modelLabelMap.set(String(item.value), label)
    }
    if (item?.code !== undefined && item?.code !== null && item.code !== '') {
      modelLabelMap.set(String(item.code), label)
    }

    const videoCapabilities = item?.meta?.video_capabilities && typeof item.meta.video_capabilities === 'object'
      ? item.meta.video_capabilities
      : (item?.video_capabilities && typeof item.video_capabilities === 'object' ? item.video_capabilities : null)
    if (videoCapabilities) {
      if (item?.id !== undefined && item?.id !== null && item.id !== '') {
        modelVideoCapabilityMap.set(String(item.id), videoCapabilities)
      }
      if (item?.value !== undefined && item?.value !== null && item.value !== '') {
        modelVideoCapabilityMap.set(String(item.value), videoCapabilities)
      }
      if (item?.code !== undefined && item?.code !== null && item.code !== '') {
        modelVideoCapabilityMap.set(String(item.code), videoCapabilities)
      }
    }
  })
  const knowledgeLabelMap = new Map()
  ;(Array.isArray(options.knowledges) ? options.knowledges : []).forEach((item) => {
    const label = String(item?.label || '').trim()
    if (!label) {
      return
    }
    if (item?.value !== undefined && item?.value !== null && item.value !== '') {
      knowledgeLabelMap.set(String(item.value), label)
    }
    if (item?.id !== undefined && item?.id !== null && item.id !== '') {
      knowledgeLabelMap.set(String(item.id), label)
    }
    if (item?.code !== undefined && item?.code !== null && item.code !== '') {
      knowledgeLabelMap.set(String(item.code), label)
    }
  })
  const parseProviderLabelMap = new Map()
  ;(Array.isArray(options.parseProviders) ? options.parseProviders : []).forEach((item) => {
    const label = String(item?.label || item?.name || '').trim()
    if (!label) {
      return
    }
    if (item?.value !== undefined && item?.value !== null && item.value !== '') {
      parseProviderLabelMap.set(String(item.value), label)
    }
    if (item?.id !== undefined && item?.id !== null && item.id !== '') {
      parseProviderLabelMap.set(String(item.id), label)
    }
    if (item?.code !== undefined && item?.code !== null && item.code !== '') {
      parseProviderLabelMap.set(String(item.code), label)
    }
    if (item?.name !== undefined && item?.name !== null && item.name !== '') {
      parseProviderLabelMap.set(String(item.name), label)
    }
  })

  const videoFieldCapabilityKeyMap = {
    image_url: 'image_url',
    frames: 'frames',
    seed: 'seed',
    return_last_frame: 'return_last_frame',
  }

  function isTruthyFlag(value, fallback = true) {
    if (value === undefined || value === null || value === '') {
      return fallback
    }
    if (typeof value === 'boolean') {
      return value
    }
    if (typeof value === 'number') {
      return value > 0
    }
    const normalized = String(value).trim().toLowerCase()
    if (!normalized) {
      return fallback
    }
    if (['true', '1', 'yes', 'on', 'enabled'].includes(normalized)) {
      return true
    }
    if (['false', '0', 'no', 'off', 'disabled'].includes(normalized)) {
      return false
    }
    return fallback
  }

  function getVideoCapabilityFlag(capabilities, key) {
    if (!capabilities || typeof capabilities !== 'object') {
      return true
    }
    const aliasKeys = [
      key,
      `supports_${key}`,
      `enable_${key}`,
      `allow_${key}`,
      key.replace(/_([a-z])/g, (_, s1) => s1.toUpperCase()),
      `supports${key.replace(/(^|_)([a-z])/g, (_, __, s2) => s2.toUpperCase())}`,
    ]
    for (const alias of aliasKeys) {
      if (Object.prototype.hasOwnProperty.call(capabilities, alias)) {
        return isTruthyFlag(capabilities[alias], true)
      }
    }
    return true
  }

  function shouldShowVideoField(fieldName, modelValue) {
    const capabilityKey = videoFieldCapabilityKeyMap[fieldName]
    if (!capabilityKey) {
      return true
    }
    const modelKey = String(modelValue || '').trim()
    if (!modelKey) {
      return true
    }
    const capabilities = modelVideoCapabilityMap.get(modelKey)
    return getVideoCapabilityFlag(capabilities, capabilityKey)
  }

  const enhanced = definitions.map((def) => {
    if (!def || typeof def !== 'object')
      return def

    const next = { ...def }
    const settingFields = Array.isArray(next.settingFields) ? [...next.settingFields] : []

    // 修复 dvha-pro 内置 field-config：其 v-for key 依赖 field.name，导致编辑字段名时每输入一个字符都会触发重新挂载而丢失焦点。
    // 这里用本地 StableFieldConfig 替换，key 使用稳定 __key。
    for (let i = 0; i < settingFields.length; i += 1) {
      const field = settingFields[i]
      if (!field || field.component !== 'field-config')
        continue
      const componentProps = field.componentProps || {}
      const useModalCard = next.type === 'ai_start' && String(field.name || '') === 'fields'
      settingFields[i] = {
        ...field,
        preview: false,
        render: ({ value, update }) => h(useModalCard ? FieldConfigModalCard : StableFieldConfig, {
          'modelValue': value,
          'onUpdate:modelValue': val => update(val),
          ...componentProps,
        }),
      }
    }

    for (let i = 0; i < settingFields.length; i += 1) {
      const field = settingFields[i]
      if (!field || field.component !== 'prompt-editor')
        continue
      const title = String(field.label || field.name || '提示词')
      settingFields[i] = {
        ...field,
        preview: false,
        render: ({ value, update }) => h(PromptModalCard, {
          'modelValue': String(value || ''),
          'title': title,
          'emptyText': '未设置内容',
          'placeholder': `请输入${title}（支持 Markdown）`,
          'onUpdate:modelValue': val => update(val),
        }),
      }
    }

    for (let i = 0; i < settingFields.length; i += 1) {
      const field = settingFields[i]
      if (!field || field.component !== 'schema-tree')
        continue
      const componentProps = field.componentProps || {}
      settingFields[i] = {
        ...field,
        preview: false,
        render: ({ value, update, data }) => {
          const modeField = String(componentProps.modeField || '')
          const disabledMode = String(componentProps.disabledMode || '')
          const disabledText = String(componentProps.disabledText || '当前模式未启用 Schema 配置')
          if (modeField && disabledMode) {
            const mode = String(data?.config?.[modeField] || '')
            if (mode === disabledMode) {
              return h('div', { class: 'text-sm text-muted' }, disabledText)
            }
          }
          return h(SchemaTreeField, {
            'modelValue': value,
            'onUpdate:modelValue': val => update(val),
          })
        },
      }
    }

    for (let i = 0; i < settingFields.length; i += 1) {
      const field = settingFields[i]
      if (!field || field.component !== 'dux-select')
        continue
      const path = String(field?.componentProps?.path || '')
      if (path !== 'ai/flow/modelOptions' && path !== 'ai/ragKnowledge/options' && path !== 'ai/parseProvider')
        continue
      settingFields[i] = {
        ...field,
        preview: {
          ...(field?.preview && typeof field.preview === 'object' ? field.preview : {}),
          formatter: ({ value }) => {
            const labelMap
              = path === 'ai/ragKnowledge/options'
                ? knowledgeLabelMap
                : path === 'ai/parseProvider'
                  ? parseProviderLabelMap
                  : modelLabelMap
            if (Array.isArray(value)) {
              const names = value
                .map(v => labelMap.get(String(v)) || String(v || '').trim())
                .filter(Boolean)
              return names.join('、')
            }
            const key = String(value || '').trim()
            if (!key) {
              return '-'
            }
            return labelMap.get(key) || key
          },
        },
      }
    }

    if (next.type === 'video_generate') {
      for (let i = 0; i < settingFields.length; i += 1) {
        const field = settingFields[i]
        const fieldName = String(field?.name || '')
        if (!field || !fieldName || !Object.prototype.hasOwnProperty.call(videoFieldCapabilityKeyMap, fieldName)) {
          continue
        }
        const originalRender = field.render
        const component = String(field.component || 'text')
        const componentProps = field.componentProps || {}

        settingFields[i] = {
          ...field,
          render: (context) => {
            const modelValue = context?.data?.config?.model_id
            if (!shouldShowVideoField(fieldName, modelValue)) {
              return null
            }
            if (typeof originalRender === 'function') {
              return originalRender(context)
            }

            const { value, update } = context
            if (component === 'number') {
              const parsed = value === '' || value === undefined || value === null ? null : Number(value)
              return h(NInputNumber, {
                ...componentProps,
                value: Number.isFinite(parsed) ? parsed : null,
                'onUpdate:value': val => update(val === null || val === undefined ? null : Number(val)),
              })
            }
            if (component === 'switch') {
              return h(NSwitch, {
                ...componentProps,
                value: Boolean(value),
                'onUpdate:value': val => update(Boolean(val)),
              })
            }
            return h(NInput, {
              ...componentProps,
              value: value === undefined || value === null ? '' : String(value),
              type: component === 'textarea' ? 'textarea' : 'text',
              'onUpdate:value': val => update(String(val || '')),
            })
          },
        }
      }
    }

    // 所有节点：注入节点超时与重试配置（写入 config.timeout_ms / config.retry.max_attempts）。
    // start/end 节点也允许配置，但默认不必填。
    settingFields.unshift(
      {
        name: 'timeout_ms',
        label: '超时（ms）',
        description: '节点执行超时，0 表示不设置（使用全局默认或节点自身逻辑）',
        preview: false,
        render: ({ value, update }) => h(NInputNumber, {
          'value': typeof value === 'number' ? value : Number(value || 0),
          'min': 0,
          'step': 500,
          'placeholder': '例如：30000',
          'onUpdate:value': val => update(Number(val || 0)),
        }),
      },
      {
        name: 'retry',
        label: '重试次数',
        description: '节点失败时最多重试次数（含首次执行）',
        preview: false,
        render: ({ value, update }) => h(NInputNumber, {
          'value': typeof value?.max_attempts === 'number' ? value.max_attempts : Number(value?.max_attempts || 1),
          'min': 1,
          'max': 10,
          'placeholder': '例如：2',
          'onUpdate:value': val => update({ max_attempts: Number(val || 1) }),
        }),
      },
    )

    // start/end 节点不需要配置入参结构。
    if (next.type !== 'ai_start' && next.type !== 'ai_end') {
      settingFields.unshift({
        name: 'schema',
        label: '入参配置',
        description: '支持默认值/模板，如 {{input.xxx}}',
        preview: false,
        render: ({ value, update }) => h(SchemaTreeField, {
          'modelValue': value,
          'schema': next.schema,
          'onUpdate:modelValue': val => update(val),
        }),
      })
    }

    const outputFields = Array.isArray(next.output?.fields) ? next.output.fields : []
    const defaultOutputFields = outputFields
      .filter(field => String(field?.name || '') !== 'mode_used')
      .map(field => ({
        name: String(field?.name || '').trim(),
        label: String(field?.label || '').trim(),
        type: String(field?.type || 'text').toLowerCase(),
        required: Boolean(field?.required),
      }))
    const hasStructuredSchemaField = settingFields.some(field => String(field?.name || '') === 'structured_schema')
    const showOutputPreview = next.showOutputPreview !== false
    const hideOutputPreviewTypes = ['ai_start', 'ai_end']
    if (showOutputPreview && !hideOutputPreviewTypes.includes(String(next.type || '')) && !hasStructuredSchemaField && outputFields.length > 0) {
      settingFields.push({
        name: '__output_preview',
        label: '输出字段',
        preview: false,
        render: () => h(OutputFieldPreviewCard, {
          fields: defaultOutputFields,
          emptyText: '未声明输出字段',
          source: 'default',
          outputRef: '{{nodes.<节点ID>.output.xxx}}',
          inputRef: '{{input.xxx}}',
        }),
      })
    }

    next.settingFields = settingFields
    return next
  })
  return createDynamicFlowNodes(enhanced)
}

const categories = [
  { key: 'base', label: '输入&输出', icon: 'i-tabler:player-play' },
  { key: 'ai', label: 'AI 处理', icon: 'i-tabler:ai' },
  { key: 'integration', label: '集成', icon: 'i-tabler:plug-connected' },
]

const editorConfig = {
  readonly: false,
  showGrid: true,
  showControls: true,
}

async function loadOptions() {
  const [res, knowledgesRes, parseProviderRes] = await Promise.all([
    optionClient.mutateAsync({
      path: 'ai/flow/options',
      method: 'GET',
    }),
    optionClient.mutateAsync({
      path: 'ai/ragKnowledge/options',
      method: 'GET',
    }),
    optionClient.mutateAsync({
      path: 'ai/parseProvider?limit=500',
      method: 'GET',
    }),
  ])
  const parseProviderList = Array.isArray(parseProviderRes.data)
    ? parseProviderRes.data
    : (
        Array.isArray(parseProviderRes.data?.items)
          ? parseProviderRes.data.items
          : (
              Array.isArray(parseProviderRes.data?.list)
                ? parseProviderRes.data.list
                : (Array.isArray(parseProviderRes.data?.data?.items) ? parseProviderRes.data.data.items : [])
            )
      )
  optionState.value = {
    providers: res.data?.providers || [],
    models: res.data?.models || [],
    knowledges: Array.isArray(knowledgesRes.data) ? knowledgesRes.data : [],
    parseProviders: parseProviderList.map(item => ({
      id: item?.id,
      value: item?.id,
      code: item?.code,
      name: item?.name || item?.label || String(item?.id || ''),
      label: item?.name || item?.label || String(item?.id || ''),
    })),
    functions: res.data?.functions || [],
    nodes: Array.isArray(res.data?.nodes) ? res.data.nodes : [],
  }
}

async function loadDetail() {
  if (!id.value)
    return
  const res = await requestClient.mutateAsync({
    path: `ai/flow/${id.value}`,
    method: 'GET',
  })
  const data = res.data || {}
  const flowPayload = (data.flow && typeof data.flow === 'object') ? data.flow : {}
  const rawNodes = Array.isArray(flowPayload.nodes) ? flowPayload.nodes : []
  const nodes = isNeuronFlowV1(flowPayload)
    ? rawNodes.map((node, index) => toEditorNode(node, index))
    : rawNodes
  flowState.value = {
    nodes,
    edges: Array.isArray(flowPayload.edges) ? flowPayload.edges : [],
    globalSettings: resolveGlobalSettingsFromDetail(data),
  }
  globalSettingsState.value = flowState.value.globalSettings
}

onMounted(() => {
  loadOptions()
})

whenever(id, () => {
  if (id.value)
    loadDetail()
}, { immediate: true })

const customNodes = shallowRef(markRaw(buildFlowNodes(optionState.value)))

watchEffect(() => {
  customNodes.value = markRaw(buildFlowNodes(optionState.value))
})

function ensureNodeDescriptions() {
  const flow = flowState.value
  if (!flow || !Array.isArray(flow.nodes) || flow.nodes.length === 0)
    return

  const definitions = Array.isArray(optionState.value?.nodes) ? optionState.value.nodes : []
  if (definitions.length === 0)
    return

  const descriptionMap = new Map(
    definitions
      .filter(def => def && typeof def === 'object')
      .map(def => [String(def.type || ''), String(def.description || '')]),
  )

  let changed = false
  const nextNodes = flow.nodes.map((node) => {
    if (!node || typeof node !== 'object')
      return node

    const desc = String(node?.data?.description || '')
    if (desc.trim())
      return node

    const type = String(node.type || '')
    const defaultDesc = String(descriptionMap.get(type) || '')
    if (!defaultDesc.trim())
      return node

    changed = true
    return {
      ...node,
      data: {
        ...(node.data || {}),
        description: defaultDesc,
      },
    }
  })

  if (changed) {
    flowState.value = {
      ...flow,
      nodes: nextNodes,
    }
  }
}

watch([() => optionState.value?.nodes, () => flowState.value?.nodes], () => {
  ensureNodeDescriptions()
}, { immediate: true, deep: false })

function cloneGlobalSettings(value) {
  return {
    ...value,
    variables: Array.isArray(value.variables) ? value.variables.map(item => ({ ...item })) : [],
  }
}

function applyGlobalSettingsPatch(patch) {
  const next = normalizeGlobalSettings({
    ...globalSettingsState.value,
    ...patch,
  })
  globalSettingsState.value = next
  if (flowState.value)
    flowState.value.globalSettings = cloneGlobalSettings(next)
  return next
}

function handleGlobalSettingsField(patch, updater) {
  const next = applyGlobalSettingsPatch(patch)
  updater?.(next)
}

function hasGlobalSettingsPayload(settings) {
  if (!settings || typeof settings !== 'object')
    return false
  return Object.keys(settings).length > 0
}

function isGlobalSettingsEqual(a, b) {
  if (a === b)
    return true
  if (!a || !b)
    return false
  if (
    a.name !== b.name
    || a.code !== b.code
    || a.description !== b.description
    || Boolean(a.status) !== Boolean(b.status)
  ) {
    return false
  }
  const varsA = Array.isArray(a.variables) ? a.variables : []
  const varsB = Array.isArray(b.variables) ? b.variables : []
  if (varsA.length !== varsB.length)
    return false
  for (let i = 0; i < varsA.length; i += 1) {
    const itemA = varsA[i] || {}
    const itemB = varsB[i] || {}
    if (
      itemA.name !== itemB.name
      || itemA.description !== itemB.description
      || itemA.value !== itemB.value
    ) {
      return false
    }
  }
  return true
}

function ensureFlowGlobalSettings() {
  if (!flowState.value)
    return
  flowState.value.globalSettings = cloneGlobalSettings(globalSettingsState.value)
}

watch(
  () => flowState.value?.globalSettings,
  (incoming) => {
    if (hasGlobalSettingsPayload(incoming)) {
      const normalized = normalizeGlobalSettings(incoming)
      if (!isGlobalSettingsEqual(normalized, globalSettingsState.value))
        globalSettingsState.value = normalized
    }
    else {
      ensureFlowGlobalSettings()
    }
  },
  { immediate: true },
)

async function handleSave(nextFlow) {
  if (!id.value) {
    message?.error?.('请先在列表中创建流程')
    return
  }
  const sourceFlow = nextFlow || flowState.value
  const storageGlobalSettings = buildGlobalSettingsPayload(globalSettingsState.value)
  const sourceNodes = Array.isArray(sourceFlow?.nodes) ? sourceFlow.nodes : []
  const sourceEdges = Array.isArray(sourceFlow?.edges) ? sourceFlow.edges : []

  const payloadFlow = {
    schema_version: 1,
    engine: 'neuron-ai',
    nodes: sourceNodes.map((node, index) => toSchemaV1Node(node, index)),
    edges: sourceEdges,
    globalSettings: storageGlobalSettings,
  }
  const baseInfo = {
    name: globalSettingsState.value.name,
    code: globalSettingsState.value.code,
    description: globalSettingsState.value.description,
    status: globalSettingsState.value.status,
  }
  await requestClient.mutateAsync({
    path: `ai/flow/${id.value}/flow`,
    method: 'PUT',
    payload: {
      flow: payloadFlow,
      global_settings: storageGlobalSettings,
      ...baseInfo,
    },
  })
  message?.success?.('保存成功')
}
</script>

<template>
  <DuxFlowEditor
    v-model:value="flowState"
    :custom-nodes="customNodes"
    :categories="categories"
    :config="editorConfig"
    :on-save="handleSave"
  >
    <template #globalSettings="{ globalSettings, updateGlobalSettings }">
      <GlobalSettingNode
        :value="globalSettings"
        :fallback="globalSettingsState"
        @patch="patch => handleGlobalSettingsField(patch, updateGlobalSettings)"
      />
    </template>
  </DuxFlowEditor>
</template>

<style scoped></style>
