<script setup lang="ts">
import { useCustomMutation, useGetAuth, useManage } from '@duxweb/dvha-core'
import { DuxPage, useDialog } from '@duxweb/dvha-pro'
import { fetchEventSource } from '@microsoft/fetch-event-source'
import dayjs from 'dayjs'
import { marked } from 'marked'
import { NButton, NEmpty, NImage, NSpin, NTag, useMessage } from 'naive-ui'
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { extractPartText, normalizeMediaUrl, stringifyContent, tryParseStructuredFromText } from './chatMessageMedia'
import { mapOpenAiUiMessages } from './chatMessageMapper'

type ChatRole = 'user' | 'assistant' | 'system' | 'tool'

interface ChatMessage {
  role: ChatRole
  content: any
  meta?: Record<string, any>
}

interface StreamStatus {
  code: string
  label: string
  meta?: Record<string, any>
}

const props = defineProps<{
  code?: string
  sessionId?: number | null
}>()

const route = useRoute()
const router = useRouter()
const manage = useManage()
const requestClient = useCustomMutation()
const message = useMessage()
const dialog = useDialog()
const auth = useGetAuth()
const apiBase = 'ai/message/v1'
const supportImage = ref(true)
const supportFile = ref(true)

const routeAgentCode = computed(() => {
  const paramVal = route.params?.code
  const queryVal = route.query?.code
  const value = paramVal ?? queryVal
  if (Array.isArray(value))
    return value[0]
  return typeof value === 'string' ? value : ''
})

const routeSessionId = computed<number | null>(() => {
  const value = route.query?.sessionId
  const target = Array.isArray(value) ? value[0] : value
  const parsed = target !== undefined ? Number(target) : null
  return Number.isFinite(parsed) ? parsed : null
})

function resolveInitialAgentCode() {
  return props.code || routeAgentCode.value || ''
}

function resolveInitialSessionId(): number | null {
  const candidate = props.sessionId ?? routeSessionId.value ?? null
  return candidate && Number.isFinite(Number(candidate)) ? Number(candidate) : null
}

const agentCode = ref(resolveInitialAgentCode())
const preferredSessionId = ref<number | null>(resolveInitialSessionId())

const sessions = ref<any[]>([])
const activeSessionId = ref<number | null>(preferredSessionId.value)
const availableAgents = ref<any[]>([])
const loadingAgents = ref(false)
const currentAgent = computed(() => availableAgents.value.find(a => a.code === agentCode.value))
const chatHistory = ref<ChatMessage[]>([])
const inputText = ref('')
const sending = ref(false)
const controller = ref<AbortController | null>(null)
const loadingSessions = ref(false)
const messagesLoading = ref(false)
const chatBodyRef = ref<HTMLDivElement | null>(null)
const scrollPending = ref(false)
const stickToBottom = ref(true)
const uploading = ref(false)
const imageInputRef = ref<HTMLInputElement | null>(null)
const fileInputRef = ref<HTMLInputElement | null>(null)
const pendingImages = ref<{ url: string, filename?: string, content?: string, mode_hint?: string, parse_mode?: string, parsed_text?: string, bytes?: number, mime_type?: string }[]>([])
const pendingFiles = ref<{ url: string, filename?: string, content?: string, mode_hint?: string, media_kind?: string, mime_type?: string, parse_mode?: string, parsed_text?: string, bytes?: number }[]>([])
const showMobileSidebar = ref(false)
const streamStatus = ref<StreamStatus | null>(null)
const sessionNotice = ref('')
const messagePollTimer = ref<number | null>(null)
const SESSION_BUSY_MESSAGE = '当前会话正在处理中，请等待本轮完成后再发送'
const MESSAGE_POLL_INTERVAL = 3000
const MESSAGE_FULL_SYNC_EVERY = 4
const messagePollCount = ref(0)

function headers() {
  const h: Record<string, string> = {
    'Content-Type': 'application/json',
  }
  if (auth?.token) {
    h.Authorization = auth.token
  }
  return h
}

function uploadHeaders() {
  const h: Record<string, string> = {}
  if (auth?.token) {
    h.Authorization = auth.token
  }
  return h
}

function renderMessageContent(msg: ChatMessage): string {
  const text = stringifyContent(msg.content)
  const meta = (msg.meta || {}) as Record<string, any>
  if (meta.__render_cache_text === text && typeof meta.__render_cache_html === 'string') {
    return meta.__render_cache_html
  }
  const result = marked.parse(text || '')
  const html = typeof result === 'string' ? result : ''
  meta.__render_cache_text = text
  meta.__render_cache_html = html
  msg.meta = meta
  return html
}

function renderRoleLabel(msg: ChatMessage): string {
  switch (msg.role) {
    case 'assistant':
      return '助手'
    case 'system':
      return '系统提示'
    case 'tool': {
      return '工具'
    }
    default:
      return '我'
  }
}

function formatMessageTime(msg: ChatMessage): string {
  msg.meta = msg.meta || {}
  const t = msg.meta.created_at
  if (t)
    return String(t)
  if (msg.meta.__display_time)
    return String(msg.meta.__display_time)
  const now = dayjs().format('YYYY-MM-DD HH:mm:ss')
  msg.meta.__display_time = now
  return now
}

function appendAssistantDeltaParts(msg: ChatMessage, parts: any[]) {
  msg.meta = msg.meta || {}
  const meta = msg.meta
  parts.forEach((part: any) => {
    if (!part || typeof part !== 'object')
      return
    const type = String(part.type || '')
    if (type === 'text') {
      const text = extractPartText(part)
      if (text)
        msg.content = `${msg.content || ''}${text}`
      return
    }
    if (type === 'image_url') {
      const image = part.image_url
      const url = normalizeMediaUrl(image)
      if (url) {
        meta.images = meta.images || []
        meta.images.push(typeof image === 'string' ? { url } : image)
      }
      return
    }
    if (type === 'video_url') {
      const video = part.video_url
      const url = normalizeMediaUrl(video)
      if (url) {
        meta.videos = meta.videos || []
        meta.videos.push(typeof video === 'string' ? { url } : video)
      }
      return
    }
    if (type === 'file_url') {
      const file = part.file || part
      meta.files = meta.files || []
      meta.files.push(file)
      return
    }
    if (type === 'card' && Array.isArray(part.card)) {
      meta.card = part.card
    }
  })
}

async function handleCardAction(button: any) {
  const type = String(button?.type || '').toLowerCase()

  if (type === 'url') {
    const url = String(button?.url || '')
    if (!url)
      return
    window.open(url, '_blank', 'noopener,noreferrer')
    return
  }

  if (type === 'path') {
    const path = String(button?.path || '')
    if (!path)
      return
    router.push(manage.getRoutePath(path))
    return
  }

  if (type === 'approval') {
    const text = String(button?.text || '').trim()
    if (!text) {
      message.error('审批内容为空')
      return
    }

    await handleApprovalReply(text)
    return
  }

  if (!activeSessionId.value)
    return

  const action = String(button?.action || '')

  if (!action)
    return

  try {
    const res = await requestClient.mutateAsync({
      path: `${apiBase}/actions`,
      method: 'POST',
      payload: {
        session_id: activeSessionId.value,
        action,
        payload: button?.payload || {},
      },
    })
    const messages = Array.isArray(res?.data?.data?.messages)
      ? res.data.data.messages
      : Array.isArray(res?.data?.messages)
        ? res.data.messages
        : []
    messages.forEach((m: any) => {
      const meta: Record<string, any> = {
        created_at: dayjs().format('YYYY-MM-DD HH:mm:ss'),
      }
      let contentValue: any = m.content || ''
      if (m.content && typeof m.content === 'object' && m.content.type === 'card') {
        meta.card = m.content.card || []
        contentValue = ''
      }
      chatHistory.value.push({
        role: (m.role || 'assistant') as ChatRole,
        content: contentValue,
        meta,
      })
    })
    scrollToBottom()
  }
  catch (err: any) {
    message.error(err?.message || '操作失败')
  }
}

function scrollToBottom() {
  if (scrollPending.value)
    return
  scrollPending.value = true
  requestAnimationFrame(() => {
    nextTick(() => {
      if (chatBodyRef.value) {
        chatBodyRef.value.scrollTop = chatBodyRef.value.scrollHeight
      }
      scrollPending.value = false
    })
  })
}

function updateScrollAnchor() {
  const el = chatBodyRef.value
  if (!el)
    return
  const threshold = 80
  const distance = el.scrollHeight - el.scrollTop - el.clientHeight
  stickToBottom.value = distance <= threshold
}

function scrollToBottomIfNeeded(force = false) {
  if (force || stickToBottom.value)
    scrollToBottom()
}

function appendMessage(msg: ChatMessage) {
  chatHistory.value.push(msg)
  scrollToBottomIfNeeded()
}

function createLocalMessageId(prefix: string) {
  return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
}

function hasVisibleMessageContent(msg?: ChatMessage | null) {
  if (!msg)
    return false
  const text = String(msg.content || '').trim()
  if (text)
    return true
  if (msg.meta?.approval && typeof msg.meta.approval === 'object')
    return true
  if (Array.isArray(msg.meta?.images) && msg.meta.images.length)
    return true
  if (Array.isArray(msg.meta?.videos) && msg.meta.videos.length)
    return true
  if (Array.isArray(msg.meta?.files) && msg.meta.files.length)
    return true
  if (Array.isArray(msg.meta?.card) && msg.meta.card.length)
    return true
  return false
}

function isPendingAssistant(msg?: ChatMessage | null) {
  if (!msg || msg.role !== 'assistant')
    return false
  return Boolean(msg.meta?.pending) && !hasVisibleMessageContent(msg)
}

function approvalStatusText(status?: string) {
  switch (String(status || '').trim()) {
    case 'approved':
      return '已同意'
    case 'rejected':
      return '已拒绝'
    case 'expired':
      return '已过期'
    case 'canceled':
      return '已取消'
    default:
      return '待审批'
  }
}

function approvalStatusType(status?: string) {
  switch (String(status || '').trim()) {
    case 'approved':
      return 'success'
    case 'rejected':
      return 'error'
    case 'expired':
    case 'canceled':
      return 'warning'
    default:
      return 'info'
  }
}

const streamStatusTone = computed(() => {
  const code = streamStatus.value?.code || ''
  if (code === 'queued' || code === 'retry_wait')
    return 'warning'
  if (code === 'tool_call' || code === 'tool_result')
    return 'info'
  return 'primary'
})

const streamStatusText = computed(() => {
  const status = streamStatus.value
  if (!status)
    return ''
  const meta = status.meta || {}
  if (status.code === 'queued' && meta.waited_ms) {
    return `${status.label} · 已等待 ${Math.ceil(Number(meta.waited_ms) / 1000)}s`
  }
  if (status.code === 'retry_wait' && meta.delay_ms) {
    return `${status.label} · ${Math.ceil(Number(meta.delay_ms) / 1000)}s 后继续`
  }
  if ((status.code === 'tool_call' || status.code === 'tool_result') && meta.tool) {
    return `${status.label} · ${String(meta.tool)}`
  }
  return status.label
})

function approvalRows(msg: ChatMessage) {
  const approval = msg.meta?.approval || {}
  const request = approval.request && typeof approval.request === 'object' ? approval.request : {}
  const actions = Array.isArray(request.actions) ? request.actions : []
  const parsed = actions[0]?.parsed && typeof actions[0].parsed === 'object' ? actions[0].parsed : {}
  const payload = parsed.payload && typeof parsed.payload === 'object' ? parsed.payload : {}
  const rows = []

  if (approval.summary)
    rows.push({ name: '说明', value: String(approval.summary) })
  if (approval.action_name || approval.tool_name)
    rows.push({ name: '执行', value: String(approval.action_name || approval.tool_name) })
  if (approval.risk_level)
    rows.push({ name: '风险', value: String(approval.risk_level) })
  if (approval.display_value)
    rows.push({ name: '目标', value: String(approval.display_value) })
  if (payload.command)
    rows.push({ name: '命令', value: String(payload.command) })

  return rows
}

function canReplyApproval(msg: ChatMessage) {
  const status = String(msg.meta?.approval?.status || '')
  return msg.role === 'assistant' && status === 'pending' && !sending.value
}

async function handleApprovalReply(text: string) {
  if (!text.trim())
    return

  try {
    inputText.value = text.trim()
    await handleSend()
    await loadMessages(activeSessionId.value, { silent: true })
    scrollToBottom()
  }
  catch (err: any) {
    message.error(err?.message || '审批操作失败')
  }
}

function cleanupPendingAssistant(index: number | null) {
  if (index === null)
    return
  const target = chatHistory.value[index]
  if (!target || target.role !== 'assistant')
    return
  if (hasVisibleMessageContent(target))
    return
  chatHistory.value.splice(index, 1)
}

function buildPayloadMessages(baseMessages: ChatMessage[]) {
  return baseMessages.map((msg) => {
    const parts: any[] = []
    const images = Array.isArray(msg.meta?.images) ? msg.meta.images : []
    const files = Array.isArray(msg.meta?.files) ? msg.meta.files : []
    const contentText = msg.content ? String(msg.content) : ''

    if (contentText) {
      parts.push({ type: 'text', text: contentText })
    }

    const seenImages = new Set<string>()
    images.forEach((img: any) => {
      const parseMode = String(img?.parse_mode || 'passthrough')
      const url = img?.url || img
      if (parseMode === 'parsed') {
        if (url && !seenImages.has(String(url))) {
          parts.push({
            type: 'image_url',
            image_url: {
              url,
              mode_hint: img?.mode_hint || 'auto',
            },
          })
          seenImages.add(String(url))
        }
        const parsedText = String(img?.parsed_text || '')
        const meta = [
          `文件名: ${img?.filename || 'unknown'}`,
          `类型: ${img?.mime_type || 'image'}`,
          `大小: ${Number(img?.bytes || 0)} bytes`,
        ].join(' | ')
        if (parsedText.trim()) {
          parts.push({ type: 'text', text: `[本地解析附件]\n${meta}\n\n${parsedText}` })
        }
        return
      }
      if (url && !seenImages.has(String(url))) {
        parts.push({
          type: 'image_url',
          image_url: {
            url,
            mode_hint: img?.mode_hint || 'auto',
          },
        })
        seenImages.add(String(url))
      }
    })

    if (files && files.length) {
      files.forEach((f: any) => {
        const parseMode = String(f?.parse_mode || 'passthrough')
        const raw = f.raw || {
          url: f.url,
          filename: f.filename,
          mime_type: f.mime_type || '',
          media_type: f.mime_type || '',
          media_kind: f.media_kind || 'file',
          mode_hint: f.mode_hint || 'auto',
        }
        if (parseMode === 'parsed') {
          parts.push({ type: 'file_url', file: raw })
          const parsedText = String(f?.parsed_text || '')
          const meta = [
            `文件名: ${f?.filename || 'unknown'}`,
            `类型: ${f?.mime_type || 'application/octet-stream'}`,
            `大小: ${Number(f?.bytes || 0)} bytes`,
          ].join(' | ')
          if (parsedText.trim()) {
            parts.push({ type: 'text', text: `[本地解析附件]\n${meta}\n\n${parsedText}` })
          }
          return
        }
        parts.push({ type: 'file_url', file: raw })
      })
    }

    const finalContent = parts.length > 0 ? parts : (msg.content ? [{ type: 'text', text: msg.content }] : [])

    return {
      role: msg.role,
      content: finalContent,
    }
  })
}

async function uploadFileToServer(file: File, as: 'image' | 'file') {
  if (!agentCode.value) {
    message.warning('请先选择智能体')
    return null
  }
  uploading.value = true
  try {
    const form = new FormData()
    form.append('file', file)
    const res = await fetch(manage.getApiUrl(`${apiBase}/files?model=${encodeURIComponent(agentCode.value)}`), {
      method: 'POST',
      headers: uploadHeaders(),
      body: form,
      credentials: 'include',
    })
    const data = await res.json()
    const payload = data?.data || data
    if (!payload?.url && !payload?.parsed_text) {
      throw new Error(data?.error?.message || '上传失败')
    }
    const fileContent = payload.content || ''
    if (as === 'image') {
      pendingImages.value.push({
        url: payload.url || '',
        filename: payload.filename || payload.name || file.name,
        content: fileContent || payload.url,
        mode_hint: payload.mode_hint || 'auto',
        parse_mode: payload.parse_mode || 'passthrough',
        parsed_text: payload.parsed_text || '',
        bytes: Number(payload.bytes || file.size || 0),
        mime_type: payload.mime_type || file.type || '',
      })
    }
    else {
      pendingFiles.value.push({
        filename: payload.filename || payload.name || file.name,
        url: payload.url || '',
        content: fileContent || payload.url,
        mode_hint: payload.mode_hint || 'auto',
        media_kind: payload.media_kind || 'file',
        mime_type: payload.mime_type || file.type || '',
        parse_mode: payload.parse_mode || 'passthrough',
        parsed_text: payload.parsed_text || '',
        bytes: Number(payload.bytes || file.size || 0),
      })
    }
  }
  catch (err: any) {
    message.error(err?.message || '上传失败')
  }
  finally {
    uploading.value = false
  }
}

const onSelectImage = () => imageInputRef.value?.click()
const onSelectFile = () => fileInputRef.value?.click()

function onImageChosen(event: Event) {
  const target = event.target as HTMLInputElement
  const file = target.files?.[0]
  target.value = ''
  if (file)
    uploadFileToServer(file, 'image')
}

function onFileChosen(event: Event) {
  const target = event.target as HTMLInputElement
  const file = target.files?.[0]
  target.value = ''
  if (file)
    uploadFileToServer(file, 'file')
}

async function loadSessions() {
  if (!agentCode.value)
    return
  loadingSessions.value = true
  try {
    const res = await requestClient.mutateAsync({
      path: `${apiBase}/sessions`,
      method: 'GET',
      query: {
        model: agentCode.value,
      },
    })
    const list = Array.isArray(res?.data) ? res.data : (Array.isArray(res?.data?.data) ? res.data.data : [])
    sessions.value = list
    if (preferredSessionId.value && list.some((s: any) => s.id === Number(preferredSessionId.value))) {
      activeSessionId.value = Number(preferredSessionId.value)
    }
    else if (!activeSessionId.value) {
      if (sessions.value.length) {
        activeSessionId.value = sessions.value[0].id
      }
      else {
        await createSession()
      }
    }
  }
  catch (err: any) {
    message.error(err?.message || '加载会话失败')
  }
  finally {
    loadingSessions.value = false
  }
}

async function createSession() {
  if (!agentCode.value || sending.value)
    return
  try {
    const res = await requestClient.mutateAsync({
      path: `${apiBase}/sessions`,
      method: 'POST',
      payload: {
        model: agentCode.value,
      },
    })
    const session = res?.data?.data ?? res?.data
    if (session?.id) {
      sessions.value.unshift(session)
      activeSessionId.value = session.id
      chatHistory.value = []
    }
  }
  catch (err: any) {
    message.error(err?.message || '新建会话失败')
  }
}

async function renameSessionTitle(nextTitle: string) {
  if (!activeSessionId.value)
    return
  const res = await requestClient.mutateAsync({
    path: `${apiBase}/sessions/${activeSessionId.value}`,
    method: 'PUT',
    payload: { title: nextTitle },
  })
  const updated = res?.data?.data ?? res?.data
  if (updated) {
    const idx = sessions.value.findIndex(s => s.id === activeSessionId.value)
    if (idx !== -1)
      sessions.value[idx] = { ...sessions.value[idx], ...updated }
  }
  message.success('标题已更新')
}

function openRenameDialog() {
  if (!activeSessionId.value || sending.value) {
    return
  }
  const session = sessions.value.find(s => s.id === activeSessionId.value)
  dialog.prompt({
    title: '重命名会话',
    defaultValue: { title: session?.title || '' },
    formSchema: [
      {
        tag: 'n-input',
        name: 'title',
        label: '会话标题',
        required: true,
        attrs: {
          'v-model:value': 'form.title',
          'placeholder': '输入会话标题',
          'maxlength': 50,
        },
      },
    ],
  }).then(async (values: Record<string, any>) => {
    const nextTitle = String(values?.title ?? '').trim()
    if (!nextTitle) {
      message.warning('请输入会话标题')
      return
    }
    await renameSessionTitle(nextTitle)
  }).catch(() => {})
}

function removeSession(sessionId?: number | null) {
  const targetId = sessionId ?? activeSessionId.value
  if (!targetId || sending.value)
    return
  const session = sessions.value.find(item => item.id === targetId)
  const sessionTitle = session?.title || `会话 #${targetId}`

  dialog.confirm({
    title: '删除会话',
    content: `确定要删除「${sessionTitle}」吗？此操作无法恢复。`,
  }).then(async () => {
    try {
      await requestClient.mutateAsync({
        path: `${apiBase}/sessions/${targetId}`,
        method: 'DELETE',
      })
      sessions.value = sessions.value.filter(item => item.id !== targetId)
      if (activeSessionId.value === targetId) {
        const nextSession = sessions.value[0]?.id ?? null
        activeSessionId.value = nextSession
        if (!nextSession)
          chatHistory.value = []
      }
      message.success('会话已删除')
    }
    catch (err: any) {
      message.error(err?.message || '删除会话失败')
      throw err
    }
  }).catch(() => {})
}

async function loadMessages(sessionId: number | null, options: { silent?: boolean } = {}) {
  if (!sessionId)
    return
  if (!options.silent)
    messagesLoading.value = true
  if (!options.silent)
    chatHistory.value = []
  try {
    const res = await requestClient.mutateAsync({
      path: `${apiBase}/sessions/${sessionId}/messages`,
      method: 'GET',
    })
    const list = Array.isArray(res?.data) ? res.data : (Array.isArray(res?.data?.data) ? res.data.data : [])
    const mapped = mapOpenAiUiMessages(list, { filterToolCallPlaceholder: true }) as ChatMessage[]

    // Keep the natural DB order (id ascending), but hide empty assistant messages that only contain tool_calls.
    chatHistory.value = mapped
    if (!options.silent)
      scrollToBottomIfNeeded(true)
    else if (mapped.length !== 0)
      scrollToBottomIfNeeded()
  }
  catch (err: any) {
    message.error(err?.message || '加载消息失败')
  }
  finally {
    if (!options.silent)
      messagesLoading.value = false
  }
}

function latestMessageId() {
  return chatHistory.value.reduce((max, msg) => {
    const id = Number(msg?.meta?.id || 0)
    return Number.isFinite(id) && id > max ? id : max
  }, 0)
}

async function pollMessages() {
  if (!activeSessionId.value || sending.value || messagesLoading.value)
    return

  messagePollCount.value++
  if (messagePollCount.value % MESSAGE_FULL_SYNC_EVERY === 0) {
    await loadMessages(activeSessionId.value, { silent: true })
    return
  }

  const afterId = latestMessageId()
  if (afterId <= 0)
    return

  try {
    const res = await requestClient.mutateAsync({
      path: `${apiBase}/sessions/${activeSessionId.value}/messages`,
      method: 'GET',
      query: {
        after_id: afterId,
        limit: 200,
      },
    })
    const list = Array.isArray(res?.data) ? res.data : (Array.isArray(res?.data?.data) ? res.data.data : [])
    const mapped = mapOpenAiUiMessages(list, { filterToolCallPlaceholder: true }) as ChatMessage[]
    if (!mapped.length)
      return
    chatHistory.value.push(...mapped)
    scrollToBottomIfNeeded()
  }
  catch {
  }
}

function stopMessagePolling() {
  if (messagePollTimer.value) {
    window.clearInterval(messagePollTimer.value)
    messagePollTimer.value = null
  }
}

function startMessagePolling() {
  stopMessagePolling()
  if (!activeSessionId.value)
    return
  messagePollCount.value = 0
  messagePollTimer.value = window.setInterval(() => {
    void pollMessages()
  }, MESSAGE_POLL_INTERVAL)
}

function handleAbort() {
  controller.value?.abort()
  controller.value = null
  sending.value = false
  streamStatus.value = null
}

function isSessionBusyError(input: unknown) {
  const text = String((input as any)?.message || input || '').trim()
  return text.includes(SESSION_BUSY_MESSAGE)
}

function applyAttachmentSupport() {
  const matched = availableAgents.value.find((item: any) => item.code === agentCode.value)
  const attachments = matched?.model?.attachments || matched?.attachments || matched?.model?.options?.attachments || {}
  const enabled = attachments?.enabled && typeof attachments.enabled === 'object' ? attachments.enabled : {}
  const localParse = attachments?.local_parse && typeof attachments.local_parse === 'object' ? attachments.local_parse : {}
  const parse = attachments?.parse && typeof attachments.parse === 'object' ? attachments.parse : {}
  const parseProviderId = Number(parse.parse_provider_id || 0)
  const canLocalParseByDriver = parseProviderId > 0

  const imageEnabled = enabled.image !== false
  const fileEnabled = enabled.file !== false
  const imageLocalParse = localParse.image !== false
  const fileLocalParse = localParse.file !== false

  supportImage.value = imageEnabled || (imageLocalParse && canLocalParseByDriver)
  supportFile.value = Boolean(
    fileEnabled
    || enabled.audio
    || enabled.video
    || (fileLocalParse && canLocalParseByDriver),
  )
}

async function handleSend() {
  const content = inputText.value.trim()
  const hasImages = pendingImages.value.length > 0
  const hasFiles = pendingFiles.value.length > 0
  const hasText = content !== ''
  const hasAttachment = hasImages || hasFiles
  if (!agentCode.value || sending.value)
    return
  if (!hasText && !hasAttachment) {
    message.warning('请输入消息内容')
    return
  }

  const userMsg: ChatMessage = {
    role: 'user',
    content: content || (hasImages ? '发送图片' : '发送文件'),
    meta: {
      id: createLocalMessageId('user'),
      created_at: dayjs().format('YYYY-MM-DD HH:mm:ss'),
      images: pendingImages.value.map(f => ({
        url: f.url,
        filename: f.filename,
        content: f.content,
        mode_hint: f.mode_hint,
        parse_mode: f.parse_mode,
        parsed_text: f.parsed_text,
        bytes: f.bytes,
        mime_type: f.mime_type,
      })),
      files: pendingFiles.value.map(f => ({
        url: f.url,
        filename: f.filename,
        content: f.content,
        mode_hint: f.mode_hint,
        media_kind: f.media_kind,
        mime_type: f.mime_type,
        parse_mode: f.parse_mode,
        parsed_text: f.parsed_text,
        bytes: f.bytes,
      })),
    },
  }
  const assistantPlaceholder: ChatMessage = {
    role: 'assistant',
    content: '',
    meta: {
      id: createLocalMessageId('assistant'),
      created_at: dayjs().format('YYYY-MM-DD HH:mm:ss'),
      pending: true,
    },
  }

  chatHistory.value.push(userMsg)
  chatHistory.value.push(assistantPlaceholder)
  scrollToBottom()
  pendingImages.value = []
  pendingFiles.value = []
  inputText.value = ''
  sending.value = true
  sessionNotice.value = ''
  streamStatus.value = null

  const streamUrl = manage.getApiUrl(`${apiBase}/chat/completions`)
  const signalController = new AbortController()
  controller.value = signalController

  const body = {
    model: agentCode.value,
    messages: buildPayloadMessages([userMsg]),
    session_id: activeSessionId.value,
    stream: true,
  }

  let currentAssistantIndex: number | null = chatHistory.value.length - 1
  let currentAssistantMessageId: string | null = null

  fetchEventSource(streamUrl, {
    method: 'POST',
    headers: headers(),
    body: JSON.stringify(body),
    signal: signalController.signal,
    credentials: 'include',
    onmessage(ev) {
      if (ev.data === '[DONE]') {
        cleanupPendingAssistant(currentAssistantIndex)
        sending.value = false
        controller.value = null
        streamStatus.value = null
        return
      }
      try {
        const payload = JSON.parse(ev.data)
        const choice = payload?.choices?.[0]
        const deltaContent = choice?.delta?.content
        const messageId = typeof payload?.id === 'string' ? payload.id : null

        if (payload?.session_id && !activeSessionId.value)
          activeSessionId.value = Number(payload.session_id)

        if (payload?.error) {
          const errMsg = payload.error?.message || '工具/模型返回错误'
          if (currentAssistantIndex !== null && chatHistory.value[currentAssistantIndex]) {
            chatHistory.value[currentAssistantIndex].content = errMsg
            chatHistory.value[currentAssistantIndex].meta = {
              ...(chatHistory.value[currentAssistantIndex].meta || {}),
              error: payload.error,
              pending: false,
            }
          }
          else {
            appendMessage({ role: 'assistant', content: errMsg, meta: { error: payload.error } })
          }
          sending.value = false
          controller.value = null
          streamStatus.value = null
          return
        }

        if (payload?.status && typeof payload.status === 'object') {
          streamStatus.value = {
            code: String(payload.status.code || ''),
            label: String(payload.status.label || '处理中'),
            meta: payload.status.meta && typeof payload.status.meta === 'object' ? payload.status.meta : {},
          }
          if (String(payload.status.code || '') === 'approval_required') {
            void loadMessages(activeSessionId.value)
          }
        }

        if (choice?.finish_reason === 'stop') {
          sending.value = false
          controller.value = null
          streamStatus.value = null
        }

        const hasDeltaContent = typeof deltaContent === 'string'
          ? deltaContent.length > 0
          : Array.isArray(deltaContent) || (!!deltaContent && typeof deltaContent === 'object')

        if (hasDeltaContent) {
          const shouldStartNew = currentAssistantIndex === null
            || (messageId && currentAssistantMessageId && messageId !== currentAssistantMessageId)
          if (shouldStartNew) {
            let initialContent = ''
            const initialMeta: Record<string, any> = {
              created_at: dayjs().format('YYYY-MM-DD HH:mm:ss'),
            }
            if (typeof deltaContent === 'string') {
              initialContent = deltaContent
            }
            if (currentAssistantIndex !== null && chatHistory.value[currentAssistantIndex] && !hasVisibleMessageContent(chatHistory.value[currentAssistantIndex])) {
              chatHistory.value[currentAssistantIndex].content = initialContent
              chatHistory.value[currentAssistantIndex].meta = {
                ...(chatHistory.value[currentAssistantIndex].meta || {}),
                ...initialMeta,
                pending: false,
              }
            }
            else {
              chatHistory.value.push({
                role: 'assistant',
                content: initialContent,
                meta: initialMeta,
              })
              currentAssistantIndex = chatHistory.value.length - 1
            }
            currentAssistantMessageId = messageId
            if (Array.isArray(deltaContent)) {
              appendAssistantDeltaParts(chatHistory.value[currentAssistantIndex], deltaContent)
            }
            else if (deltaContent && typeof deltaContent === 'object') {
              appendAssistantDeltaParts(chatHistory.value[currentAssistantIndex], [deltaContent])
            }
          }
          else if (currentAssistantIndex !== null) {
            if (typeof deltaContent === 'string') {
              chatHistory.value[currentAssistantIndex].content += deltaContent
            }
            else if (Array.isArray(deltaContent)) {
              appendAssistantDeltaParts(chatHistory.value[currentAssistantIndex], deltaContent)
            }
            else if (deltaContent && typeof deltaContent === 'object') {
              appendAssistantDeltaParts(chatHistory.value[currentAssistantIndex], [deltaContent])
            }
          }
          const currentIndex = currentAssistantIndex
          if (currentIndex !== null) {
            const currentMsg = chatHistory.value[currentIndex]
            const structured = typeof currentMsg.content === 'string' ? tryParseStructuredFromText(currentMsg.content) : null
            if (structured) {
              currentMsg.meta = currentMsg.meta || {}
              if (Array.isArray(structured.card))
                currentMsg.meta.card = structured.card
              if (Array.isArray(structured.images) && structured.images.length) {
                currentMsg.meta.images = structured.images.map(url => ({ url }))
              }
              if (Array.isArray(structured.videos) && structured.videos.length) {
                currentMsg.meta.videos = structured.videos.map(url => ({ url }))
              }
              currentMsg.content = structured.text || ''
            }
          }
          scrollToBottom()
        }

        if (payload && Object.prototype.hasOwnProperty.call(payload, 'tool_result')) {
          const toolResult = payload.tool_result
          const toolText = typeof toolResult === 'string' && toolResult.trim() !== ''
            ? toolResult
            : (payload.tool_label || payload.tool || '工具')
          const toolMeta: Record<string, any> = {
            tool: payload.tool,
            tool_label: payload.tool_label || payload.tool,
            message_id: payload.message_id,
            created_at: dayjs().format('YYYY-MM-DD HH:mm:ss'),
          }
          chatHistory.value.push({
            role: 'tool',
            content: toolText,
            meta: toolMeta,
          })
          scrollToBottom()
        }

      }
      catch {
      }
    },
    onclose() {
      cleanupPendingAssistant(currentAssistantIndex)
      sending.value = false
      controller.value = null
      streamStatus.value = null
    },
    onerror(err) {
      cleanupPendingAssistant(currentAssistantIndex)
      sending.value = false
      controller.value = null
      streamStatus.value = null
      if (isSessionBusyError(err)) {
        sessionNotice.value = SESSION_BUSY_MESSAGE
        return
      }
      message.error(err?.message || '会话连接失败')
      throw err
    },
  }).catch((err) => {
    if (err?.name === 'AbortError')
      return
    cleanupPendingAssistant(currentAssistantIndex)
    sending.value = false
    controller.value = null
    streamStatus.value = null
    if (isSessionBusyError(err)) {
      sessionNotice.value = SESSION_BUSY_MESSAGE
      return
    }
    message.error(err?.message || '会话连接失败')
  })
}

function handleInputKeydown(event: KeyboardEvent) {
  if (event.key === 'Enter' && !event.shiftKey) {
    event.preventDefault()
    handleSend()
  }
}

async function loadAvailableAgents() {
  loadingAgents.value = true
  try {
    const res = await requestClient.mutateAsync({
      path: 'ai/agent',
      method: 'GET',
    })
    const list = Array.isArray(res?.data?.data) ? res.data.data : Array.isArray(res?.data) ? res.data : []
    availableAgents.value = list
    applyAttachmentSupport()
  }
  catch (err: any) {
    message.error(err?.message || '加载智能体列表失败')
  }
  finally {
    loadingAgents.value = false
  }
}

function switchAgent(code: string) {
  if (code === agentCode.value)
    return
  preferredSessionId.value = null
  agentCode.value = code
}

function openAgentSelector() {
  if (sending.value || !availableAgents.value.length)
    return
  dialog.prompt({
    title: '切换智能体',
    defaultValue: { agent: agentCode.value },
    formSchema: [
      {
        tag: 'n-select',
        name: 'agent',
        label: '选择智能体',
        required: true,
        attrs: {
          'v-model:value': 'form.agent',
          'placeholder': '选择要切换的智能体',
          'options': availableAgents.value.map((item: any) => ({
            label: item.name || item.code,
            value: item.code,
          })),
        },
      },
    ],
  }).then(async (values: Record<string, any>) => {
    const nextCode = String(values?.agent ?? '').trim()
    if (nextCode && nextCode !== agentCode.value) {
      switchAgent(nextCode)
    }
  }).catch(() => {})
}

onBeforeUnmount(() => {
  handleAbort()
  stopMessagePolling()
})

watch(() => [props.code, routeAgentCode.value], ([propCode, queryCode]) => {
  const next = propCode || queryCode || ''
  if (next && agentCode.value !== next) {
    agentCode.value = next
  }
}, { immediate: true })

watch(() => [props.sessionId, routeSessionId.value], () => {
  const next = resolveInitialSessionId()
  preferredSessionId.value = next
  if (next && sessions.value.some(s => s.id === next))
    activeSessionId.value = next
}, { immediate: true })

watch(agentCode, (code, prev) => {
  if (prev === code)
    return
  handleAbort()
  chatHistory.value = []
  sessions.value = []
  activeSessionId.value = preferredSessionId.value
  if (code) {
    loadAvailableAgents().finally(() => {
      loadSessions()
    })
  }
  else {
    loadAvailableAgents()
  }
}, { immediate: true })

watch(activeSessionId, (val, prev) => {
  if (val === prev)
    return
  if (prev !== null && prev !== undefined)
    handleAbort()
  stopMessagePolling()
  sessionNotice.value = ''
  if (val) {
    loadMessages(val)
    startMessagePolling()
    // 移动端选择会话后自动关闭侧边栏
    showMobileSidebar.value = false
  }
  else { chatHistory.value = [] }
}, { immediate: true })
</script>

<template>
  <DuxPage :scrollbar="false" :padding="false">
    <div class="h-full flex flex-col">
      <!-- 主体内容区 -->
      <div class="flex-1 flex min-h-0 relative">
        <!-- 移动端遮罩层 -->
        <div
          v-if="showMobileSidebar"
          class="fixed inset-0 bg-black/50 z-40 md:hidden"
          @click="showMobileSidebar = false"
        />

        <!-- 左侧边栏 -->
        <div
          class="fixed md:relative inset-y-0 left-0 z-50 md:z-0 w-70 flex-none border-r border-muted bg-white dark:bg-gray-950 flex flex-col transition-transform duration-300 md:translate-x-0"
          :class="showMobileSidebar ? 'translate-x-0' : '-translate-x-full'"
        >
          <!-- 移动端关闭按钮 -->
          <div class="md:hidden flex items-center justify-between px-4 py-3 border-b border-muted">
            <div class="text-sm font-semibold">
              会话管理
            </div>
            <button
              class="size-8 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 flex items-center justify-center"
              @click="showMobileSidebar = false"
            >
              <i class="i-tabler:x text-lg" />
            </button>
          </div>

          <!-- 当前智能体信息 -->
          <div class="flex-none p-4 border-b border-muted">
            <div v-if="currentAgent" class="space-y-3">
              <div class="flex items-start justify-between gap-2">
                <div class="flex-1 min-w-0">
                  <div class="flex items-center gap-2 mb-1">
                    <div class="p-3 rounded-lg bg-primary/10 text-primary flex items-center justify-center flex-none">
                      <i class="i-tabler:robot size-6" />
                    </div>
                    <div class="flex-1 min-w-0">
                      <div class="text-base font-semibold truncate">
                        {{ currentAgent.name }}
                      </div>
                      <div class="text-sm text-muted truncate">
                        {{ currentAgent.desc || currentAgent.code }}
                      </div>
                    </div>
                  </div>
                </div>
                <NButton
                  size="small"
                  text
                  :disabled="sending || loadingAgents"
                  @click="openAgentSelector"
                >
                  <template #icon>
                    <i class="i-tabler:switch-horizontal" />
                  </template>
                </NButton>
              </div>
              <NButton
                type="primary"
                class="w-full"
                block
                :disabled="sending"
                @click="createSession"
              >
                <template #icon>
                  <i class="i-tabler:plus" />
                </template>
                新建会话
              </NButton>
            </div>
            <div v-else class="text-center py-6">
              <div v-if="loadingAgents" class="flex justify-center">
                <NSpin size="small" />
              </div>
              <div v-else class="space-y-3">
                <div class="text-sm text-muted">
                  未选择智能体
                </div>
                <NButton size="small" @click="loadAvailableAgents">
                  <template #icon>
                    <i class="i-tabler:refresh" />
                  </template>
                  加载智能体
                </NButton>
              </div>
            </div>
          </div>

          <!-- 会话列表 -->
          <div class="flex-1 overflow-hidden flex flex-col">
            <div class="flex-none px-4 py-3 flex items-center justify-between">
              <div class="text-xs font-medium text-muted uppercase tracking-wider">
                会话列表
              </div>
              <NTag size="small" :bordered="false" round>
                {{ sessions.length }}
              </NTag>
            </div>

            <div class="flex-1 overflow-y-auto px-3 pb-3">
              <div v-if="sessions.length && !loadingSessions" class="space-y-1.5">
                <div
                  v-for="item in sessions"
                  :key="item.id"
                  class="group relative cursor-pointer rounded-lg px-3 py-2.5 transition-all duration-200"
                  :class="activeSessionId === item.id
                    ? 'bg-primary/10 text-primary'
                    : 'hover:bg-gray-100 dark:hover:bg-gray-800 text-gray-700 dark:text-gray-300'"
                  @click="activeSessionId = item.id"
                >
                  <div class="flex items-start gap-2.5">
                    <div
                      class="flex-none size-8 rounded-lg flex items-center justify-center mt-0.5"
                      :class="activeSessionId === item.id
                        ? 'bg-primary/15 text-primary'
                        : 'bg-gray-100 dark:bg-gray-800 text-muted'"
                    >
                      <i class="i-tabler:message text-sm" />
                    </div>
                    <div class="flex-1 min-w-0">
                      <div class="text-sm font-medium truncate leading-tight">
                        {{ item.title || `会话 #${item.id}` }}
                      </div>
                      <div class="text-xs text-gray-500 dark:text-gray-500 truncate mt-1">
                        {{ item.last_message_at || item.created_at || '刚刚' }}
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div v-if="!loadingSessions && !sessions.length" class="py-16 flex items-center justify-center">
                <NEmpty description="暂无会话" size="small" />
              </div>

              <div v-if="loadingSessions" class="flex justify-center py-16">
                <NSpin size="medium" />
              </div>
            </div>
          </div>
        </div>

        <!-- 右侧聊天区 -->
        <div class="relative flex-1 flex flex-col min-w-0 bg-muted">
          <!-- 会话头部 -->
          <div
            v-if="activeSessionId"
            class="flex-none px-4 py-3 border-b border-muted bg-white dark:bg-gray-950"
          >
            <div class="flex items-center justify-between gap-3">
              <!-- 左侧：移动端菜单 + 会话信息 -->
              <div class="flex items-center gap-4 md:gap-3 flex-1 min-w-0">
                <!-- 移动端菜单按钮 -->
                <div class="md:hidden flex items-center">
                  <NButton
                    text
                    @click="showMobileSidebar = !showMobileSidebar"
                  >
                    <template #icon>
                      <div class="flex justify-center items-center">
                        <i class="i-tabler:menu-2 size-5" />
                      </div>
                    </template>
                  </NButton>
                </div>

                <!-- 会话图标和信息 -->
                <div class="hidden size-9 md:size-10 rounded-lg bg-primary/10 text-primary md:flex items-center justify-center flex-none">
                  <i class="i-tabler:message text-lg md:text-xl" />
                </div>
                <div class="min-w-0 flex-1">
                  <div class="flex items-center gap-4">
                    <div class="text-sm md:text-base font-semibold truncate">
                      {{ sessions.find(s => s.id === activeSessionId)?.title || `会话 #${activeSessionId}` }}
                    </div>
                    <div v-if="sending && streamStatusText" class="flex items-center gap-1.5 px-2.5 py-1 rounded-full" :class="streamStatusTone === 'warning' ? 'bg-amber-500/10 text-amber-600' : streamStatusTone === 'info' ? 'bg-sky-500/10 text-sky-600' : 'bg-primary/10 text-primary'">
                      <span class="size-1.5 rounded-full bg-primary animate-bounce" style="animation-delay: 0ms" />
                      <span class="size-1.5 rounded-full bg-primary animate-bounce" style="animation-delay: 150ms" />
                      <span class="size-1.5 rounded-full bg-primary animate-bounce" style="animation-delay: 300ms" />
                      <span class="text-xs font-medium ml-0.5">{{ streamStatusText }}</span>
                    </div>
                  </div>
                  <div class="text-xs text-muted mt-0.5">
                    {{ chatHistory.length }} 条消息
                  </div>
                </div>
              </div>

              <!-- 右侧：操作按钮组 -->
              <div class="flex items-center gap-1.5">
                <!-- 刷新按钮 -->
                <NButton
                  circle
                  secondary
                  :disabled="sending"
                  title="刷新会话列表"
                  @click="loadSessions"
                >
                  <template #icon>
                    <i class="i-tabler:refresh" />
                  </template>
                </NButton>

                <!-- 编辑按钮 -->
                <NButton
                  circle
                  secondary
                  title="重命名会话"
                  @click="openRenameDialog"
                >
                  <template #icon>
                    <i class="i-tabler:edit" />
                  </template>
                </NButton>

                <!-- 删除按钮 -->
                <NButton
                  circle
                  secondary
                  type="error"
                  title="删除会话"
                  @click="removeSession(activeSessionId)"
                >
                  <template #icon>
                    <i class="i-tabler:trash" />
                  </template>
                </NButton>
              </div>
            </div>
          </div>

          <!-- 消息区域 -->
          <div class="flex-1 overflow-hidden relative">
            <div v-if="activeSessionId" class="h-full flex flex-col">
              <div ref="chatBodyRef" class="flex-1 overflow-y-auto p-6" @scroll="updateScrollAnchor">
                <div class="max-w-4xl mx-auto space-y-5 md:space-y-6">
                  <div
                    v-for="(msg, index) in chatHistory"
                    :key="msg.meta?.id ?? index"
                    class="flex gap-2.5 md:gap-3 animate-fade-in"
                    :class="msg.role === 'user' ? 'justify-end' : 'justify-start'"
                  >
                    <!-- 左侧头像 -->
                    <div v-if="msg.role !== 'user'" class="flex-none">
                      <div
                        class="size-9 md:size-10 rounded-full flex items-center justify-center"
                        :class="msg.role === 'assistant'
                          ? 'bg-gradient-to-br from-primary via-primary to-primary/80 ring-primary/20'
                          : msg.role === 'tool'
                            ? 'bg-gradient-to-br from-amber-400 via-amber-500 to-amber-600 ring-amber-500/20'
                            : 'bg-gradient-to-br from-gray-400 via-gray-500 to-gray-600 ring-gray-500/20'"
                      >
                        <i
                          class="text-lg md:text-xl text-white"
                          :class="msg.role === 'assistant' ? 'i-tabler:robot' : msg.role === 'tool' ? 'i-tabler:tool' : 'i-tabler:message-circle'"
                        />
                      </div>
                    </div>

                    <!-- 消息气泡 -->
                    <div class="flex flex-col gap-1.5 max-w-[85%] md:max-w-[75%]">
                      <!-- 发送者名称 -->
                      <div
                        v-if="msg.role !== 'user'"
                        class="text-sm font-semibold px-3"
                        :class="msg.role === 'assistant'
                          ? 'text-primary'
                          : msg.role === 'tool'
                            ? 'text-warning'
                            : 'text-muted'"
                      >
                        {{ renderRoleLabel(msg) }}
                      </div>

                      <div
                        class="group relative rounded-2xl px-4 md:px-5 py-3 md:py-3.5 text-[14px] md:text-[15px] leading-relaxed transition-all duration-200"
                        :class="msg.role === 'user'
                          ? 'bg-primary text-white shadow-md shadow-primary/20 rounded-tr-sm'
                          : msg.role === 'assistant'
                            ? 'bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700 shadow-sm rounded-tl-sm'
                            : 'bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800/40 rounded-tl-sm'"
                      >
                        <!-- 消息内容 -->
                        <div v-if="msg.meta?.approval && typeof msg.meta.approval === 'object'" class="space-y-3 max-w-[280px]">
                          <div class="flex items-center justify-between gap-3">
                            <div class="text-sm font-semibold">
                              审批请求
                            </div>
                            <NTag size="small" round :bordered="false" :type="approvalStatusType(msg.meta.approval.status)">
                              {{ approvalStatusText(msg.meta.approval.status) }}
                            </NTag>
                          </div>
                          <div class="space-y-1.5 text-sm text-muted">
                            <div v-for="(row, ridx) in approvalRows(msg)" :key="`approval-row-${ridx}`" class="flex justify-between gap-3">
                              <div class="flex-none">
                                {{ row.name }}
                              </div>
                              <div class="text-right break-all text-default">
                                {{ row.value }}
                              </div>
                            </div>
                          </div>
                          <div v-if="canReplyApproval(msg)" class="flex justify-end gap-2">
                            <NButton secondary type="error" :disabled="sending" @click="handleApprovalReply('拒绝')">
                              拒绝
                            </NButton>
                            <NButton type="primary" :disabled="sending" @click="handleApprovalReply('同意')">
                              同意
                            </NButton>
                          </div>
                        </div>
                        <div v-else-if="Array.isArray(msg.meta?.card)" class="space-y-3">
                          <div
                            v-for="(card, cidx) in msg.meta.card"
                            :key="`card-${cidx}`"
                            class="flex flex-col gap-2 max-w-[230px]"
                          >
                            <div class="flex gap-2">
                              <div v-if="card.image" class="flex-none">
                                <NImage :src="card.image" width="80" height="80" class="max-h-48 max-w-full object-cover" object-fit="cover" />
                              </div>
                              <div class="flex-1 min-w-0">
                                <div v-if="card.title" class="text-sm font-semibold line-clamp-2">
                                  {{ card.title }}
                                </div>
                                <div v-if="card.desc" class="text-xs mt-1">
                                  {{ card.desc }}
                                </div>
                              </div>
                            </div>
                            <div v-if="Array.isArray(card.fields) && card.fields.length" class="space-y-1 text-sm text-muted">
                              <div v-for="(field, fidx) in card.fields" :key="`field-${cidx}-${fidx}`" class="flex justify-between gap-3">
                                <div>
                                  {{ field?.name || '' }}
                                </div>
                                <div>
                                  {{ field?.value || '' }}
                                </div>
                              </div>
                            </div>
                            <div v-if="Array.isArray(card.buttons) && card.buttons.length" class="flex flex-wrap justify-end gap-2">
                              <NButton
                                v-for="(btn, bidx) in card.buttons"
                                :key="`btn-${cidx}-${bidx}`"
                                type="primary"
                                secondary
                                :block="card.buttons.length === 1"
                                @click="handleCardAction(btn)"
                              >
                                {{ btn?.label || btn?.action || btn?.path || '操作' }}
                              </NButton>
                            </div>
                          </div>
                        </div>
                        <div v-else-if="isPendingAssistant(msg)" class="flex items-center gap-1.5 py-1">
                          <span class="size-1.5 rounded-full bg-primary/60 animate-bounce" style="animation-delay: 0ms" />
                          <span class="size-1.5 rounded-full bg-primary/60 animate-bounce" style="animation-delay: 150ms" />
                          <span class="size-1.5 rounded-full bg-primary/60 animate-bounce" style="animation-delay: 300ms" />
                        </div>
                        <div
                          v-else
                          class="prose prose-sm max-w-none prose-p:my-1.5 "
                          :class="msg.role === 'user'
                            ? 'text-white prose-p:leading-relaxed'
                            : msg.role === 'assistant'
                              ? 'text-default'
                              : msg.role === 'tool'
                                ? 'text-warning'
                                : 'text-default'"
                          v-html="renderMessageContent(msg)"
                        />

                        <!-- 图片 -->
                        <div v-if="msg.meta?.images?.length" class="grid grid-cols-2 gap-2 mt-3">
                          <img
                            v-for="(img, idx) in msg.meta.images"
                            :key="`img-${idx}`"
                            :src="img?.url || img"
                            alt="image"
                            :class="msg.meta.images.length === 1 ? 'col-span-2' : ''"
                            class="max-h-48 md:max-h-56 w-full object-cover rounded-2xl border-2 shadow-lg transition-all duration-300 cursor-pointer hover:scale-[1.02] hover:shadow-2xl"
                            :style="msg.role === 'user' ? 'border-color: rgba(255,255,255,0.3)' : 'border-color: rgba(0,0,0,0.05)'"
                          >
                        </div>

                        <!-- 视频 -->
                        <div v-if="msg.meta?.videos?.length" class="space-y-2 mt-3">
                          <video
                            v-for="(video, idx) in msg.meta.videos"
                            :key="`video-${idx}`"
                            :src="video?.url || video"
                            controls
                            preload="metadata"
                            class="w-full max-h-72 rounded-xl border"
                          />
                        </div>

                        <!-- 文件 -->
                        <div v-if="msg.meta?.files?.length" class="space-y-2 mt-3">
                          <div
                            v-for="(file, idx) in msg.meta.files"
                            :key="`file-${idx}`"
                            class="group/file flex items-center gap-2.5 px-3.5 md:px-4 py-2.5 md:py-3 rounded-xl text-sm font-medium transition-all duration-200 border"
                            :class="msg.role === 'user'
                              ? 'bg-white/15 hover:bg-white/25 text-white border-white/20 hover:border-white/30 backdrop-blur-sm'
                              : 'bg-gray-50 dark:bg-gray-900/50 hover:bg-gray-100 dark:hover:bg-gray-800 border-gray-200 dark:border-gray-700 hover:shadow-md'"
                          >
                            <div class="size-8 rounded-lg flex items-center justify-center flex-none" :class="msg.role === 'user' ? 'bg-white/20' : 'bg-primary/10'">
                              <i class="i-tabler:paperclip text-base" :class="msg.role === 'user' ? 'text-white' : 'text-primary'" />
                            </div>
                            <a :href="file.url || file" target="_blank" rel="noreferrer" class="truncate hover:underline flex-1 min-w-0">
                              {{ file.filename || file.url || file }}
                            </a>
                            <i class="i-tabler:external-link text-base opacity-50 group-hover/file:opacity-100 transition-opacity flex-none" />
                          </div>
                        </div>

                      </div>

                      <!-- 时间戳 -->
                      <div
                        class="text-xs px-3 flex items-center gap-1.5 mt-1"
                        :class="msg.role === 'user' ? 'text-gray-400 justify-end' : 'text-gray-500 dark:text-gray-500'"
                      >
                        <i class="i-tabler:clock text-xs" />
                        <span>{{ formatMessageTime(msg) }}</span>
                      </div>
                    </div>

                    <!-- 右侧头像 -->
                    <div v-if="msg.role === 'user'" class="flex-none">
                      <div class="size-9 md:size-10 rounded-full bg-primary flex items-center justify-center">
                        <i class="i-tabler:user text-lg md:text-xl text-white" />
                      </div>
                    </div>
                  </div>

                  <div class="h-[200px]" />
                </div>
              </div>

              <!-- 空状态 -->
              <div
                v-if="!chatHistory.length && !messagesLoading"
                class="absolute inset-0 flex flex-col items-center justify-center gap-5 text-gray-400"
              >
                <div class="relative">
                  <div class="size-24 rounded-3xl bg-primary flex items-center justify-center shadow-xl shadow-primary/5 backdrop-blur-sm border border-primary/10">
                    <i class="i-tabler:message-circle text-5xl text-white" />
                  </div>
                  <div class="absolute -bottom-2 -right-2 size-10 rounded-full bg-gradient-to-br from-primary/20 to-primary/10 flex items-center justify-center shadow-lg border border-primary/20">
                    <i class="i-tabler:sparkles text-base text-primary animate-pulse" />
                  </div>
                </div>
                <div class="text-center space-y-2">
                  <div class="text-lg font-semibold text-default">
                    开始新的对话
                  </div>
                  <div class="text-sm text-muted max-w-xs">
                    发送消息与 AI 助手开始智能对话
                  </div>
                </div>
              </div>

              <!-- 加载状态 -->
              <div v-if="messagesLoading && !chatHistory.length" class="absolute inset-0 flex items-center justify-center">
                <div class="text-center space-y-4">
                  <NSpin size="large" />
                  <div class="text-sm font-medium text-muted">
                    加载消息中...
                  </div>
                </div>
              </div>
            </div>

            <!-- 未选择会话 -->
            <div v-else class="h-full flex flex-col items-center justify-center gap-5">
              <div class="relative">
                <div class="size-24 rounded-3xl bg-primary to-transparent flex items-center justify-center shadow-xl border border-primary">
                  <i class="i-tabler:message-circle text-5xl text-white" />
                </div>
              </div>
              <div class="text-center space-y-2">
                <div class="text-lg font-semibold text-default">
                  未选择会话
                </div>
                <div class="text-sm text-muted">
                  从左侧选择或创建一个会话开始对话
                </div>
              </div>
            </div>
          </div>

          <!-- 输入区域 -->
          <div
            v-if="activeSessionId"
            class="absolute bottom-0 left-0 right-0 flex justify-center pointer-events-none px-4"
          >
              <div class="w-full max-w-4xl pointer-events-auto">
              <div v-if="!sending && sessionNotice" class="mb-2 flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50/95 px-3 py-2 text-xs text-amber-700 shadow-sm dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-300">
                <i class="i-tabler:clock-hour-4 text-sm" />
                <span>{{ sessionNotice }}</span>
              </div>
              <!-- 输入框容器 -->
              <div class="overflow-hidden bg-white dark:bg-gray-800 border rounded-xl border-gray-200 dark:border-gray-700 shadow-lg shadow-black/5">
                <!-- 附件预览 -->
                <div v-if="pendingImages.length || pendingFiles.length" class="flex flex-wrap gap-2 p-4 border-b border-muted dark:border-accented">
                  <!-- 图片附件 -->
                  <div
                    v-for="(img, idx) in pendingImages"
                    :key="`img-${idx}`"
                    class="group relative"
                  >
                    <div class="relative size-14 rounded-lg overflow-hidden border border-gray-200 dark:border-gray-700  shadow-sm hover:shadow transition-all">
                      <img
                        :src="img.url"
                        alt="preview"
                        class="size-full object-cover"
                      >
                      <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center">
                        <button
                          class="size-6 rounded-full bg-white/90 hover:bg-white flex items-center justify-center transition-colors"
                          @click="pendingImages.splice(idx, 1)"
                        >
                          <i class="i-tabler:x text-gray-700 text-sm" />
                        </button>
                      </div>
                    </div>
                    <div class="mt-1 text-[10px] text-center text-muted truncate max-w-[56px] md:max-w-[64px]">
                      {{ img.filename || '图片' }}
                    </div>
                  </div>

                  <!-- 文件附件 -->
                  <div
                    v-for="(file, idx) in pendingFiles"
                    :key="`file-${idx}`"
                    class="group relative inline-flex items-center gap-2 p-1.5 rounded-lg border border-muted shadow-sm hover:shadow transition-all"
                  >
                    <div class="size-8 rounded bg-muted flex items-center justify-center flex-none">
                      <i class="i-tabler:file-text text-base text-default" />
                    </div>
                    <div class="flex-1 min-w-0 pr-1">
                      <div class="text-xs font-medium text-gray-900 dark:text-gray-100 truncate max-w-[100px] md:max-w-[150px]">
                        {{ file.filename || '文件' }}
                      </div>
                    </div>
                    <button
                      class="size-5 rounded-full hover:bg-gray-100 dark:hover:bg-gray-700 flex items-center justify-center transition-colors flex-none"
                      @click="pendingFiles.splice(idx, 1)"
                    >
                      <i class="i-tabler:x text-muted text-xs" />
                    </button>
                  </div>
                </div>

                <!-- 输入框 -->
                <textarea
                  v-model="inputText"
                  rows="2"
                  placeholder="输入消息内容..."
                  :disabled="!agentCode || sending"
                  class="w-full px-3 pt-3 text-sm bg-transparent border-none outline-none resize-none text-default placeholder-muted disabled:opacity-50 disabled:cursor-not-allowed"
                  @keydown="handleInputKeydown"
                />

                <!-- 底部工具栏 -->
                <div class="flex items-center justify-between px-3 pb-3">
                  <input ref="imageInputRef" type="file" accept="image/*" class="hidden" @change="onImageChosen">
                  <input ref="fileInputRef" type="file" class="hidden" @change="onFileChosen">

                  <!-- 左侧信息 -->
                  <div class="flex items-center gap-2 text-sm text-muted">
                    <div v-if="uploading" class="flex items-center gap-1.5 text-xs">
                      <NSpin :size="14" />
                      <span class="hidden md:inline">上传中...</span>
                    </div>
                    <div v-else class="text-xs text-muted hidden md:block">
                      Enter 发送，Shift + Enter 换行
                    </div>
                  </div>

                  <!-- 右侧按钮组 -->
                  <div class="flex items-center gap-2">
                    <!-- 附件按钮 -->
                    <NButton
                      :disabled="!agentCode || uploading || !supportImage"
                      title="上传图片"
                      circle
                      secondary
                      @click="onSelectImage"
                    >
                      <template #icon>
                        <i class="i-tabler:photo text-muted" />
                      </template>
                    </NButton>

                    <!-- 文件按钮 -->
                    <NButton
                      circle
                      secondary
                      :disabled="!agentCode || uploading || !supportFile"
                      title="上传文件"
                      @click="onSelectFile"
                    >
                      <template #icon>
                        <i class="i-tabler:file text-muted" />
                      </template>
                    </NButton>

                    <!-- 发送按钮 -->
                    <NButton
                      circle
                      secondary
                      :type="sending || !inputText.trim() || !agentCode ? 'default' : 'primary'"
                      :disabled="sending || !inputText.trim() || !agentCode"
                      title="发送消息"
                      @click="handleSend"
                    >
                      <template #icon>
                        <i v-if="!sending" class="i-tabler:send text-lg" />
                        <NSpin v-else size="small" />
                      </template>
                    </NButton>

                    <!-- 停止按钮 -->
                    <NButton
                      v-if="sending"
                      circle
                      secondary
                      type="warning"
                      title="停止生成"
                      @click="handleAbort"
                    >
                      <template #icon>
                        <i class="i-tabler:player-stop text-lg" />
                      </template>
                    </NButton>
                  </div>
                </div>
              </div>
              <div class="h-4 bg-gradient-to-b from-transparent to-[var(--n-color)] dark:to-gray-950" />
            </div>
          </div>
        </div>
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
</style>
