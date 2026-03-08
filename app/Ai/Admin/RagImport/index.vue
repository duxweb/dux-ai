<script setup lang="ts">
import { useCustomMutation, useInvalidate } from '@duxweb/dvha-core'
import { DuxSelect } from '@duxweb/dvha-naiveui'
import { DuxPage, DuxSelectCard } from '@duxweb/dvha-pro'
import { NAlert, NButton, NCard, NEmpty, NInputNumber, NProgress, useMessage } from 'naive-ui'
import { computed, ref, watch } from 'vue'

type DocType = 'document' | 'qa' | 'sheet'

const knowledgeId = ref<number | null>(null)
const docType = ref<DocType>('document')
const files = ref<File[]>([])
type UploadStatus = 'pending' | 'uploading' | 'success' | 'error'
interface UploadInfo { status: UploadStatus, message?: string, progress?: number }
const statusMap = ref<Record<string, UploadInfo>>({})
const isDragging = ref(false)
const isUploading = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)
const overrides = ref({
  sheet_header_rows: null as number | null,
  sheet_start_row: null as number | null,
})

const message = useMessage()
const { mutateAsync } = useCustomMutation()
const { invalidate } = useInvalidate()

const docTypeOptions: Array<{ label: string, value: DocType, description: string }> = [
  { label: '文档', value: 'document', description: '上传 PDF / Office / Markdown / 文本等文件' },
  { label: '问答', value: 'qa', description: '上传 CSV 文件，第一列问、第二列答' },
  { label: '表格', value: 'sheet', description: '上传 Excel / CSV 等结构化表格' },
]
const documentAccept = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'md', 'txt', 'png', 'jpg', 'jpeg', 'csv']
  .map(ext => `.${ext}`)
  .join(',')
const qaAccept = '.csv'
const sheetAccept = '.xls,.xlsx,.csv'
const uploadAccept = computed(() => {
  if (docType.value === 'qa')
    return qaAccept
  if (docType.value === 'sheet')
    return sheetAccept
  return documentAccept
})
const templateUrl = '/templates/rag-qa-template.csv'

function formatFileSize(size: number) {
  if (!size || size <= 0)
    return '0 B'
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  let current = size
  let index = 0
  while (current >= 1024 && index < units.length - 1) {
    current /= 1024
    index += 1
  }
  const digits = current >= 100 ? 0 : current >= 10 ? 1 : 2
  return `${current.toFixed(digits)} ${units[index]}`
}

const totalSize = computed(() => files.value.reduce((sum, file) => sum + file.size, 0))
const summaryLabel = computed(() => {
  if (!files.value.length)
    return '未选择文件'
  return `已选择 ${files.value.length} 个文件 · ${formatFileSize(totalSize.value)}`
})
const getFileKey = (file: File) => `${file.name}-${file.size}-${file.lastModified}`
const statusLabelMap: Record<UploadStatus, string> = {
  pending: '待上传',
  uploading: '上传中',
  success: '上传成功',
  error: '上传失败',
}
const statusClassMap: Record<UploadStatus, string> = {
  pending: 'text-muted',
  uploading: 'text-primary',
  success: 'text-green-600',
  error: 'text-red-600',
}
const uploadList = computed(() => files.value.map((file) => {
  const key = getFileKey(file)
  const state = statusMap.value[key] ?? { status: 'pending' as UploadStatus, progress: 0 }
  return {
    file,
    key,
    status: state.status,
    message: state.message,
    progress: state.progress ?? 0,
  }
}))

watch(docType, () => {
  files.value = []
  statusMap.value = {}
})

watch(files, (newFiles) => {
  const next: Record<string, UploadInfo> = {}
  newFiles.forEach((file) => {
    const key = getFileKey(file)
    next[key] = statusMap.value[key] ?? { status: 'pending', progress: 0 }
  })
  statusMap.value = next
}, { deep: true })

const allowSubmit = computed(() => Boolean(knowledgeId.value) && files.value.length > 0 && !isUploading.value)

function handleSelectFiles() {
  fileInput.value?.click()
}

function mergeFiles(nextFiles: File[]) {
  const collection = new Map<string, File>()
  for (const file of [...files.value, ...nextFiles]) {
    const key = `${file.name}-${file.size}-${file.lastModified}`
    if (!collection.has(key))
      collection.set(key, file)
  }
  files.value = Array.from(collection.values())
}

function handleInputChange(event: Event) {
  const target = event.target as HTMLInputElement | null
  if (!target?.files?.length)
    return
  mergeFiles(Array.from(target.files))
  target.value = ''
}

function handleDrop(event: DragEvent) {
  event.preventDefault()
  isDragging.value = false
  if (!event.dataTransfer?.files?.length)
    return
  mergeFiles(Array.from(event.dataTransfer.files))
}

function handleDragOver(event: DragEvent) {
  event.preventDefault()
  if (!isDragging.value)
    isDragging.value = true
}

function handleDragLeave(event: DragEvent) {
  event.preventDefault()
  if (isDragging.value)
    isDragging.value = false
}

function removeFile(index: number) {
  const removed = files.value[index]
  files.value.splice(index, 1)
  if (removed) {
    const key = getFileKey(removed)
    const next = { ...statusMap.value }
    delete next[key]
    statusMap.value = next
  }
}

function clearFiles() {
  files.value = []
  statusMap.value = {}
}

async function submitImport() {
  if (isUploading.value) {
    return
  }
  if (!knowledgeId.value) {
    message.error('请选择文档库')
    return
  }
  if (!files.value.length) {
    message.error('请先选择要导入的文件')
    return
  }
  isUploading.value = true
  let successCount = 0
  for (const file of files.value) {
    const key = getFileKey(file)
    statusMap.value = {
      ...statusMap.value,
      [key]: { status: 'uploading', progress: 0 },
    }
    const formData = new FormData()
    formData.append('knowledge_id', `${knowledgeId.value}`)
    formData.append('type', docType.value)
    if (overrides.value.sheet_header_rows !== null)
      formData.append('sheet_header_rows', `${overrides.value.sheet_header_rows}`)
    if (overrides.value.sheet_start_row !== null)
      formData.append('sheet_start_row', `${overrides.value.sheet_start_row}`)
    formData.append('files[]', file, file.name)
    try {
      const result = await mutateAsync({
        path: 'ai/ragKnowledgeData/import',
        method: 'POST',
        payload: formData,
        onUploadProgress: (progressEvent: { loaded?: number, total?: number, percent?: number }) => {
          const percent = typeof progressEvent.percent === 'number'
            ? Math.round(progressEvent.percent)
            : progressEvent.total
              ? Math.round((progressEvent.loaded || 0) / progressEvent.total * 100)
              : 0
          statusMap.value = {
            ...statusMap.value,
            [key]: { status: 'uploading', progress: percent },
          }
        },
      })
      statusMap.value = {
        ...statusMap.value,
        [key]: { status: 'success', progress: 100 },
      }
      successCount += 1
      message.success(result?.message || `${file.name} 导入完成`)
    }
    catch (error: any) {
      const errorMessage = error?.message || '导入失败'
      statusMap.value = {
        ...statusMap.value,
        [key]: { status: 'error', message: errorMessage, progress: statusMap.value[key]?.progress ?? 0 },
      }
      message.error(`${file.name} 导入失败`)
    }
  }
  if (successCount > 0) {
    invalidate('ai/ragKnowledgeData')
  }
  if (successCount === files.value.length && successCount > 0) {
    clearFiles()
  }
  isUploading.value = false
}
</script>

<template>
  <DuxPage :scrollbar="false">
    <div class="flex flex-col lg:flex-row h-full">
      <div class="flex-1">
        <NCard title="文件导入" :bordered="false" class="lg:h-full" content-class="space-y-5" size="small">
          <NAlert type="info">
            支持批量上传 PDF、Office、Markdown、文本等文件，系统会自动上传到存储并入库到文档库。
          </NAlert>
          <div class="grid gap-4">
            <div class="space-y-2">
              <div class="text-sm font-medium">
                选择文档库
              </div>
              <DuxSelect
                v-model:value="knowledgeId"
                path="ai/ragKnowledge"
                label-field="name"
                value-field="id"
                placeholder="请选择文档库"
              />
            </div>
            <div class="space-y-2">
              <div class="text-sm font-medium">
                文档类型
              </div>
              <DuxSelectCard v-model:value="docType" :options="docTypeOptions" />
            </div>
            <div v-if="docType === 'sheet'">
              <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div v-if="docType === 'sheet'">
                  <div class="ext-muted mb-1">
                    表头行
                  </div>
                  <NInputNumber v-model:value="overrides.sheet_header_rows" :min="0" :max="50" placeholder="留空使用默认" />
                </div>
                <div v-if="docType === 'sheet'">
                  <div class="text-muted mb-1">
                    数据开始行
                  </div>
                  <NInputNumber v-model:value="overrides.sheet_start_row" :min="1" :max="1000" placeholder="留空使用默认" />
                </div>
              </div>
            </div>
            <NAlert v-if="docType === 'qa'" type="info">
              <div class="flex flex-wrap items-center gap-2">
                <span>请使用 CSV 模板，第一列为「问」、第二列为「答」。</span>
                <NButton text type="primary" tag="a" :href="templateUrl" target="_blank">
                  [下载模板]
                </NButton>
              </div>
            </NAlert>
            <div>
              <input
                ref="fileInput"
                type="file"
                class="hidden"
                multiple
                :accept="uploadAccept"
                @change="handleInputChange"
              >
              <div
                class="border-2 border-dashed rounded-lg p-6 text-center transition flex flex-col items-center justify-center gap-3 cursor-pointer"
                :class="isDragging ? 'border-primary bg-primary/5' : 'border-muted hover:border-primary/60'"
                @click="handleSelectFiles"
                @dragover="handleDragOver"
                @dragleave="handleDragLeave"
                @drop="handleDrop"
              >
                <div class="text-lg font-medium">
                  拖拽文件到此处，或点击选择文件
                </div>
                <div class="text-xs text-muted">
                  支持多选，单个文件大小需符合存储限制
                </div>
                <NButton type="primary" ghost size="small">
                  选择文件
                </NButton>
              </div>
            </div>
            <div class="flex items-center justify-between">
              <div class="text-sm text-muted">
                {{ summaryLabel }}
              </div>
              <div class="flex items-center gap-3">
                <NButton quaternary size="small" :disabled="!files.length || isUploading" @click="clearFiles">
                  清空
                </NButton>
                <NButton type="primary" :loading="isUploading" :disabled="!allowSubmit" @click="submitImport">
                  执行导入
                </NButton>
              </div>
            </div>
          </div>
        </NCard>
      </div>
      <div class="flex-1">
        <NCard title="导入预览" :bordered="false" class="lg:h-full" content-class="space-y-4 min-h-0" size="small">
          <div v-if="!files.length" class="h-full flex items-center justify-center">
            <NEmpty description="未选择任何文件" />
          </div>
          <div v-else class="space-y-3 h-full overflow-y-auto py-3">
            <div
              v-for="(item, index) in uploadList"
              :key="item.key"
              class="border border-muted rounded-lg p-3 flex flex-col gap-2"
            >
              <div class="flex items-center justify-between text-sm font-medium">
                <span class="truncate">{{ item.file.name }}</span>
                <NButton size="small" text :disabled="isUploading" @click="removeFile(index)">
                  移除
                </NButton>
              </div>
              <div class="text-xs text-muted flex flex-wrap gap-2">
                <span>大小：{{ formatFileSize(item.file.size) }}</span>
                <span v-if="item.file.type">类型：{{ item.file.type }}</span>
                <span>更新时间：{{ new Date(item.file.lastModified).toLocaleString() }}</span>
                <span class="font-medium" :class="statusClassMap[item.status]">
                  {{ statusLabelMap[item.status] }}
                  <span v-if="item.status === 'error' && item.message" class="ml-1">{{ item.message }}</span>
                </span>
              </div>
              <NProgress
                v-if="item.status !== 'pending'"
                :percentage="item.status === 'success' ? 100 : item.progress ?? 0"
                :status="item.status === 'error' ? 'error' : item.status === 'success' ? 'success' : 'default'"
                :height="6"
                indicator-placement="inside"
                :processing="item.status === 'uploading'"
              />
            </div>
          </div>
        </NCard>
      </div>
    </div>
  </DuxPage>
</template>

<style scoped></style>
