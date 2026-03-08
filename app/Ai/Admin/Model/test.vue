<script setup lang="ts">
import { useCustomMutation } from '@duxweb/dvha-core'
import { DuxCodeEditor, DuxModalPage } from '@duxweb/dvha-pro'
import { marked } from 'marked'
import { NButton, NImage, NInput, NSelect, NSpin, NTag, useMessage } from 'naive-ui'
import { computed, nextTick, onMounted, ref, watch } from 'vue'

interface RunMessage {
  role: 'user' | 'assistant'
  text: string
  result?: any
  _htmlCache?: string
  _htmlCacheText?: string
}

const props = defineProps<{
  id?: number | string
  data?: Record<string, any>
}>()

const request = useCustomMutation()
const message = useMessage()
const loading = ref(false)
const inputText = ref('')
const outputMode = ref<'text' | 'structured' | 'auto'>('text')
const structuredSchemaText = ref('[]')
const chatRef = ref<HTMLDivElement | null>(null)
const messages = ref<RunMessage[]>([])
const showJsonIndex = ref<number | null>(null)
const modelInfo = ref<Record<string, any>>({})

const modelId = computed(() => Number(props.id || props.data?.id || 0))
const type = computed(() => String(modelInfo.value?.type || props.data?.type || 'chat'))
const modelName = computed(() => String(modelInfo.value?.name || props.data?.name || '未命名模型'))
const modelCode = computed(() => String(modelInfo.value?.code || props.data?.code || ''))
const remoteModel = computed(() => String(modelInfo.value?.model || props.data?.model || ''))
const providerName = computed(() => String(modelInfo.value?.provider || props.data?.provider || ''))

const placeholder = computed(() => {
  const map: Record<string, string> = {
    embedding: '输入要向量化的文本...',
    image: '输入图片生成提示词...',
    video: '输入视频生成提示词...',
  }
  return map[type.value] || '输入消息...'
})

const runModeOptions = [
  { label: '文本', value: 'text' },
  { label: '自动', value: 'auto' },
  { label: '结构化', value: 'structured' },
]

const typeLabel = computed(() => {
  const map: Record<string, string> = {
    chat: '会话',
    embedding: '向量',
    image: '图片',
    video: '视频',
  }
  return map[type.value] || type.value
})

const typeTagType = computed(() => {
  const map: Record<string, 'info' | 'success' | 'warning' | 'error'> = {
    chat: 'info',
    embedding: 'success',
    image: 'warning',
    video: 'error',
  }
  return map[type.value] || 'info'
})

async function loadModelInfo() {
  const id = modelId.value
  modelInfo.value = { ...(props.data || {}) }
  if (!id)
    return
  try {
    const res = await request.mutateAsync({
      path: `ai/model/${id}`,
      method: 'GET',
    })
    const info = res?.data || {}
    if (info && typeof info === 'object') {
      modelInfo.value = info
    }
  }
  catch {
    // 保留传入 data 兜底
  }
}

function renderHtml(msg: RunMessage): string {
  if (msg._htmlCacheText === msg.text && typeof msg._htmlCache === 'string')
    return msg._htmlCache
  const result = marked.parse(msg.text || '')
  const html = typeof result === 'string' ? result : ''
  msg._htmlCacheText = msg.text
  msg._htmlCache = html
  return html
}

function collectUrls(node: any, keys: string[], target: string[], depth = 0) {
  if (!node || depth > 6) return
  if (Array.isArray(node)) {
    node.forEach(item => collectUrls(item, keys, target, depth + 1))
    return
  }
  if (typeof node !== 'object') return
  keys.forEach((key) => {
    const url = String((node as any)[key] || '').trim()
    if (url && !target.includes(url)) target.push(url)
  })
  Object.values(node).forEach(v => collectUrls(v, keys, target, depth + 1))
}

function extractImageUrls(result: any): string[] {
  const urls: string[] = []
  if (Array.isArray(result?.images)) {
    result.images.forEach((item: any) => {
      const url = String(item || '').trim()
      if (url && !urls.includes(url)) urls.push(url)
    })
  }
  collectUrls(result?.response, ['url', 'image_url'], urls)
  return urls
}

function extractVideoUrls(result: any): string[] {
  const urls: string[] = []
  collectUrls(result?.response, ['url', 'video_url', 'output_url', 'download_url'], urls)
  return urls.filter(url =>
    /\.(mp4|mov|webm)$/i.test(url) || url.includes('.m3u8'),
  )
}

function scrollToBottom() {
  nextTick(() => {
    if (chatRef.value)
      chatRef.value.scrollTop = chatRef.value.scrollHeight
  })
}

async function runModel() {
  const id = Number(props.id || props.data?.id || 0)
  const text = inputText.value.trim()
  if (!id || !text || loading.value) return

  messages.value.push({ role: 'user', text })
  inputText.value = ''
  scrollToBottom()

  loading.value = true
  try {
    let structuredSchema: any[] = []
    if (type.value === 'chat' && outputMode.value !== 'text') {
      try {
        const parsed = JSON.parse(structuredSchemaText.value || '[]')
        structuredSchema = Array.isArray(parsed) ? parsed : []
      }
      catch {
        message.error('结构化 Schema 必须是 JSON 数组')
        return
      }
    }
    const payload: Record<string, any> = type.value === 'embedding'
      ? { text }
      : { prompt: text }
    if (type.value === 'chat') {
      payload.output_mode = outputMode.value
      payload.structured_schema = structuredSchema
    }

    const res = await request.mutateAsync({
      path: `ai/model/${id}/test`,
      method: 'POST',
      payload,
    })
    const result = res?.data ?? {}
    const contentText = typeof result?.content === 'string' ? result.content.trim() : ''
    const summaryText = typeof result?.summary === 'string' ? result.summary.trim() : ''

    messages.value.push({
      role: 'assistant',
      text: contentText || summaryText || '运行完成',
      result,
    })
  }
  catch (err: any) {
    message.error(err?.message || '运行失败')
    messages.value.push({
      role: 'assistant',
      text: err?.message || '运行失败',
      result: { error: true },
    })
  }
  finally {
    loading.value = false
    scrollToBottom()
  }
}

function handleKeydown(event: KeyboardEvent) {
  if (event.key === 'Enter' && !event.shiftKey) {
    event.preventDefault()
    runModel()
  }
}

function toggleJson(index: number) {
  showJsonIndex.value = showJsonIndex.value === index ? null : index
}

onMounted(() => {
  loadModelInfo()
})

watch(
  () => [props.id, props.data?.id],
  () => {
    loadModelInfo()
  },
)
</script>

<template>
  <DuxModalPage title="模型运行测试">
    <div class="h-[72vh] flex flex-col">
      <!-- 头部信息 -->
      <div class="flex-none flex items-center gap-3 pb-3 border-b border-gray-100 dark:border-gray-800">
        <div class="size-9 rounded-xl bg-primary/10 flex items-center justify-center">
          <i class="i-tabler:cpu text-lg text-primary" />
        </div>
        <div class="min-w-0 flex-1">
          <div class="text-sm font-semibold text-default truncate">{{ modelName }}</div>
          <div class="text-xs text-muted truncate">
            {{ providerName || '-' }} · {{ remoteModel || '-' }}
          </div>
        </div>
        <NTag :bordered="false" round :type="typeTagType">
          {{ typeLabel }}
        </NTag>
      </div>
      <div v-if="type === 'chat'" class="flex-none mt-3 mb-1 grid grid-cols-1 md:grid-cols-2 gap-2">
        <NSelect v-model:value="outputMode" :options="runModeOptions" />
        <NInput :value="outputMode === 'text' ? '文本模式不使用结构化 Schema' : 'Schema 见下方 JSON 编辑器'" readonly />
      </div>
      <div v-if="type === 'chat' && outputMode !== 'text'" class="flex-none mb-2 rounded-lg border border-gray-100 dark:border-gray-700 overflow-hidden">
        <DuxCodeEditor v-model:value="structuredSchemaText" language="json" height="140px" />
      </div>

      <!-- 消息区域 -->
      <div
        ref="chatRef"
        class="flex-1 overflow-y-auto py-4 px-2"
      >
        <!-- 空状态 -->
        <div v-if="!messages.length && !loading" class="h-full flex flex-col items-center justify-center gap-4 text-gray-400">
          <div class="size-16 rounded-2xl bg-primary/5 flex items-center justify-center">
            <i class="i-tabler:message-circle text-3xl text-primary/40" />
          </div>
          <div class="text-center space-y-1">
            <div class="text-base font-medium text-default">开始测试</div>
            <div class="text-sm text-muted">输入内容并发送，测试模型运行效果</div>
          </div>
        </div>

        <!-- 消息列表 -->
        <div v-else class="space-y-5">
          <div
            v-for="(msg, idx) in messages"
            :key="`msg-${idx}`"
            class="flex gap-2.5 animate-fade-in"
            :class="msg.role === 'user' ? 'justify-end' : 'justify-start'"
          >
            <!-- 助手头像 -->
            <div v-if="msg.role === 'assistant'" class="flex-none">
              <div class="size-8 rounded-full bg-gradient-to-br from-primary via-primary to-primary/80 flex items-center justify-center">
                <i class="i-tabler:robot text-sm text-white" />
              </div>
            </div>

            <!-- 消息气泡 -->
            <div class="flex flex-col gap-1 max-w-[80%]">
              <div
                class="relative rounded-2xl px-4 py-2.5 text-sm leading-relaxed shadow-sm"
                :class="msg.role === 'user'
                  ? 'bg-primary text-white rounded-tr-sm'
                  : msg.result?.error
                    ? 'bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-800 text-red-600 dark:text-red-400 rounded-tl-sm'
                    : 'bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700 rounded-tl-sm'"
              >
                <!-- 用户消息 -->
                <div v-if="msg.role === 'user'" class="whitespace-pre-wrap">
                  {{ msg.text }}
                </div>

                <!-- 助手消息 -->
                <template v-else>
                  <div
                    class="prose prose-sm max-w-none prose-p:my-1"
                    :class="msg.result?.error ? 'text-red-600 dark:text-red-400' : 'text-default'"
                    v-html="renderHtml(msg)"
                  />

                  <!-- 图片结果 -->
                  <div v-if="msg.result?.type === 'image' && extractImageUrls(msg.result).length" class="grid grid-cols-2 gap-2 mt-3">
                    <NImage
                      v-for="(url, iidx) in extractImageUrls(msg.result)"
                      :key="`img-${idx}-${iidx}`"
                      :src="url"
                      width="180"
                      object-fit="cover"
                      class="rounded-lg"
                    />
                  </div>

                  <!-- 视频结果 -->
                  <div v-if="msg.result?.type === 'video' && extractVideoUrls(msg.result).length" class="mt-3 space-y-1.5">
                    <a
                      v-for="(url, vidx) in extractVideoUrls(msg.result)"
                      :key="`video-${idx}-${vidx}`"
                      :href="url"
                      target="_blank"
                      rel="noreferrer"
                      class="flex items-center gap-2 px-3 py-2 rounded-lg bg-gray-50 dark:bg-gray-900 text-xs text-primary hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
                    >
                      <i class="i-tabler:player-play flex-none" />
                      <span class="truncate">{{ url }}</span>
                    </a>
                  </div>

                  <!-- 向量结果 -->
                  <div v-if="msg.result?.type === 'embedding'" class="mt-2 space-y-1 text-xs text-muted">
                    <div class="flex items-center gap-1.5">
                      <i class="i-tabler:vector" />
                      <span>维度：{{ msg.result.dimensions || 0 }}</span>
                    </div>
                    <div v-if="Array.isArray(msg.result.vector_preview) && msg.result.vector_preview.length" class="font-mono text-xs opacity-70 break-all">
                      [{{ msg.result.vector_preview.join(', ') }}]
                    </div>
                  </div>
                </template>
              </div>

              <!-- 消息底部信息 -->
              <div v-if="msg.role === 'assistant' && msg.result && !msg.result.error" class="flex items-center gap-3 px-1 text-xs text-muted">
                <span v-if="msg.result?.usage" class="flex items-center gap-1">
                  <i class="i-tabler:coins" />
                  {{ msg.result.usage.total_tokens ?? 0 }} tokens
                </span>
                <button
                  class="flex items-center gap-1 hover:text-primary transition-colors cursor-pointer"
                  @click="toggleJson(idx)"
                >
                  <i class="i-tabler:code" />
                  {{ showJsonIndex === idx ? '收起' : 'JSON' }}
                </button>
              </div>

              <!-- JSON 展开 -->
              <div v-if="showJsonIndex === idx && msg.result && !msg.result.error" class="mt-1 rounded-lg overflow-hidden border border-gray-100 dark:border-gray-700">
                <DuxCodeEditor readonly :value="JSON.stringify(msg.result, null, 2)" />
              </div>
            </div>

            <!-- 用户头像 -->
            <div v-if="msg.role === 'user'" class="flex-none">
              <div class="size-8 rounded-full bg-primary flex items-center justify-center">
                <i class="i-tabler:user text-sm text-white" />
              </div>
            </div>
          </div>

          <!-- 加载中动画 -->
          <div v-if="loading" class="flex gap-2.5 justify-start animate-fade-in">
            <div class="flex-none">
              <div class="size-8 rounded-full bg-gradient-to-br from-primary via-primary to-primary/80 flex items-center justify-center">
                <i class="i-tabler:robot text-sm text-white" />
              </div>
            </div>
            <div class="bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700 rounded-2xl rounded-tl-sm px-4 py-3 shadow-sm">
              <div class="flex items-center gap-1.5">
                <span class="size-1.5 rounded-full bg-primary/60 animate-bounce" style="animation-delay: 0ms" />
                <span class="size-1.5 rounded-full bg-primary/60 animate-bounce" style="animation-delay: 150ms" />
                <span class="size-1.5 rounded-full bg-primary/60 animate-bounce" style="animation-delay: 300ms" />
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- 输入区域 -->
      <div class="flex-none pt-3 border-t border-gray-100 dark:border-gray-800">
        <div class="overflow-hidden bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl">
          <textarea
            v-model="inputText"
            rows="2"
            :placeholder="placeholder"
            :disabled="loading"
            class="w-full px-4 pt-3 text-sm bg-transparent border-none outline-none resize-none text-default placeholder-gray-400 disabled:opacity-50 disabled:cursor-not-allowed"
            @keydown="handleKeydown"
          />
          <div class="flex items-center justify-between px-3 pb-2.5">
            <div class="text-xs text-muted">
              Enter 发送，Shift + Enter 换行
            </div>
            <NButton
              circle
              :type="loading || !inputText.trim() ? 'default' : 'primary'"
              :disabled="loading || !inputText.trim()"
              @click="runModel"
            >
              <template #icon>
                <NSpin v-if="loading" :size="14" />
                <i v-else class="i-tabler:send" />
              </template>
            </NButton>
          </div>
        </div>
      </div>
    </div>
  </DuxModalPage>
</template>

<style scoped>
@keyframes fade-in {
  from {
    opacity: 0;
    transform: translateY(8px);
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
