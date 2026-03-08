<script setup>
import { DuxSelect } from '@duxweb/dvha-naiveui'
import { DuxDrawerTabForm, DuxFormItem, DuxFormLayout, DuxIconPicker } from '@duxweb/dvha-pro'
import { NInput, NInputNumber, NSelect, NSwitch, NTabPane } from 'naive-ui'
import { computed, ref, watch } from 'vue'

const props = defineProps({
  id: {
    type: [String, Number],
    required: false,
  },
})

const model = ref({
  provider_id: undefined,
  name: '',
  code: '',
  model: '',
  type: 'chat',
  dimensions: null,
  options: {
    batch_size: null,
    debug_log: false,
    video_compress: {
      enabled: true,
      max_mb: 10,
      max_width: 720,
      max_height: 1280,
      fps: 24,
      audio_kbps: 48,
      timeout: 120,
      preset: 'veryfast',
    },
    video_capabilities: {
      image_url: true,
      frames: true,
      seed: true,
      return_last_frame: true,
    },
    attachments: {
      enabled: {
        image: false,
        file: false,
        audio: false,
        video: false,
      },
      mode: {
        image: 'auto',
        file: 'auto',
        audio: 'auto',
        video: 'auto',
      },
      local_parse: {
        image: true,
        file: true,
        audio: false,
        video: false,
      },
      local_storage_name: undefined,
      parse: {
        parse_provider_id: undefined,
      },
    },
  },
  icon: '',
  active: true,
  supports_structured_output: false,
  description: '',
  quota_type: 'once',
  quota_tokens: 0,
})

const typeOptions = [
  { label: 'Chat', value: 'chat' },
  { label: 'Embeddings', value: 'embedding' },
  { label: 'Image', value: 'image' },
  { label: 'Video', value: 'video' },
]

const quotaTypeOptions = [
  { label: '一次性', value: 'once' },
  { label: '按天', value: 'daily' },
  { label: '按月', value: 'monthly' },
]

const isEmbedding = computed(() => model.value.type === 'embedding')
const isChat = computed(() => model.value.type === 'chat')
const isVideo = computed(() => model.value.type === 'video')
const isImageOrVideo = computed(() => model.value.type === 'image' || model.value.type === 'video')
const attachmentPolicyOptions = [
  { label: '模型支持', value: 'model' },
  { label: '本地解析', value: 'parse' },
  { label: '关闭', value: 'off' },
]

const imageModeOptions = [
  { label: '自动', value: 'auto' },
  { label: 'URL', value: 'url' },
  { label: 'Base64', value: 'base64' },
]

const documentModeOptions = [
  { label: '自动', value: 'auto' },
  { label: 'Base64', value: 'base64' },
]

const videoPresetOptions = [
  { label: 'ultrafast', value: 'ultrafast' },
  { label: 'veryfast', value: 'veryfast' },
  { label: 'faster', value: 'faster' },
  { label: 'fast', value: 'fast' },
  { label: 'medium', value: 'medium' },
]

function defaultAttachments() {
  return {
    enabled: {
      image: false,
      file: false,
      audio: false,
      video: false,
    },
    mode: {
      image: 'auto',
      file: 'auto',
      audio: 'auto',
      video: 'auto',
    },
    local_parse: {
      image: true,
      file: true,
      audio: false,
      video: false,
    },
    local_storage_name: undefined,
    parse: {
      parse_provider_id: undefined,
    },
  }
}

function defaultVideoCapabilities() {
  return {
    image_url: true,
    frames: true,
    seed: true,
    return_last_frame: true,
  }
}

function normalizeVideoCapabilities(value) {
  const defaults = defaultVideoCapabilities()
  const source = value && typeof value === 'object' ? value : {}
  return {
    image_url: source.image_url !== undefined ? Boolean(source.image_url) : defaults.image_url,
    frames: source.frames !== undefined ? Boolean(source.frames) : defaults.frames,
    seed: source.seed !== undefined ? Boolean(source.seed) : defaults.seed,
    return_last_frame: source.return_last_frame !== undefined ? Boolean(source.return_last_frame) : defaults.return_last_frame,
  }
}

function defaultVideoCompress() {
  return {
    enabled: true,
    max_mb: 10,
    max_width: 720,
    max_height: 1280,
    fps: 24,
    audio_kbps: 48,
    timeout: 120,
    preset: 'veryfast',
  }
}

function normalizeVideoCompress(value) {
  const defaults = defaultVideoCompress()
  const source = value && typeof value === 'object' ? value : {}
  const preset = source.preset && String(source.preset).trim() !== '' ? String(source.preset) : defaults.preset
  return {
    enabled: source.enabled !== undefined ? Boolean(source.enabled) : defaults.enabled,
    max_mb: Math.min(100, Math.max(1, Number(source.max_mb ?? defaults.max_mb) || defaults.max_mb)),
    max_width: Math.min(4096, Math.max(160, Number(source.max_width ?? defaults.max_width) || defaults.max_width)),
    max_height: Math.min(4096, Math.max(160, Number(source.max_height ?? defaults.max_height) || defaults.max_height)),
    fps: Math.min(60, Math.max(12, Number(source.fps ?? defaults.fps) || defaults.fps)),
    audio_kbps: Math.min(192, Math.max(16, Number(source.audio_kbps ?? defaults.audio_kbps) || defaults.audio_kbps)),
    timeout: Math.min(600, Math.max(10, Number(source.timeout ?? defaults.timeout) || defaults.timeout)),
    preset,
  }
}

function normalizeAttachments(value) {
  const defaults = defaultAttachments()
  const source = value && typeof value === 'object' ? value : {}
  const enabled = source.enabled && typeof source.enabled === 'object' ? source.enabled : {}
  const mode = source.mode && typeof source.mode === 'object' ? source.mode : {}
  const localParse = source.local_parse && typeof source.local_parse === 'object' ? source.local_parse : {}
  const parse = source.parse && typeof source.parse === 'object' ? source.parse : {}

  const imageMode = ['auto', 'url', 'base64'].includes(String(mode.image || '').toLowerCase()) ? String(mode.image).toLowerCase() : 'auto'
  const normalizeDocumentMode = (input) => ['auto', 'base64'].includes(String(input || '').toLowerCase()) ? String(input).toLowerCase() : 'auto'
  const parseProviderIdRaw = parse.parse_provider_id !== undefined && parse.parse_provider_id !== null && parse.parse_provider_id !== ''
    ? Number(parse.parse_provider_id)
    : undefined
  const parseProviderId = Number.isFinite(parseProviderIdRaw) && Number(parseProviderIdRaw) > 0
    ? Number(parseProviderIdRaw)
    : undefined

  return {
    enabled: {
      image: enabled.image !== undefined ? Boolean(enabled.image) : defaults.enabled.image,
      file: enabled.file !== undefined ? Boolean(enabled.file) : defaults.enabled.file,
      audio: enabled.audio !== undefined ? Boolean(enabled.audio) : defaults.enabled.audio,
      video: enabled.video !== undefined ? Boolean(enabled.video) : defaults.enabled.video,
    },
    mode: {
      image: imageMode,
      file: normalizeDocumentMode(mode.file),
      audio: normalizeDocumentMode(mode.audio),
      video: normalizeDocumentMode(mode.video),
    },
    local_parse: {
      image: localParse.image !== undefined ? Boolean(localParse.image) : defaults.local_parse.image,
      file: localParse.file !== undefined ? Boolean(localParse.file) : defaults.local_parse.file,
      audio: localParse.audio !== undefined ? Boolean(localParse.audio) : defaults.local_parse.audio,
      video: localParse.video !== undefined ? Boolean(localParse.video) : defaults.local_parse.video,
    },
    local_storage_name: source.local_storage_name ? String(source.local_storage_name) : undefined,
    parse: {
      parse_provider_id: parseProviderId,
    },
  }
}

function normalizeNumber(value, fallback = null) {
  if (value === '' || value === undefined || value === null) {
    return fallback
  }
  const numeric = Number(value)
  return Number.isFinite(numeric) ? numeric : fallback
}

function getAttachmentPolicy(kind) {
  const attachments = model.value.options?.attachments || {}
  const enabled = attachments.enabled?.[kind] !== false
  const localParse = attachments.local_parse?.[kind] !== false
  if (enabled)
    return 'model'
  return localParse ? 'parse' : 'off'
}

function setAttachmentPolicy(kind, policy) {
  if (!model.value.options || typeof model.value.options !== 'object')
    return
  const attachments = normalizeAttachments(model.value.options.attachments)
  if (policy === 'model') {
    attachments.enabled[kind] = true
    attachments.local_parse[kind] = true
  }
  else if (policy === 'parse') {
    attachments.enabled[kind] = false
    attachments.local_parse[kind] = true
  }
  else {
    attachments.enabled[kind] = false
    attachments.local_parse[kind] = false
  }
  model.value.options = {
    ...model.value.options,
    attachments,
  }
}

const imagePolicy = computed({
  get: () => getAttachmentPolicy('image'),
  set: value => setAttachmentPolicy('image', value),
})
const filePolicy = computed({
  get: () => getAttachmentPolicy('file'),
  set: value => setAttachmentPolicy('file', value),
})
const audioPolicy = computed({
  get: () => getAttachmentPolicy('audio'),
  set: value => setAttachmentPolicy('audio', value),
})
const videoPolicy = computed({
  get: () => getAttachmentPolicy('video'),
  set: value => setAttachmentPolicy('video', value),
})

watch(
  () => model.value.options,
  (value) => {
    if (!value || typeof value !== 'object') {
      model.value.options = {
        batch_size: null,
        video_compress: defaultVideoCompress(),
        video_capabilities: defaultVideoCapabilities(),
        attachments: defaultAttachments(),
      }
      return
    }
    const batchSize = normalizeNumber(value.batch_size, null)
    const debugLog = Boolean(value.debug_log)
    const mediaStorageName = value.media_storage_name && String(value.media_storage_name).trim() !== ''
      ? String(value.media_storage_name)
      : undefined
    const attachments = normalizeAttachments(value.attachments)
    const videoCapabilities = normalizeVideoCapabilities(value.video_capabilities)
    const videoCompress = normalizeVideoCompress(value.video_compress)
    if (value.batch_size !== batchSize
      || value.debug_log !== debugLog
      || value.media_storage_name !== mediaStorageName
      || JSON.stringify(value.attachments || {}) !== JSON.stringify(attachments)
      || JSON.stringify(value.video_capabilities || {}) !== JSON.stringify(videoCapabilities)
      || JSON.stringify(value.video_compress || {}) !== JSON.stringify(videoCompress)) {
      model.value.options = {
        ...value,
        batch_size: batchSize,
        debug_log: debugLog,
        media_storage_name: mediaStorageName,
        video_capabilities: videoCapabilities,
        video_compress: videoCompress,
        attachments,
      }
    }
  },
  { deep: true },
)

watch(
  () => model.value.quota_tokens,
  (value) => {
    const numeric = normalizeNumber(value, 0)
    if (value !== numeric) {
      model.value.quota_tokens = numeric
    }
  },
)

watch(
  () => model.value.dimensions,
  (value) => {
    const numeric = normalizeNumber(value, null)
    if (value !== numeric) {
      model.value.dimensions = numeric
    }
  },
)


watch(
  () => model.value.type,
  (value) => {
    if (!model.value.options || typeof model.value.options !== 'object') {
      model.value.options = {
        batch_size: null,
        video_compress: defaultVideoCompress(),
        video_capabilities: defaultVideoCapabilities(),
        attachments: defaultAttachments(),
      }
      return
    }
    model.value.options = {
      ...model.value.options,
      attachments: normalizeAttachments(model.value.options.attachments),
    }
    if (value !== 'embedding') {
      model.value.dimensions = null
      model.value.options = {
        ...model.value.options,
        batch_size: null,
      }
      return
    }
    if (!Object.prototype.hasOwnProperty.call(model.value.options, 'batch_size')) {
      model.value.options = { ...model.value.options, batch_size: null }
    }
  },
  { immediate: true },
)
</script>

<template>
  <DuxDrawerTabForm
    :id="props.id"
    path="ai/model"
    :data="model"
    default-tab="base"
    invalidate="ai/model"
  >
    <NTabPane name="base" label="基本信息">
      <DuxFormLayout label-placement="top">
        <DuxFormItem label="所属服务商" required>
          <DuxSelect
            v-model:value="model.provider_id"
            path="ai/provider"
            label-field="name"
            value-field="id"
            placeholder="请选择服务商"
          />
        </DuxFormItem>
        <DuxFormItem label="模型名称" required>
          <NInput v-model:value="model.name" placeholder="请输入模型名称" />
        </DuxFormItem>
        <DuxFormItem label="模型标识" description="可选，不填将自动生成">
          <NInput v-model:value="model.code" placeholder="请输入调用标识" />
        </DuxFormItem>
        <DuxFormItem label="远端模型 ID" required>
          <NInput v-model:value="model.model" placeholder="如 gpt-4o-mini、hunyuan-turbos-latest" />
        </DuxFormItem>
        <DuxFormItem label="模型类型" required>
          <NSelect v-model:value="model.type" :options="typeOptions" />
        </DuxFormItem>
        <DuxFormItem label="说明">
          <NInput v-model:value="model.description" type="textarea" placeholder="备注信息" />
        </DuxFormItem>
        <DuxFormItem label="启用">
          <NSwitch v-model:value="model.active" />
        </DuxFormItem>
      </DuxFormLayout>
    </NTabPane>

    <NTabPane name="model_params" label="模型参数">
      <DuxFormLayout label-placement="top">
        <template v-if="isChat">
          <DuxFormItem label="支持结构化输出" description="开启后可在流程节点中选择 structured/auto 输出模式">
            <NSwitch v-model:value="model.supports_structured_output" />
          </DuxFormItem>
          <DuxFormItem label="本地存储驱动" description="留空使用系统默认存储">
            <DuxSelect
              v-model:value="model.options.attachments.local_storage_name"
              path="system/storage"
              label-field="title"
              value-field="name"
              placeholder="选择存储驱动"
              clearable
            />
          </DuxFormItem>
          <DuxFormItem v-if="imagePolicy === 'parse' || filePolicy === 'parse'" label="解析驱动" description="图片/文件启用本地解析时建议配置">
            <DuxSelect
              v-model:value="model.options.attachments.parse.parse_provider_id"
              path="ai/parseProvider"
              label-field="name"
              value-field="id"
              placeholder="选择解析配置"
              clearable
            />
          </DuxFormItem>
          <DuxFormItem label="图片处理策略">
            <NSelect v-model:value="imagePolicy" :options="attachmentPolicyOptions" />
          </DuxFormItem>
          <DuxFormItem v-if="imagePolicy === 'model'" label="图片发送模式">
            <NSelect v-model:value="model.options.attachments.mode.image" :options="imageModeOptions" />
          </DuxFormItem>
          <DuxFormItem label="文件处理策略">
            <NSelect v-model:value="filePolicy" :options="attachmentPolicyOptions" />
          </DuxFormItem>
          <DuxFormItem v-if="filePolicy === 'model'" label="文件发送模式">
            <NSelect v-model:value="model.options.attachments.mode.file" :options="documentModeOptions" />
          </DuxFormItem>
          <DuxFormItem label="音频处理策略" description="音频暂不支持本地解析，建议使用模型支持或关闭">
            <NSelect v-model:value="audioPolicy" :options="attachmentPolicyOptions" />
          </DuxFormItem>
          <DuxFormItem v-if="audioPolicy === 'model'" label="音频发送模式">
            <NSelect v-model:value="model.options.attachments.mode.audio" :options="documentModeOptions" />
          </DuxFormItem>
          <DuxFormItem label="视频处理策略" description="视频暂不支持本地解析，建议使用模型支持或关闭">
            <NSelect v-model:value="videoPolicy" :options="attachmentPolicyOptions" />
          </DuxFormItem>
          <DuxFormItem v-if="videoPolicy === 'model'" label="视频发送模式">
            <NSelect v-model:value="model.options.attachments.mode.video" :options="documentModeOptions" />
          </DuxFormItem>
        </template>
        <template v-else-if="isImageOrVideo">
          <DuxFormItem label="调试日志" :description="isVideo ? '开启后输出视频请求与响应调试日志（ai.video）' : '开启后输出图片请求与响应调试日志（ai.image）'">
            <NSwitch v-model:value="model.options.debug_log" />
          </DuxFormItem>
          <DuxFormItem label="媒体存储驱动" description="图片/视频转存时使用，留空走系统默认存储">
            <DuxSelect
              v-model:value="model.options.media_storage_name"
              path="system/storage"
              label-field="title"
              value-field="name"
              placeholder="选择存储驱动"
              clearable
            />
          </DuxFormItem>
          <template v-if="isVideo">
            <DuxFormItem label="发送自动压缩" description="机器人发送视频前自动压缩，避免平台大小限制">
              <NSwitch v-model:value="model.options.video_compress.enabled" />
            </DuxFormItem>
            <template v-if="model.options.video_compress.enabled">
              <DuxFormItem label="目标大小(MB)">
                <NInputNumber v-model:value="model.options.video_compress.max_mb" :min="1" :max="100" :precision="0" class="w-full" />
              </DuxFormItem>
              <DuxFormItem label="最大宽度">
                <NInputNumber v-model:value="model.options.video_compress.max_width" :min="160" :max="4096" :precision="0" class="w-full" />
              </DuxFormItem>
              <DuxFormItem label="最大高度">
                <NInputNumber v-model:value="model.options.video_compress.max_height" :min="160" :max="4096" :precision="0" class="w-full" />
              </DuxFormItem>
              <DuxFormItem label="帧率(FPS)">
                <NInputNumber v-model:value="model.options.video_compress.fps" :min="12" :max="60" :precision="0" class="w-full" />
              </DuxFormItem>
              <DuxFormItem label="音频码率(kbps)">
                <NInputNumber v-model:value="model.options.video_compress.audio_kbps" :min="16" :max="192" :precision="0" class="w-full" />
              </DuxFormItem>
              <DuxFormItem label="压缩超时(秒)">
                <NInputNumber v-model:value="model.options.video_compress.timeout" :min="10" :max="600" :precision="0" class="w-full" />
              </DuxFormItem>
              <DuxFormItem label="压缩预设">
                <NSelect v-model:value="model.options.video_compress.preset" :options="videoPresetOptions" />
              </DuxFormItem>
            </template>
            <DuxFormItem label="视频能力参数" description="控制流程 video_generate 节点展示哪些可选字段">
              <div class="grid grid-cols-2 gap-2">
                <div>
                <NSwitch v-model:value="model.options.video_capabilities.image_url">
                  <template #checked>支持首帧图片 URL</template>
                  <template #unchecked>支持首帧图片 URL</template>
                </NSwitch>
                </div>
                <div>
                <NSwitch v-model:value="model.options.video_capabilities.frames">
                  <template #checked>支持帧数</template>
                  <template #unchecked>支持帧数</template>
                </NSwitch>
                </div>
                <div>
                <NSwitch v-model:value="model.options.video_capabilities.seed">
                  <template #checked>支持随机种子</template>
                  <template #unchecked>支持随机种子</template>
                </NSwitch>
                </div>
                <div>
                <NSwitch v-model:value="model.options.video_capabilities.return_last_frame">
                  <template #checked>支持返回尾帧图</template>
                  <template #unchecked>支持返回尾帧图</template>
                </NSwitch>
                </div>
              </div>
            </DuxFormItem>
          </template>
        </template>
        <div v-else class="text-sm text-muted">
          当前模型类型无需配置聊天附件能力。
        </div>
      </DuxFormLayout>
    </NTabPane>

    <NTabPane name="extra" label="附加信息">
      <DuxFormLayout label-placement="top">
        <DuxFormItem label="额度类型" description="用于负载均衡，Token 为 0 表示不限制">
          <NSelect v-model:value="model.quota_type" :options="quotaTypeOptions" />
        </DuxFormItem>
        <DuxFormItem label="额度 Token">
          <NInputNumber v-model:value="model.quota_tokens" :min="0" placeholder="例如 500000" />
        </DuxFormItem>
        <DuxFormItem v-if="isEmbedding" label="向量维度" description="仅 Embeddings 模型需要（可选，留空为自动）">
          <NInputNumber v-model:value="model.dimensions" :min="1" placeholder="例如 384 / 768 / 1536" />
        </DuxFormItem>
        <DuxFormItem
          v-if="isEmbedding"
          label="批量大小 batch_size"
          description="Embeddings 批量请求 input 数组长度（部分服务商限制最大 50）；留空使用默认 50"
        >
          <NInputNumber
            v-model:value="model.options.batch_size"
            :min="1"
            :max="50"
            placeholder="1-50"
          />
        </DuxFormItem>
        <DuxFormItem label="图标">
          <DuxIconPicker v-model:value="model.icon" />
        </DuxFormItem>
      </DuxFormLayout>
    </NTabPane>
  </DuxDrawerTabForm>
</template>
