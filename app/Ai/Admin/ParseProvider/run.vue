<script setup lang="ts">
import { useCustomMutation } from '@duxweb/dvha-core'
import { DuxCodeEditor, DuxModalPage } from '@duxweb/dvha-pro'
import { marked } from 'marked'
import { NButton, NTag, useMessage } from 'naive-ui'
import { computed, onMounted, ref, watch } from 'vue'

const props = defineProps<{
  id?: number | string
  data?: Record<string, any>
}>()

const request = useCustomMutation()
const message = useMessage()
const loading = ref(false)
const selectedFile = ref<File | null>(null)
const result = ref<Record<string, any> | null>(null)
const fileInputRef = ref<HTMLInputElement | null>(null)
const dragging = ref(false)
const showJson = ref(false)
const providerInfo = ref<Record<string, any>>({})

const providerId = computed(() => Number(props.id || props.data?.id || 0))
const providerName = computed(() => String(providerInfo.value?.provider_name || props.data?.provider_name || '-'))
const configName = computed(() => String(providerInfo.value?.name || props.data?.name || '解析配置'))
const configCode = computed(() => String(providerInfo.value?.code || props.data?.code || ''))

const filePreview = computed(() => {
  if (!selectedFile.value) return null
  const file = selectedFile.value
  const ext = file.name.split('.').pop()?.toLowerCase() || ''
  const isImage = ['png', 'jpg', 'jpeg', 'webp', 'bmp', 'gif'].includes(ext)
  return { name: file.name, size: formatSize(file.size), ext, isImage }
})

function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

const resultHtml = computed(() => {
  const text = result.value?.content || ''
  if (!text) return ''
  const html = marked.parse(text)
  return typeof html === 'string' ? html : ''
})

async function loadProviderInfo() {
  providerInfo.value = { ...(props.data || {}) }
  const id = providerId.value
  if (!id) return
  try {
    const res = await request.mutateAsync({ path: `ai/parseProvider/${id}`, method: 'GET' })
    const info = res?.data || {}
    if (info && typeof info === 'object') providerInfo.value = info
  }
  catch {}
}

function pickFile() {
  fileInputRef.value?.click()
}

function onFileChange(event: Event) {
  const input = event.target as HTMLInputElement
  selectedFile.value = input.files?.[0] || null
  input.value = ''
  result.value = null
  showJson.value = false
}

function onDrop(event: DragEvent) {
  dragging.value = false
  const file = event.dataTransfer?.files?.[0]
  if (file) {
    selectedFile.value = file
    result.value = null
    showJson.value = false
  }
}

function removeFile() {
  selectedFile.value = null
  result.value = null
  showJson.value = false
}

async function runParse() {
  const id = providerId.value
  if (!id) return message.error('缺少配置 ID')
  if (!selectedFile.value) return message.warning('请先选择要解析的文件')

  loading.value = true
  result.value = null
  showJson.value = false
  try {
    const form = new FormData()
    form.append('file', selectedFile.value, selectedFile.value.name)
    const res = await request.mutateAsync({
      path: `ai/parseProvider/${id}/run`,
      method: 'POST',
      payload: form,
    })
    result.value = res?.data || {}
    message.success('解析完成')
  }
  catch (err: any) {
    message.error(err?.message || '运行失败')
  }
  finally {
    loading.value = false
  }
}

onMounted(() => loadProviderInfo())
watch(() => [props.id, props.data?.id], () => loadProviderInfo())
</script>

<template>
  <DuxModalPage title="解析运行测试">
    <div class="space-y-4">
      <!-- 头部信息 -->
      <div class="flex items-center gap-3">
        <div class="size-9 rounded-xl bg-primary/10 flex items-center justify-center">
          <i class="i-tabler:file-search text-lg text-primary" />
        </div>
        <div class="min-w-0 flex-1">
          <div class="text-sm font-semibold text-default truncate">{{ configName }}</div>
          <div class="text-xs text-muted truncate">
            {{ providerName }} <span v-if="configCode">· {{ configCode }}</span>
          </div>
        </div>
        <NTag :bordered="false" round type="info">
          解析测试
        </NTag>
      </div>

      <input
        ref="fileInputRef"
        type="file"
        class="hidden"
        accept=".pdf,.png,.jpg,.jpeg,.webp,.bmp,.gif"
        @change="onFileChange"
      >

      <!-- 上传区域 -->
      <div v-if="!selectedFile" class="relative">
        <div
          class="rounded-xl border-2 border-dashed transition-all duration-200 cursor-pointer"
          :class="dragging
            ? 'border-primary bg-primary/5'
            : 'border-gray-200 dark:border-gray-700 hover:border-primary/50 hover:bg-gray-50 dark:hover:bg-gray-900'"
          @click="pickFile"
          @dragover.prevent="dragging = true"
          @dragleave.prevent="dragging = false"
          @drop.prevent="onDrop"
        >
          <div class="flex flex-col items-center gap-3 py-10">
            <div class="size-12 rounded-xl flex items-center justify-center" :class="dragging ? 'bg-primary/10' : 'bg-gray-100 dark:bg-gray-800'">
              <i class="text-2xl" :class="dragging ? 'i-tabler:upload text-primary' : 'i-tabler:cloud-upload text-muted'" />
            </div>
            <div class="text-center">
              <div class="text-sm font-medium" :class="dragging ? 'text-primary' : 'text-default'">
                {{ dragging ? '松开上传文件' : '点击或拖拽文件到此处' }}
              </div>
              <div class="text-xs text-muted mt-1">
                支持 PDF、PNG、JPG、WEBP 等格式
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- 已选文件 -->
      <div v-else class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4">
        <div class="flex items-center gap-3">
          <div class="size-10 rounded-lg flex items-center justify-center flex-none" :class="filePreview?.isImage ? 'bg-green-50 dark:bg-green-950/30' : 'bg-blue-50 dark:bg-blue-950/30'">
            <i class="text-lg" :class="filePreview?.isImage ? 'i-tabler:photo text-green-500' : 'i-tabler:file-type-pdf text-blue-500'" />
          </div>
          <div class="flex-1 min-w-0">
            <div class="text-sm font-medium text-default truncate">{{ filePreview?.name }}</div>
            <div class="text-xs text-muted mt-0.5">
              {{ filePreview?.size }} · {{ filePreview?.ext?.toUpperCase() }}
            </div>
          </div>
          <div class="flex items-center gap-2 flex-none">
            <NButton size="small" quaternary circle title="移除文件" :disabled="loading" @click="removeFile">
              <template #icon>
                <i class="i-tabler:x" />
              </template>
            </NButton>
            <NButton size="small" quaternary circle title="更换文件" :disabled="loading" @click="pickFile">
              <template #icon>
                <i class="i-tabler:refresh" />
              </template>
            </NButton>
            <NButton type="primary" secondary size="small" :loading="loading" @click="runParse">
              <template #icon>
                <i class="i-tabler:player-play" />
              </template>
              解析
            </NButton>
          </div>
        </div>

        <!-- 加载动画 -->
        <div v-if="loading" class="mt-4 flex items-center gap-3 px-1">
          <div class="flex items-center gap-1.5">
            <span class="size-1.5 rounded-full bg-primary/60 animate-bounce" style="animation-delay: 0ms" />
            <span class="size-1.5 rounded-full bg-primary/60 animate-bounce" style="animation-delay: 150ms" />
            <span class="size-1.5 rounded-full bg-primary/60 animate-bounce" style="animation-delay: 300ms" />
          </div>
          <span class="text-xs text-muted">正在解析文件...</span>
        </div>
      </div>

      <!-- 解析结果 -->
      <div v-if="result" class="space-y-3 animate-fade-in">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-2">
            <i class="i-tabler:check text-green-500" />
            <span class="text-sm font-medium text-default">解析结果</span>
          </div>
          <button
            class="flex items-center gap-1 text-xs text-muted hover:text-primary transition-colors cursor-pointer"
            @click="showJson = !showJson"
          >
            <i class="i-tabler:code" />
            {{ showJson ? '查看内容' : '查看 JSON' }}
          </button>
        </div>

        <!-- JSON 视图 -->
        <div v-if="showJson" class="rounded-xl overflow-hidden border border-gray-200 dark:border-gray-700">
          <DuxCodeEditor readonly :value="JSON.stringify(result, null, 2)" />
        </div>

        <!-- 内容视图 -->
        <div v-else class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
          <div
            v-if="resultHtml"
            class="p-4 prose prose-sm max-w-none text-default max-h-[400px] overflow-y-auto"
            v-html="resultHtml"
          />
          <div v-else class="p-4 text-sm text-muted text-center">
            无文本内容
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
