<script setup lang="ts">
import { useCustomMutation, useGetAuth, useManage } from '@duxweb/dvha-core'
import { DuxPage, useModal } from '@duxweb/dvha-pro'
import { fetchEventSource } from '@microsoft/fetch-event-source'
import { marked } from 'marked'
import { NButton, NCode, NEmpty, NSpin, NTag, useMessage } from 'naive-ui'
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

interface FlowField {
  name: string
  label: string
  type: string
  required?: boolean
  description?: string
  defaultValue: any
}

interface NodeStatus {
  status: 'pending' | 'running' | 'success' | 'error' | 'skipped'
  message?: string
  input?: any
  output?: any
}

interface ConversationItem {
  role: 'system' | 'user' | 'assistant' | 'tool'
  content: string
  name?: string
  error?: boolean
  createdAt?: number
}

const route = useRoute()
const router = useRouter()
const manage = useManage()
const requestClient = useCustomMutation()
const auth = useGetAuth()
const message = useMessage()
const modal = useModal()

const flowId = computed(() => {
  const paramVal = route.params?.id
  return Array.isArray(paramVal) ? paramVal[0] : paramVal
})

const loadingDetail = ref(false)
const executing = ref(false)
const flowDetail = ref<Record<string, any> | null>(null)
const streamControllerRef = ref<AbortController | null>(null)
const nodeStatusMap = reactive<Record<string, NodeStatus>>({})
const activeNodeId = ref('')
const orderedNodeList = ref<any[]>([])
const orderedNodes = computed(() => orderedNodeList.value ?? [])
const completionReceived = ref(false)
const executionFailed = ref(false)
const persistedRunStatus = ref('')
const showMobileNodes = ref(false)
const runStatusPollTimer = ref<number | null>(null)
const runtimeRunId = ref('')
const runtimeWorkflowId = ref('')

const executionStatus = computed(() => {
  if (executing.value) {
    return {
      label: '执行中',
      icon: 'i-tabler:loader-2',
      iconClass: 'text-warning animate-spin',
      cardClass: 'border-warning/25 bg-warning/5 text-warning',
    }
  }
  if (persistedRunStatus.value === 'running') {
    return {
      label: '后台执行中',
      icon: 'i-tabler:player-track-next',
      iconClass: 'text-warning',
      cardClass: 'border-warning/25 bg-warning/5 text-warning',
    }
  }
  if (persistedRunStatus.value === 'resuming') {
    return {
      label: '后台恢复中',
      icon: 'i-tabler:loader-2',
      iconClass: 'text-warning animate-spin',
      cardClass: 'border-warning/25 bg-warning/5 text-warning',
    }
  }
  if (persistedRunStatus.value === 'suspended') {
    return {
      label: '后台等待中',
      icon: 'i-tabler:player-pause',
      iconClass: 'text-warning',
      cardClass: 'border-warning/25 bg-warning/5 text-warning',
    }
  }
  if (persistedRunStatus.value === 'success' || completionReceived.value) {
    return {
      label: '已完成',
      icon: 'i-tabler:check',
      iconClass: 'text-primary',
      cardClass: 'border-primary/25 bg-primary/5 text-primary',
    }
  }
  if (persistedRunStatus.value === 'failed' || persistedRunStatus.value === 'canceled' || executionFailed.value) {
    return {
      label: persistedRunStatus.value === 'canceled' ? '已取消' : '执行失败',
      icon: 'i-tabler:x',
      iconClass: 'text-error',
      cardClass: 'border-error/25 bg-error/5 text-error',
    }
  }
  return {
    label: '准备就绪',
    icon: 'i-tabler:circle-dot',
    iconClass: 'text-muted',
    cardClass: 'border-muted/60 bg-white/60 dark:bg-gray-950/30 text-muted',
  }
})
let lastStreamErrorMessage = ''
let lastStreamErrorNode = ''
let lastStreamSignature = ''

const startNodeFields = computed<FlowField[]>(() => {
  const orderedStart = (orderedNodes.value ?? []).find((node: any) => node?.type === 'ai_start')
  const detailNodes = flowDetail.value?.flow?.nodes ?? []
  const detailStart = detailNodes.find((node: any) => node?.type === 'ai_start')

  const candidate = orderedStart || detailStart
  if (!candidate) {
    return []
  }

  return normalizeStartFields(candidate?.config?.fields)
})
const hasInputFields = computed(() => startNodeFields.value.length > 0)

const form = reactive<Record<string, any>>({})
const fallbackJson = ref('')
const inputSummary = computed(() => {
  if (hasInputFields.value) {
    return `已配置字段 · ${startNodeFields.value.length} 项`
  }
  return fallbackJson.value.trim() ? '已配置 JSON 输入' : '未配置 JSON 输入'
})

const conversationLogs = ref<ConversationItem[]>([])
const visibleConversationLogs = computed(() =>
  conversationLogs.value.filter(item => item?.role !== 'system'),
)
const nodeStatusEnabled = ref(true)

const flowName = computed(() => flowDetail.value?.name ?? (flowId.value ? `流程 #${flowId.value}` : '未知流程'))
const flowCode = computed(() => flowDetail.value?.code ?? String(flowId.value ?? ''))
const routeRunId = computed(() => {
  const value = route.query?.run_id
  if (Array.isArray(value))
    return value[0] || ''
  return typeof value === 'string' ? value : ''
})
const activeRunId = computed(() => routeRunId.value || runtimeRunId.value)
const isHistoryMode = computed(() => !!routeRunId.value)
const showInputAction = computed(() =>
  !isHistoryMode.value
  && !executing.value
  && !completionReceived.value
  && !['running', 'suspended'].includes(persistedRunStatus.value),
)
const isBackgroundProcessing = computed(() =>
  isHistoryMode.value && ['running', 'suspended', 'resuming'].includes(persistedRunStatus.value),
)
const backgroundStatusHint = computed(() => {
  if (!isBackgroundProcessing.value) {
    return ''
  }
  if (persistedRunStatus.value === 'suspended') {
    return '流程已挂起等待异步任务，调度器将完成后自动恢复并继续执行。'
  }
  if (persistedRunStatus.value === 'resuming') {
    return '调度器正在恢复流程，后续节点将在后台继续执行。'
  }
  return '流程正在后台执行，页面将每 5 秒自动刷新状态。'
})

function normalizeOrderedNode(node: any) {
  if (!node || typeof node !== 'object')
    return node

  const id = String(node.id ?? '')
  const name = String(node.name ?? node.label ?? node.data?.name ?? node.data?.label ?? id)
  const description = String(node.description ?? node.data?.description ?? '')

  const data = (node.data && typeof node.data === 'object') ? { ...node.data } : {}
  data.name = data.name ?? name
  data.label = data.label ?? name
  data.description = data.description ?? description

  return {
    ...node,
    id,
    name,
    label: node.label ?? name,
    description,
    data,
  }
}

function extractFieldConfigItems(value: any) {
  if (!value)
    return []
  if (Array.isArray(value))
    return value
  if (typeof value === 'object' && Array.isArray(value.items))
    return value.items
  return []
}

function normalizeStartFields(value: any): FlowField[] {
  const items = extractFieldConfigItems(value)
  return items
    .map((item: any, index: number) => {
      const name = (item?.name || `field_${index + 1}`).trim()
      if (!name)
        return null
      const label = item?.label || name
      return {
        name,
        label,
        type: item?.type || 'text',
        required: !!item?.required,
        description: item?.description || '',
        defaultValue: typeof item?.content !== 'undefined' ? item.content : '',
      } as FlowField
    })
    .filter((field): field is FlowField => !!field)
}

function resolveDefaultValue(type?: string) {
  switch (type) {
    case 'number':
      return null
    case 'boolean':
      return false
    case 'image':
    case 'file':
      return null
    case 'images':
    case 'files':
      return []
    case 'json':
    case 'text':
    case 'textarea':
    case 'date':
    default:
      return ''
  }
}

function resolveFieldDefaultValue(field: FlowField) {
  const value = field.defaultValue
  const isEmptyString = typeof value === 'string' && value === ''
  if (value === undefined || value === null || (isEmptyString && field.type !== 'text' && field.type !== 'textarea' && field.type !== 'json')) {
    return resolveDefaultValue(field.type)
  }

  switch (field.type) {
    case 'number': {
      const num = Number(value)
      return Number.isNaN(num) ? null : num
    }
    case 'boolean': {
      if (typeof value === 'boolean')
        return value
      if (typeof value === 'string')
        return value === 'true' || value === '1'
      return Boolean(value)
    }
    case 'images':
    case 'files':
      return Array.isArray(value) ? value : []
    case 'image':
    case 'file':
      return value || null
    default:
      return value
  }
}

function resetForm(fields: FlowField[]) {
  Object.keys(form).forEach((key) => {
    delete form[key]
  })

  fields.forEach((field: FlowField) => {
    form[field.name] = resolveFieldDefaultValue(field)
  })
}

async function loadDetail() {
  if (!flowId.value) {
    message.error('流程标识不存在，无法加载详情')
    return
  }
  loadingDetail.value = true
  try {
    const res = await requestClient.mutateAsync({
      path: `ai/flow/${flowId.value}`,
      method: 'GET',
    })
    flowDetail.value = res.data
    await loadOrderedNodes()
  }
  catch (error: any) {
    orderedNodeList.value = []
    message.error(error?.message ?? '加载流程详情失败')
  }
  finally {
    loadingDetail.value = false
  }
}

function parseMessageTimestamp(raw: any, fallback: number): number {
  if (typeof raw === 'number' && Number.isFinite(raw)) {
    return raw > 1e12 ? raw : raw * 1000
  }

  if (typeof raw === 'string') {
    const text = raw.trim()
    if (!text) {
      return fallback
    }

    if (/^\d+$/.test(text)) {
      const num = Number(text)
      if (Number.isFinite(num)) {
        return num > 1e12 ? num : num * 1000
      }
    }

    const match = text.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/)
    if (match) {
      const [, y, m, d, hh, mm, ss = '0'] = match
      return new Date(
        Number(y),
        Number(m) - 1,
        Number(d),
        Number(hh),
        Number(mm),
        Number(ss),
      ).getTime()
    }
  }

  return fallback
}

function buildConversationFromNodeLogs(logs: any[], snapshot: any): ConversationItem[] {
  if (!Array.isArray(logs) || logs.length === 0) {
    return []
  }

  const base = parseMessageTimestamp(snapshot?.log?.created_at, Date.now())
  const items: ConversationItem[] = []

  logs.forEach((item: any, index: number) => {
    const meta = (item?.meta && typeof item.meta === 'object') ? item.meta : {}
    const nodeType = String(meta?.type || '')
    const nodeLabel = String(meta?.label || meta?.node || `节点 ${index + 1}`)
    const nodeStatus = typeof meta?.node_status === 'number' ? meta.node_status : 1
    const createdRaw = item?.created_at_ms ?? item?.created_at
    const createdAt = parseMessageTimestamp(createdRaw, base + index * 2)

    if (nodeType === 'ai_start') {
      const userContent = formatMessageContent(item?.data) || formatMessageContent(meta?.input)
      if (userContent?.trim()) {
        items.push({
          role: 'user',
          content: userContent,
          createdAt,
        })
      }
      return
    }

    let content = formatMessageContent(item?.data)
    if (!content?.trim()) {
      const nodeMessage = typeof meta?.node_message === 'string' ? meta.node_message : ''
      if (nodeMessage && nodeMessage !== 'ok') {
        content = nodeMessage
      }
    }
    if (!content?.trim() && nodeStatus === 0) {
      const failedMessage = typeof item?.message === 'string' ? item.message : ''
      content = failedMessage || '执行失败'
    }
    if (!content?.trim()) {
      return
    }

    items.push({
      role: 'assistant',
      name: nodeLabel,
      content,
      error: nodeStatus === 0,
      createdAt: createdAt + 1,
    })
  })

  if (items.length === 0) {
    const fallbackMessage = typeof snapshot?.log?.message === 'string' ? snapshot.log.message.trim() : ''
    if (fallbackMessage && fallbackMessage !== 'ok') {
      items.push({
        role: 'assistant',
        name: '流程',
        content: fallbackMessage,
        error: snapshot?.log?.status === 0,
        createdAt: parseMessageTimestamp(snapshot?.log?.created_at, base),
      })
    }
  }

  return items
}

function hydrateNodeLogs(logs: any[]) {
  if (!Array.isArray(logs)) {
    return
  }
  resetNodeStatuses()
  let lastNodeId = ''
  logs.forEach((item: any) => {
    const nodeId = String(item?.meta?.node ?? item?.meta?.node_id ?? '').trim()
    if (!nodeId) {
      return
    }
    applyNodeMetaToStatus(nodeId, {
      meta: item?.meta ?? {},
      data: item?.data ?? null,
    })
    lastNodeId = nodeId
  })
  if (lastNodeId) {
    activeNodeId.value = lastNodeId
  }
}

function normalizeRunStatus(snapshot: any): string {
  const runStatus = String(snapshot?.status || '').trim()
  if (runStatus) {
    return runStatus
  }
  const logStatus = snapshot?.log?.status
  if (logStatus === 1) {
    return 'success'
  }
  if (logStatus === 0) {
    return 'failed'
  }
  return ''
}

async function loadRunSnapshot(runId: string) {
  if (!runId) {
    persistedRunStatus.value = ''
    conversationLogs.value = []
    resetNodeStatuses()
    return
  }

  try {
    const res = await requestClient.mutateAsync({
      path: `ai/flowRun/${encodeURIComponent(runId)}/snapshot`,
      method: 'GET',
    })
    const snapshot = res?.data ?? {}
    conversationLogs.value = []
    resetNodeStatuses()
    persistedRunStatus.value = normalizeRunStatus(snapshot)
    executing.value = false

    if (persistedRunStatus.value === 'success') {
      completionReceived.value = true
      executionFailed.value = false
    }
    else if (persistedRunStatus.value === 'failed' || persistedRunStatus.value === 'canceled') {
      completionReceived.value = true
      executionFailed.value = true
    }
    else {
      completionReceived.value = false
      executionFailed.value = false
    }

    const nodeLogs = Array.isArray(snapshot?.log?.logs) ? snapshot.log.logs : []
    hydrateNodeLogs(nodeLogs)
    conversationLogs.value = buildConversationFromNodeLogs(nodeLogs, snapshot)
  }
  catch (error: any) {
    message.error(error?.message ?? '加载执行状态失败')
  }
}

async function resolveRunByWorkflowId(workflowId: string) {
  if (!workflowId) {
    return
  }
  try {
    const res = await requestClient.mutateAsync({
      path: `ai/flowRun/context/resolve?workflow_id=${encodeURIComponent(workflowId)}`,
      method: 'GET',
    })
    const data = res?.data
    const runId = data?.id ? String(data.id) : ''
    if (!runId) {
      return
    }
    runtimeRunId.value = runId
    if (!routeRunId.value) {
      await router.replace({
        path: route.path,
        query: {
          ...route.query,
          run_id: runId,
        },
      })
    }
    await loadRunSnapshot(runId)
    startRunStatusPolling(runId)
  }
  catch {
    // FlowRun 记录可能存在短暂写入延迟，本次忽略。
  }
}

function stopRunStatusPolling() {
  if (runStatusPollTimer.value) {
    window.clearInterval(runStatusPollTimer.value)
    runStatusPollTimer.value = null
  }
}

function startRunStatusPolling(runId: string) {
  stopRunStatusPolling()
  if (!runId) {
    return
  }
  runStatusPollTimer.value = window.setInterval(() => {
    if (!['running', 'suspended', 'resuming'].includes(persistedRunStatus.value)) {
      stopRunStatusPolling()
      return
    }
    loadRunSnapshot(runId)
  }, 5000)
}

async function loadOrderedNodes() {
  if (!flowId.value) {
    orderedNodeList.value = []
    return
  }
  try {
    const res = await requestClient.mutateAsync({
      path: `ai/flow/${flowId.value}/orderedNodes`,
      method: 'GET',
    })
    const nodes = Array.isArray(res.data?.nodes) ? res.data.nodes : []
    orderedNodeList.value = nodes.map(normalizeOrderedNode)
  }
  catch (error: any) {
    orderedNodeList.value = []
    message.error(error?.message ?? '加载节点顺序失败')
  }
}

watch(
  startNodeFields,
  (fields) => {
    resetForm(fields)
    fallbackJson.value = ''
  },
  { immediate: true },
)

watch(
  [orderedNodes, nodeStatusEnabled],
  ([nodes, enabled]) => {
    if (!enabled) {
      Object.keys(nodeStatusMap).forEach((key) => {
        delete nodeStatusMap[key]
      })
      return
    }
    const list = Array.isArray(nodes) ? nodes : []
    const idSet = new Set(list.map((node: any) => node.id))
    Object.keys(nodeStatusMap).forEach((key) => {
      if (!idSet.has(key)) {
        delete nodeStatusMap[key]
      }
    })
    list.forEach((node: any) => {
      if (!nodeStatusMap[node.id]) {
        nodeStatusMap[node.id] = createEmptyNodeStatus(node)
      }
    })
  },
  { immediate: true },
)

function parseJsonValue(value: string, label: string) {
  if (!value || !value.trim()) {
    return null
  }
  try {
    return JSON.parse(value)
  }
  catch (error) {
    message.error(`${label} 的 JSON 格式不正确`)
    throw error
  }
}

function buildInputPayload() {
  if (startNodeFields.value.length === 0) {
    const parsed = parseJsonValue(fallbackJson.value, '流程输入')
    if (parsed === null) {
      message.error('请输入流程执行所需的 JSON 输入')
      throw new Error('invalid input')
    }
    return parsed
  }

  const payload: Record<string, any> = {}
  for (const field of startNodeFields.value) {
    let value = form[field.name]
    if (field.required && isEmptyValue(value)) {
      message.error(`请填写 ${field.label}`)
      throw new Error('invalid input')
    }
    if (field.type === 'json') {
      if (typeof value === 'string' && value.trim()) {
        value = parseJsonValue(value, field.label)
      }
    }
    if (field.type === 'file' || field.type === 'image') {
      value = normalizeUploadedFileValue(value)
    }
    if (field.type === 'files' || field.type === 'images') {
      value = normalizeUploadedFileListValue(value)
    }
    payload[field.name] = value
  }

  return payload
}

function normalizeUploadedFileValue(value: any) {
  if (!value || typeof value !== 'object') {
    return value
  }
  const url = typeof value.url === 'string' ? value.url : ''
  const path = typeof value.path === 'string' ? value.path : ''
  if (!path && url) {
    return { ...value, path: url }
  }
  return value
}

function normalizeUploadedFileListValue(value: any) {
  if (!Array.isArray(value)) {
    return []
  }
  return value.map(normalizeUploadedFileValue)
}

function isEmptyValue(value: any) {
  return value === '' || value === null || value === undefined || (Array.isArray(value) && value.length === 0)
}

function cloneFormState() {
  return JSON.parse(JSON.stringify(form))
}

function applyFormState(payload: { form?: Record<string, any>, fallbackJson?: string }) {
  if (payload?.form && typeof payload.form === 'object') {
    Object.keys(form).forEach((key) => {
      delete form[key]
    })
    Object.entries(payload.form).forEach(([key, value]) => {
      form[key] = value
    })
  }
  if (typeof payload?.fallbackJson === 'string') {
    fallbackJson.value = payload.fallbackJson
  }
}

function openInputModal(executeAfter = false) {
  modal
    .show({
      title: '输入参数',
      component: () => import('./ExecuteInputModal.vue'),
      componentProps: {
        fields: startNodeFields.value,
        formData: cloneFormState(),
        fallbackJson: fallbackJson.value,
        hasInputFields: hasInputFields.value,
      },
    })
    .then((result) => {
      if (result && typeof result === 'object') {
        applyFormState(result)
        if (executeAfter) {
          handleExecute()
        }
      }
    })
    .catch(() => {})
}

async function handleExecute() {
  if (!flowCode.value) {
    message.error('流程标识不存在，无法执行')
    return
  }

  let inputPayload: Record<string, any>
  try {
    inputPayload = buildInputPayload()
  }
  catch {
    return
  }

  startStreamExecution(inputPayload)
}

async function startStreamExecution(inputPayload: Record<string, any>) {
  if (!flowCode.value) {
    return
  }
  closeStream()
  executing.value = true
  persistedRunStatus.value = ''
  runtimeRunId.value = ''
  runtimeWorkflowId.value = ''
  completionReceived.value = false
  executionFailed.value = false
  conversationLogs.value = []
  resetNodeStatuses()
  markStartNodeStatus('running', '执行中')

  const controller = new AbortController()
  streamControllerRef.value = controller
  const streamUrl = manage.getApiUrl(`ai/agent/flow/execute/${flowCode.value}/stream`)
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
  }
  if (auth?.token) {
    headers.Authorization = auth.token
  }
  let handledError = false
  const reportError = (text: string, name?: string) => handleFlowPayloadError(text, name)
  const handleChunk = (data: string) => handleUnifiedStreamChunk(data, reportError)

  fetchEventSource(streamUrl, {
    method: 'POST',
    headers,
    body: JSON.stringify({ input: inputPayload }),
    signal: controller.signal,
    onmessage(ev) {
      if (!ev.data || !ev.data.trim()) {
        return
      }
      handleChunk(ev.data)
    },
    credentials: 'include',
    onerror(err) {
      if (controller.signal.aborted) {
        return
      }
      handledError = true
      executing.value = false
      executionFailed.value = true
      appendLocalErrorToChat(err?.message ?? '实时执行失败')
      throw err
    },
    onclose() {
      if (streamControllerRef.value === controller) {
        streamControllerRef.value = null
      }
      if (controller.signal.aborted) {
        return
      }
      if (!completionReceived.value && !handledError) {
        executing.value = false
        executionFailed.value = true
        appendLocalErrorToChat('实时连接已关闭')
      }
    },
  }).catch((error) => {
    if (error?.name === 'AbortError') {
      return
    }
    if (!handledError) {
      executing.value = false
      executionFailed.value = true
      appendLocalErrorToChat(error?.message ?? '实时执行失败')
    }
    closeStream()
  })
}

function handleUnifiedStreamChunk(data: string, reportError: (message: string, name?: string) => void) {
  if (!data) {
    return
  }
  if (data === '[DONE]') {
    executing.value = false
    if (!executionFailed.value && !['running', 'suspended', 'resuming'].includes(persistedRunStatus.value)) {
      if (runtimeWorkflowId.value) {
        void resolveRunByWorkflowId(runtimeWorkflowId.value)
        closeStream()
        return
      }
      completionReceived.value = true
      message.success('流程执行完成')
    }
    closeStream()
    return
  }
  let payload: any
  try {
    payload = JSON.parse(data)
  }
  catch {
    return
  }
  if (!payload || typeof payload !== 'object') {
    return
  }
  const event = payload.meta?.event
  appendStreamPayloadToChat(payload)

  if (event === 'start') {
    const workflowId = String(payload?.meta?.workflow_id || '')
    if (workflowId) {
      runtimeWorkflowId.value = workflowId
    }
    return
  }

  if (event === 'finish') {
    const workflowId = String(payload?.data?.workflow_id || payload?.meta?.workflow_id || '')
    if (workflowId) {
      runtimeWorkflowId.value = workflowId
    }
    const status = Number(payload?.status ?? 0)
    if (status === 2) {
      persistedRunStatus.value = 'suspended'
      completionReceived.value = false
      executing.value = false
      if (runtimeWorkflowId.value) {
        void resolveRunByWorkflowId(runtimeWorkflowId.value)
      }
      return
    }
    if (status === 1) {
      persistedRunStatus.value = 'success'
      completionReceived.value = true
      executing.value = false
      return
    }
    reportError(payload?.message ?? '执行错误', payload?.meta?.label ?? payload?.meta?.node)
    return
  }

  if (event === 'node') {
    const nodeId = payload.meta?.node
    if (nodeId) {
      highlightNodeByName(nodeId)
      applyNodeMetaToStatus(nodeId, payload)
    }
    if (payload.status === 0) {
      reportError(payload.message ?? '执行错误', payload.meta?.label ?? payload.meta?.node)
    }
    return
  }

  if (event === 'error') {
    reportError(payload.message ?? '执行错误', payload.meta?.label ?? payload.meta?.node)
  }
}

function handleFlowPayloadError(messageText: string, name?: string) {
  executing.value = false
  executionFailed.value = true
  const text = (messageText || '').trim() || '执行失败'
  const last = conversationLogs.value[conversationLogs.value.length - 1]
  if (last?.error && last?.content === text && (!name || last?.name === name)) {
    return
  }
  appendLocalErrorToChat(text, name)
}

function appendLocalErrorToChat(content: string, name?: string) {
  const text = content?.trim?.() ? content : '执行失败'
  conversationLogs.value.push({
    role: 'assistant',
    name: name || '流程',
    content: text,
    error: true,
    createdAt: Date.now(),
  })
}

function appendStreamPayloadToChat(payload: any) {
  const createdAt = Date.now()
  const event = payload?.meta?.event
  const node = payload?.meta?.node
  const label = payload?.meta?.label ?? node ?? '流程'
  const payloadMessage = typeof payload?.message === 'string' ? payload.message : ''

  if (event === 'start') {
    return
  }

  const nodeType = payload?.meta?.type
  if (event === 'node' && nodeType === 'ai_start' && payload?.status !== 0) {
    const userContent = formatMessageContent(payload?.data) || formatMessageContent(payload?.meta?.input)
    if (userContent) {
      conversationLogs.value.push({
        role: 'user',
        content: userContent,
        createdAt,
      })
    }
    return
  }

  if (event === 'error') {
    if (payloadMessage && payloadMessage === lastStreamErrorMessage && (!node || node === lastStreamErrorNode)) {
      return
    }
  }

  let content = ''
  const isError = payload?.status === 0
  if (payload?.status === 0) {
    content = payloadMessage || '执行错误'
    lastStreamErrorMessage = content
    lastStreamErrorNode = node || ''
  }
  else {
    content = formatMessageContent(payload?.data) || (payloadMessage && payloadMessage !== 'ok' ? payloadMessage : '')
    if (!content && event === 'node') {
      content = `节点「${label}」执行完成`
    }
  }

  if (!content) {
    return
  }

  const signature = `${event || ''}|${node || ''}|${payload?.status ?? ''}|${payloadMessage}|${content}`
  if (signature === lastStreamSignature) {
    return
  }
  lastStreamSignature = signature

  conversationLogs.value.push({
    role: 'assistant',
    name: label,
    content,
    error: isError,
    createdAt,
  })
}

function applyNodeMetaToStatus(nodeId: string, payload: any) {
  const meta = payload?.meta ?? {}
  const nodeStatus = typeof meta?.node_status === 'number' ? meta.node_status : 1
  const status: NodeStatus['status']
    = nodeStatus === 0 ? 'error' : nodeStatus === 2 ? 'skipped' : 'success'
  const node = findNode(nodeId)
  const previous = nodeStatusMap[nodeId] ?? createEmptyNodeStatus(node)
  const messageText = typeof meta?.node_message === 'string' && meta.node_message
    ? meta.node_message
    : status === 'error'
      ? '执行失败'
      : status === 'skipped'
        ? '已跳过'
        : '执行完成'

  nodeStatusMap[nodeId] = {
    ...previous,
    status,
    message: messageText,
    input: meta?.input ?? previous.input,
    output: payload?.data ?? previous.output,
  }
}

function closeStream() {
  if (streamControllerRef.value) {
    streamControllerRef.value.abort()
    streamControllerRef.value = null
  }
}

function formatMessageContent(content: any): string {
  if (typeof content === 'string') {
    return content
  }
  if (Array.isArray(content)) {
    const parts = content
      .map((segment) => {
        if (!segment) {
          return ''
        }
        if (typeof segment === 'string') {
          return segment
        }
        if (typeof segment === 'object') {
          if (segment.type === 'text') {
            return segment.text ?? ''
          }
          return JSON.stringify(segment)
        }
        return ''
      })
      .filter(Boolean)
    const result = parts.join('\n')
    return result
  }
  if (typeof content === 'object' && content !== null) {
    try {
      const result = JSON.stringify(content, null, 2)
      return result
    }
    catch {
      const result = String(content)
      return result
    }
  }
  if (content === undefined || content === null) {
    return ''
  }
  const result = String(content)
  return result
}

function resetNodeStatuses() {
  activeNodeId.value = ''
  Object.keys(nodeStatusMap).forEach((key) => {
    delete nodeStatusMap[key]
  })
  if (!nodeStatusEnabled.value) {
    return
  }
  orderedNodes.value.forEach((node: any) => {
    nodeStatusMap[node.id] = createEmptyNodeStatus(node)
  })
}

function getStartNode() {
  return orderedNodes.value.find((node: any) => node?.type === 'ai_start') ?? null
}

function markStartNodeStatus(status: NodeStatus['status'], message?: string) {
  const startNode = getStartNode()
  if (!startNode) {
    return
  }
  const previous = nodeStatusMap[startNode.id] ?? createEmptyNodeStatus(startNode)
  nodeStatusMap[startNode.id] = {
    ...previous,
    status,
    message: message ?? previous.message,
  }
  activeNodeId.value = startNode.id
}

function createEmptyNodeStatus(node?: any): NodeStatus {
  return {
    status: 'pending',
    message: node?.data?.description || '等待执行',
    input: null,
    output: null,
  }
}

function findNode(nodeId: string) {
  return orderedNodes.value.find((node: any) => node.id === nodeId)
}

function resolveNodeStatusText(status?: NodeStatus['status']) {
  switch (status) {
    case 'running':
      return 'RUN'
    case 'success':
      return 'OK'
    case 'error':
      return 'ERR'
    case 'skipped':
      return 'SKIP'
    default:
      return 'PEND'
  }
}

function resolveNodeStatusBarClass(status?: NodeStatus['status']) {
  switch (status) {
    case 'success':
      return 'bg-primary'
    case 'running':
      return 'bg-warning'
    case 'error':
      return 'bg-error'
    case 'skipped':
      return 'bg-gray-400 dark:bg-gray-600'
    default:
      return 'bg-gray-200 dark:bg-gray-700'
  }
}

function resolveNodeStatusBadgeClass(status?: NodeStatus['status']) {
  switch (status) {
    case 'success':
      return 'border-primary/30 text-primary bg-primary/10'
    case 'running':
      return 'border-warning/30 text-warning bg-warning/10'
    case 'error':
      return 'border-error/30 text-error bg-error/10'
    case 'skipped':
      return 'border-gray-300/60 dark:border-gray-700 text-muted bg-gray-100/60 dark:bg-gray-800/40'
    default:
      return 'border-gray-300/60 dark:border-gray-700 text-muted bg-gray-100/60 dark:bg-gray-800/40'
  }
}

function resolveRoleLabel(role: ConversationItem['role']) {
  switch (role) {
    case 'user':
      return '用户'
    case 'assistant':
      return 'AI'
    case 'tool':
      return '工具'
    default:
      return '系统'
  }
}

function highlightNodeByName(name?: string | null) {
  if (!name) {
    return
  }
  const node = orderedNodes.value.find((item: any) => item.data?.label === name || item.id === name)
  if (!node) {
    return
  }
  activeNodeId.value = node.id
  const previous = nodeStatusMap[node.id] ?? createEmptyNodeStatus(node)
  if (previous.status === 'pending') {
    nodeStatusMap[node.id] = {
      ...previous,
      status: 'running',
      message: '执行中',
    }
  }
}

function formatTimestamp(value?: number) {
  if (!value) {
    return ''
  }
  const date = new Date(value)
  const pad = (num: number) => String(num).padStart(2, '0')
  const padMs = (num: number) => String(num).padStart(3, '0')
  return `${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}.${padMs(date.getMilliseconds())}`
}

function renderContent(content: any): string {
  const text = typeof content === 'string' ? content : String(content ?? '')
  const result = marked.parse(text || '')
  return typeof result === 'string' ? result : ''
}

function parseStructuredJson(content: any) {
  const text = typeof content === 'string' ? content.trim() : ''
  if (!text) {
    return null
  }
  if (!(text.startsWith('{') || text.startsWith('['))) {
    return null
  }
  try {
    const parsed = JSON.parse(text)
    return parsed
  }
  catch {
    return null
  }
}

function isStructuredJsonMessage(content: any) {
  return parseStructuredJson(content) !== null
}

function formatStructuredJson(content: any) {
  const parsed = parseStructuredJson(content)
  if (parsed === null) {
    return typeof content === 'string' ? content : String(content ?? '')
  }
  try {
    return JSON.stringify(parsed, null, 2)
  }
  catch {
    return typeof content === 'string' ? content : String(content ?? '')
  }
}

onMounted(() => {
  loadDetail().then(() => {
    if (activeRunId.value) {
      loadRunSnapshot(activeRunId.value).then(() => {
        startRunStatusPolling(activeRunId.value)
      })
    }
  })
})

onBeforeUnmount(() => {
  closeStream()
  stopRunStatusPolling()
})

watch(activeRunId, (runId) => {
  if (runId) {
    loadRunSnapshot(runId).then(() => {
      startRunStatusPolling(runId)
    })
    return
  }
  stopRunStatusPolling()
  persistedRunStatus.value = ''
  conversationLogs.value = []
  resetNodeStatuses()
})
</script>

<template>
  <DuxPage :title="flowName" back :scrollbar="false" :padding="false">
    <div class="h-full flex flex-col md:flex-row gap-0">
      <!-- 移动端遮罩 -->
      <div
        v-if="showMobileNodes"
        class="fixed inset-0 bg-black/50 z-40 md:hidden"
        @click="showMobileNodes = false"
      />
      <!-- 左侧：节点时间轴 -->
      <div
        class="fixed inset-y-0 left-0 z-50 w-80 max-w-[85vw] border-r border-muted bg-white dark:bg-gray-950 transform transition-transform duration-200 md:static md:transform-none md:w-80 md:max-w-none"
        :class="showMobileNodes ? 'translate-x-0' : '-translate-x-full md:translate-x-0'"
      >
        <div class="h-full flex flex-col">
          <!-- 头部 -->
          <div class="flex-none px-4 py-3 border-b border-muted">
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-2">
                <div class="size-8 rounded-lg bg-gradient-to-br from-primary to-primary/80 flex items-center justify-center shadow-lg shadow-primary/20">
                  <i class="i-tabler:list-details text-white text-base" />
                </div>
                <div class="leading-tight">
                  <div class="text-xs uppercase tracking-[0.18em] text-muted">
                    Execution
                  </div>
                  <div class="font-semibold text-base">
                    Nodes
                  </div>
                </div>
              </div>
              <button
                class="md:hidden size-8 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 flex items-center justify-center"
                @click="showMobileNodes = false"
              >
                <i class="i-tabler:x text-lg" />
              </button>
              <NTag v-if="nodeStatusEnabled" size="small" :bordered="false" round type="primary">
                {{ orderedNodes.length }}
              </NTag>
            </div>
          </div>

          <!-- 节点列表 -->
          <div class="flex-1 overflow-y-auto px-4 py-4">
            <NSpin :show="loadingDetail">
              <div v-if="orderedNodes.length" class="space-y-2">
                <div
                  v-for="(node, index) in orderedNodes"
                  :key="node.id"
                  class="relative"
                >
                  <div
                    class="group relative flex gap-3 px-3 py-3 rounded-lg border transition-all duration-200 cursor-pointer overflow-hidden bg-white/70 dark:bg-gray-900/40 backdrop-blur supports-[backdrop-filter]:bg-white/60"
                    :class="
                      activeNodeId === node.id
                        ? 'border-primary/30 shadow-lg shadow-primary/10 ring-1 ring-primary/10'
                        : 'border-muted/60 hover:border-primary/20 hover:shadow-md hover:shadow-black/5 dark:hover:shadow-black/30'
                    "
                    @click="() => { activeNodeId = node.id; showMobileNodes = false }"
                  >
                    <!-- 状态侧边条 -->
                    <div
                      class="absolute left-0 top-2 bottom-2 w-1 rounded-r"
                      :class="resolveNodeStatusBarClass(nodeStatusMap[node.id]?.status)"
                    />

                    <!-- 序号/图标 -->
                    <div class="flex-none relative z-10 pl-2">
                      <div
                        class="size-12 rounded-xl flex flex-col items-center justify-center border bg-white/60 dark:bg-gray-950/30"
                        :class="activeNodeId === node.id ? 'border-primary/30' : 'border-muted/60'"
                      >
                        <div class="text-xs font-mono text-muted leading-none">
                          {{ String(index + 1).padStart(2, '0') }}
                        </div>
                        <div
                          class="mt-1 text-[8px] px-1.5 py-0.5 rounded-md border leading-none"
                          :class="resolveNodeStatusBadgeClass(nodeStatusMap[node.id]?.status)"
                        >
                          {{ resolveNodeStatusText(nodeStatusMap[node.id]?.status) }}
                        </div>
                      </div>
                    </div>

                    <!-- 节点信息 -->
                    <div class="flex-1 min-w-0">
                      <div class="flex items-center gap-2 min-w-0">
                        <div class="font-semibold text-sm truncate">
                          {{ node.data?.name || node.id }}
                        </div>
                        <div class="text-xs font-mono text-muted/80 truncate">
                          {{ node.id }}
                        </div>
                      </div>
                      <div class="mt-1 text-xs truncate text-muted">
                        {{ nodeStatusMap[node.id]?.message || '等待执行' }}
                      </div>
                    </div>

                    <div class="flex-none flex items-center pr-1">
                      <i
                        v-if="nodeStatusMap[node.id]?.status === 'success'"
                        class="i-tabler:check text-base text-primary"
                      />
                      <i
                        v-else-if="nodeStatusMap[node.id]?.status === 'running'"
                        class="i-tabler:loader-2 text-base text-warning animate-spin"
                      />
                      <i
                        v-else-if="nodeStatusMap[node.id]?.status === 'error'"
                        class="i-tabler:x text-base text-error"
                      />
                      <i
                        v-else-if="nodeStatusMap[node.id]?.status === 'skipped'"
                        class="i-tabler:ban text-base text-muted"
                      />
                      <i v-else class="i-tabler:circle-dot text-base text-muted" />
                    </div>
                  </div>
                </div>
              </div>
              <div v-else class="py-16 flex items-center justify-center">
                <NEmpty description="暂无节点" size="small" />
              </div>
            </NSpin>
          </div>
        </div>
      </div>

      <!-- 右侧：执行日志和输入表单 -->
      <div class="flex-1 flex flex-col min-w-0 bg-muted min-h-0">
        <!-- 工作流信息头部（合并执行状态） -->
        <div class="flex-none border-b border-muted bg-white dark:bg-gray-950">
          <div class="px-3 py-3 md:px-6">
            <div class="flex items-start md:items-center justify-between gap-3 md:gap-4">
              <!-- 左侧：工作流信息 -->
              <div class="flex items-center gap-3 flex-1 min-w-0">
                <div class="md:hidden flex items-center">
                  <NButton text @click="showMobileNodes = true">
                    <template #icon>
                      <i class="i-tabler:menu-2 size-5" />
                    </template>
                  </NButton>
                </div>
                <!-- 工作流图标 -->
                <div class="flex-none">
                  <div class="size-8 rounded-lg bg-gradient-to-br from-primary via-primary to-primary/80 flex items-center justify-center shadow-lg shadow-primary/20 relative overflow-hidden">
                    <div class="absolute inset-0 bg-gradient-to-br from-white/10 to-transparent" />
                    <i class="i-tabler:bolt text-base text-white relative z-10" />
                  </div>
                </div>

                <!-- 工作流详细信息 -->
                <div class="flex-1 min-w-0">
                  <div class="flex items-center gap-3">
                    <h2 class="text-base font-bold text-default truncate">
                      {{ flowName }}
                    </h2>
                    <NTag v-if="loadingDetail" size="small" :bordered="false">
                      <template #icon>
                        <NSpin :size="12" />
                      </template>
                      加载中
                    </NTag>
                  </div>
                  <div class="text-xs text-muted">
                    {{ flowDetail?.description || '工作流自动化执行' }}
                  </div>
                </div>
              </div>

              <!-- 右侧：执行状态和控制 -->
              <div class="flex-none flex flex-col items-end gap-3">
                <!-- 执行状态 -->
                <div
                  class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-medium backdrop-blur supports-[backdrop-filter]:bg-white/40"
                  :class="executionStatus.cardClass"
                >
                  <i class="text-base" :class="[executionStatus.icon, executionStatus.iconClass]" />
                  <span class="leading-none">{{ executionStatus.label }}</span>
                </div>
              </div>
            </div>
            <div v-if="backgroundStatusHint" class="mt-3 rounded border border-warning/30 bg-warning/5 px-3 py-2 text-sm text-warning">
              {{ backgroundStatusHint }}
            </div>
          </div>
        </div>

        <!-- 执行日志区（使用聊天框样式） -->
        <div class="flex-1 min-h-0 overflow-y-auto p-4 md:p-6 bg-gradient-to-b from-gray-50/30 to-transparent dark:from-gray-900/30">
          <div v-if="visibleConversationLogs.length" class="space-y-4 max-w-4xl mx-auto">
            <div
              v-for="(msg, index) in visibleConversationLogs"
              :key="index"
              class="flex gap-3 animate-fade-in"
              :class="msg.role === 'user' ? 'justify-end' : 'justify-start'"
            >
              <!-- 左侧头像 -->
              <div v-if="msg.role !== 'user'" class="flex-none">
                <div
                  class="size-9 rounded-full flex items-center justify-center shadow-lg"
                  :class="
                    msg.role === 'assistant'
                      ? 'bg-gradient-to-br from-primary to-primary/80'
                      : msg.role === 'tool'
                        ? 'bg-gradient-to-br from-warning to-warning/80'
                        : 'bg-gradient-to-br from-gray-500 to-gray-600'
                  "
                >
                  <i
                    class="text-lg text-white"
                    :class="
                      msg.role === 'assistant'
                        ? 'i-tabler:robot'
                        : msg.role === 'tool'
                          ? 'i-tabler:tool'
                          : 'i-tabler:message-circle'
                    "
                  />
                </div>
              </div>

              <!-- 消息气泡 -->
              <div class="flex flex-col gap-1.5 max-w-[85%] md:max-w-[75%]">
                <div
                  v-if="msg.role !== 'user'"
                  class="text-sm font-semibold px-3"
                  :class="
                    msg.error
                      ? 'text-error'
                      : msg.role === 'assistant'
                        ? 'text-primary'
                        : msg.role === 'tool'
                          ? 'text-warning'
                          : 'text-muted'
                  "
                >
                  {{ msg.name || resolveRoleLabel(msg.role) }}
                </div>

                <div
                  class="rounded-3xl px-4 py-3 text-sm leading-relaxed shadow hover:shadow-lg transition-all duration-200"
                  :class="
                    msg.role === 'user'
                      ? 'bg-gradient-to-br from-primary to-primary/90 text-white rounded-tr-lg'
                      : msg.error
                        ? 'bg-error/5 border border-error/30 rounded-tl-lg'
                        : msg.role === 'assistant'
                          ? 'bg-white dark:bg-gray-800 border border-muted rounded-tl-lg'
                        : 'bg-warning/5 border border-warning/30 rounded-tl-lg'
                  "
                >
                  <details
                    v-if="isStructuredJsonMessage(msg.content)"
                    class="p-0"
                    open
                  >
                    <summary class="cursor-pointer text-sm select-none ">
                      结构化结果 JSON
                    </summary>
                    <div :class="msg.role === 'user' ? 'bg-default rounded-xl px-3 py-2 text-white/60' : 'text-muted'">
                      <NCode
                        language="json"
                        :code="formatStructuredJson(msg.content)"
                        :word-wrap="true"
                        class="mt-2"
                        :style="{
                          '--n-color': 'transparent',
                          '--n-border-color': 'transparent',
                        }"
                      />
                    </div>
                  </details>
                  <div
                    v-else
                    class="max-w-none flow-chat-content"
                    :class="
                      msg.role === 'user'
                        ? 'text-white'
                        : msg.error
                          ? 'text-error'
                          : msg.role === 'assistant'
                            ? 'text-default'
                            : 'text-warning'
                    "
                    v-html="renderContent(msg.content)"
                  />
                </div>

                <div
                  class="text-xs px-3 flex items-center gap-1.5"
                  :class="msg.role === 'user' ? 'text-gray-400 justify-end' : 'text-gray-500'"
                >
                  <i class="i-tabler:clock text-xs" />
                  <span>{{ formatTimestamp(msg.createdAt) }}</span>
                </div>
              </div>

              <!-- 右侧头像 -->
              <div v-if="msg.role === 'user'" class="flex-none">
                <div class="size-9 rounded-full bg-gradient-to-br from-primary to-primary/80 flex items-center justify-center shadow-lg shadow-primary/20">
                  <i class="i-tabler:user text-lg text-white" />
                </div>
              </div>
            </div>

            <div v-if="executing && !executionFailed" class="flex gap-3 justify-start animate-fade-in">
              <div class="flex-none">
                <div class="size-9 rounded-full bg-gradient-to-br from-primary to-primary/80 flex items-center justify-center shadow-lg">
                  <i class="i-tabler:robot text-lg text-white" />
                </div>
              </div>
              <div class="flex flex-col gap-1.5 max-w-[85%] md:max-w-[75%]">
                <div class="text-sm font-semibold px-3 text-primary">
                  AI
                </div>
                <div class="bg-white dark:bg-gray-800 border border-muted rounded-3xl rounded-tl-lg px-4 py-3 shadow">
                  <div class="flex items-center gap-1.5">
                    <span class="size-1.5 rounded-full bg-primary/60 animate-bounce" style="animation-delay: 0ms" />
                    <span class="size-1.5 rounded-full bg-primary/60 animate-bounce" style="animation-delay: 150ms" />
                    <span class="size-1.5 rounded-full bg-primary/60 animate-bounce" style="animation-delay: 300ms" />
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div v-else class="h-full flex flex-col items-center justify-center gap-4 text-muted">
            <div class="relative">
              <div class="size-24 rounded-2xl bg-gradient-to-br from-primary/10 to-primary/5 flex items-center justify-center">
                <i class="i-tabler:messages text-5xl text-primary/60" />
              </div>
              <div class="absolute -bottom-1 -right-1 size-8 rounded-full bg-gradient-to-br from-primary/20 to-primary/10 flex items-center justify-center shadow-lg">
                <i class="i-tabler:sparkles text-sm text-primary animate-pulse" />
              </div>
            </div>
            <div class="text-sm">
              暂无执行日志，点击下方按钮开始执行
            </div>
          </div>
        </div>

        <!-- 底部：输入参数区 -->
        <div v-if="showInputAction" class="p-4 max-w-4xl mx-auto w-full">
          <div class="rounded-2xl border border-muted/60 bg-white/60 dark:bg-gray-900/30 backdrop-blur supports-[backdrop-filter]:bg-white/40 px-4 py-3">
            <div class="flex items-center justify-between gap-4">
              <div class="flex items-center gap-3 min-w-0">
                <div class="size-10 rounded-2xl bg-gradient-to-br from-primary/15 to-primary/5 flex items-center justify-center border border-primary/15">
                  <i class="i-tabler:forms text-primary text-lg" />
                </div>
                <div class="min-w-0">
                  <div class="text-sm font-semibold leading-tight">
                    输入参数
                  </div>
                  <div class="text-[11px] text-muted truncate mt-0.5">
                    {{ inputSummary }}
                  </div>
                </div>
              </div>

              <div class="flex items-center gap-2 flex-none">
                <NButton
                  type="primary"
                  :loading="executing || loadingDetail"
                  :disabled="loadingDetail"
                  @click="openInputModal(true)"
                >
                  <template #icon>
                    <i class="i-tabler:player-play" />
                  </template>
                  开始执行
                </NButton>
              </div>
            </div>
          </div>
        </div>

        <!-- 执行完成后的操作区 -->
        <div v-else-if="completionReceived && !isHistoryMode" class="p-4 flex justify-center">
          <NButton type="primary" @click="() => { completionReceived = false; executionFailed = false; conversationLogs = []; resetNodeStatuses() }">
            <template #icon>
              <i class="i-tabler:refresh" />
            </template>
            重新执行
          </NButton>
        </div>
        <div v-else-if="isHistoryMode" class="p-4" />
      </div>
    </div>
  </DuxPage>
</template>

<style scoped>
@keyframes fade-in {
  from {
    opacity: 0;
    transform: translateY(10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.animate-fade-in {
  animation: fade-in 0.3s ease-out;
}

.flow-chat-content {
  word-break: break-all;
  overflow-wrap: anywhere;
}

.flow-chat-content :deep(pre),
.flow-chat-content :deep(code) {
  white-space: pre-wrap;
  word-break: break-all;
  overflow-wrap: anywhere;
}

.flow-chat-content :deep(pre) {
  overflow-x: hidden;
}

</style>
