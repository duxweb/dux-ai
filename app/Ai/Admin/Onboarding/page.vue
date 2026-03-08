<script setup lang="ts">
import { useCustomMutation, useManage } from '@duxweb/dvha-core'
import { DuxPage } from '@duxweb/dvha-pro'
import { NButton, NForm, NFormItem, NInput, NInputNumber, NSelect, NSpace, useMessage } from 'naive-ui'
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'

const request = useCustomMutation()
const message = useMessage()
const router = useRouter()
const manage = useManage()

const loading = ref(false)
const submitting = ref(false)
const step = ref(1)
const createdResult = ref<any>(null)

const meta = ref<any>({
  scenes: [],
  protocols: [],
  providers: [],
  models: [],
  tools: [],
  bots: [],
  vectors: [],
  storages: [],
  parse_providers: [],
  bot_platforms: [],
})

const form = ref<any>({
  scene: 'agent_only',
  provider_mode: 'reuse',
  provider_reuse_id: undefined,
  provider_protocol: 'openai_like',
  provider_name: undefined,
  provider_api_key: undefined,
  provider_base_url: undefined,
  chat_model_mode: 'create',
  chat_model_reuse_id: undefined,
  chat_model_name: undefined,
  chat_model: undefined,
  embedding_model_mode: 'create',
  embedding_model_reuse_id: undefined,
  embedding_model_name: undefined,
  embedding_model: undefined,
  agent_name: undefined,
  agent_instructions: undefined,
  tool_codes: [],
  bot_mode: 'reuse',
  bot_codes: [],
  storage_id: undefined,
  vector_id: undefined,
  parse_provider_id: undefined,
})
const newBot = ref<any>({
  name: undefined,
  code: undefined,
  platform: 'dingtalk',
  verify_secret: undefined,
  config: {
    app_key: undefined,
    app_id: undefined,
    app_secret: undefined,
    corp_id: undefined,
    agent_id: undefined,
    token: undefined,
    aes_key: undefined,
    webhook: undefined,
    sign_secret: undefined,
    verification_token: undefined,
    encrypt_key: undefined,
  },
  remark: undefined,
})

// 场景配置：图标、颜色、标题、描述
const sceneConfig: Record<string, { icon: string, color: string, label: string, desc: string }> = {
  agent_only: {
    icon: 'i-tabler:robot',
    color: 'blue',
    label: '先聊聊天',
    desc: '快速创建一个 AI 对话助手，可以闲聊、答疑、帮你处理各种问题',
  },
  im: {
    icon: 'i-tabler:message-circle',
    color: 'green',
    label: '接入聊天平台',
    desc: '把 AI 接到微信、钉钉等平台，用户直接发消息就能得到回复',
  },
  knowledge: {
    icon: 'i-tabler:books',
    color: 'purple',
    label: '搭建知识问答',
    desc: '上传你的文档资料，AI 会基于这些内容来回答问题，越问越懂你',
  },
}

const protocolOptions = computed(() => (meta.value.protocols || []).map((item: any) => ({
  label: item.label,
  value: item.value,
})))

const providerOptions = computed(() => (meta.value.providers || []).map((item: any) => ({
  label: `${item.name} (${item.code})`,
  value: item.id,
})))

const chatModelOptions = computed(() => (meta.value.models || [])
  .filter((item: any) => item.type === 'chat')
  .map((item: any) => ({
    label: `${item.name} (${item.model})`,
    value: item.id,
  })))

const embeddingModelOptions = computed(() => (meta.value.models || [])
  .filter((item: any) => item.type === 'embedding')
  .map((item: any) => ({
    label: `${item.name} (${item.model})`,
    value: item.id,
  })))

const toolOptions = computed(() => (meta.value.tools || []).map((item: any) => ({
  label: item.name || item.code,
  value: item.code,
  description: item.description || '',
  needsConfig: !!item.needs_config,
  settingsCount: Number(item.settings_count || 0),
})))

const selectedToolOptions = computed(() => toolOptions.value.filter((item: any) => (form.value.tool_codes || []).includes(item.value)))
const selectedConfigToolOptions = computed(() => selectedToolOptions.value.filter((item: any) => item.needsConfig))

const botOptions = computed(() => (meta.value.bots || []).map((item: any) => ({
  label: `${item.label}${item.platform ? ` (${item.platform})` : ''}`,
  value: item.value,
})))
const botPlatformOptions = computed(() => {
  const options = (meta.value.bot_platforms || []).map((item: any) => ({
    label: String(item.label || item.value || ''),
    value: String(item.value || ''),
  })).filter((item: any) => item.value !== '')
  if (options.length) {
    return options
  }
  return [
    { label: '钉钉', value: 'dingtalk' },
    { label: '飞书', value: 'feishu' },
    { label: 'QQ机器人', value: 'qq_bot' },
    { label: '企业微信', value: 'wecom' },
  ]
})

const storageOptions = computed(() => (meta.value.storages || []).map((item: any) => ({
  label: `${item.title || item.name} (${item.type || '-'})`,
  value: item.id,
})))

const vectorOptions = computed(() => (meta.value.vectors || []).map((item: any) => ({
  label: `${item.name} (${item.driver || '-'})`,
  value: item.id,
})))

const parserOptions = computed(() => (meta.value.parse_providers || []).map((item: any) => ({
  label: `${item.name} (${item.provider || '-'})`,
  value: item.id,
})))

const isIMScene = computed(() => form.value.scene === 'im')
const isKnowledgeScene = computed(() => form.value.scene === 'knowledge')
const isDingtalkBot = computed(() => newBot.value.platform === 'dingtalk')
const isFeishuBot = computed(() => newBot.value.platform === 'feishu')
const isQQBot = computed(() => newBot.value.platform === 'qq_bot')
const isWecomBot = computed(() => newBot.value.platform === 'wecom')
const stepKeys = computed(() => {
  if (isIMScene.value) {
    return ['scene', 'provider', 'model', 'channel', 'assistant', 'confirm']
  }
  return ['scene', 'provider', 'model', 'assistant', 'confirm']
})
const totalSteps = computed(() => stepKeys.value.length)
const currentStepKey = computed(() => stepKeys.value[step.value - 1] || 'scene')

const canNext = computed(() => {
  if (currentStepKey.value === 'scene') {
    return !!form.value.scene
  }
  if (currentStepKey.value === 'provider') {
    if (form.value.provider_mode === 'reuse') {
      return !!form.value.provider_reuse_id
    }
    return !!form.value.provider_protocol && !!form.value.provider_api_key && !!form.value.provider_base_url
  }
  if (currentStepKey.value === 'model') {
    if (form.value.chat_model_mode === 'reuse') {
      if (!form.value.chat_model_reuse_id)
        return false
    }
    else if (!form.value.chat_model) {
      return false
    }

    if (isKnowledgeScene.value) {
      if (form.value.embedding_model_mode === 'reuse') {
        return !!form.value.embedding_model_reuse_id
      }
      return !!form.value.embedding_model
    }
    return true
  }
  if (currentStepKey.value === 'channel') {
    if (form.value.bot_mode === 'create') {
      if (!String(newBot.value.name || '').trim()) {
        return false
      }
      const config = newBot.value.config || {}
      if (isDingtalkBot.value) {
        return !!String(config.webhook || '').trim()
      }
      if (isFeishuBot.value || isQQBot.value) {
        return !!String(config.app_id || '').trim() && !!String(config.app_secret || '').trim()
      }
      if (isWecomBot.value) {
        return !!String(config.corp_id || '').trim()
          && !!String(config.app_secret || '').trim()
          && Number(config.agent_id || 0) > 0
      }
      return false
    }
    return Array.isArray(form.value.bot_codes) && form.value.bot_codes.length > 0
  }
  if (currentStepKey.value === 'assistant') {
    if (!form.value.agent_name)
      return false
    if (isKnowledgeScene.value && (!form.value.storage_id || !form.value.vector_id))
      return false
    return true
  }
  return true
})

const stepInfo = computed(() => {
  const map: Record<string, { title: string, desc: string }> = {
    scene: { title: '选择场景', desc: '先告诉我，你想让 Dux AI 帮你做什么？' },
    provider: { title: '连接服务', desc: '选择你的 AI 服务商，或者填入一个新的' },
    model: { title: '挑选模型', desc: '选一个合适的大模型来驱动你的 AI' },
    channel: { title: '接入渠道', desc: '先配置机器人渠道，后续创建助手时直接绑定' },
    assistant: { title: '创建助手', desc: '给你的 AI 起个名字，赋予它能力' },
    confirm: { title: '确认创建', desc: '一切就绪，确认后一键生成' },
  }
  return map[currentStepKey.value] || map.scene
})

// 确认页流程节点：根据场景动态生成

const providerDisplayName = computed(() => {
  if (form.value.provider_mode === 'reuse') {
    const item = (meta.value.providers || []).find((entry: any) => entry.id === form.value.provider_reuse_id)
    return item?.name || item?.code || '-'
  }
  return String(form.value.provider_name || '').trim() || String(form.value.provider_protocol || '').trim() || '-'
})

const chatModelDisplayName = computed(() => {
  if (form.value.chat_model_mode === 'reuse') {
    const item = (meta.value.models || []).find((entry: any) => entry.id === form.value.chat_model_reuse_id)
    return item?.name || item?.model || '-'
  }
  return String(form.value.chat_model_name || '').trim() || String(form.value.chat_model || '').trim() || '-'
})

const embeddingModelDisplayName = computed(() => {
  if (!isKnowledgeScene.value) {
    return '-'
  }
  if (form.value.embedding_model_mode === 'reuse') {
    const item = (meta.value.models || []).find((entry: any) => entry.id === form.value.embedding_model_reuse_id)
    return item?.name || item?.model || '-'
  }
  return String(form.value.embedding_model_name || '').trim() || String(form.value.embedding_model || '').trim() || '-'
})

const agentDisplayName = computed(() => String(form.value.agent_name || '').trim() || '-')
const ragDisplayName = computed(() => isKnowledgeScene.value ? '已创建' : '-')

const confirmFlowSteps = computed(() => {
  const base = [
    { icon: 'i-tabler:server', label: '服务商' },
    { icon: 'i-tabler:cpu', label: '模型' },
    { icon: 'i-tabler:robot', label: '智能体' },
  ]
  if (isIMScene.value) {
    base.push({ icon: 'i-tabler:message-circle', label: '渠道' })
  }
  if (isKnowledgeScene.value) {
    base.push({ icon: 'i-tabler:books', label: '知识库' })
  }
  return base
})

function protocolDefaultBaseUrl(protocol: string): string {
  const item = (meta.value.protocols || []).find((p: any) => p.value === protocol)
  return item?.default_base_url || ''
}

function onProtocolChange(value: string) {
  form.value.provider_base_url = protocolDefaultBaseUrl(value)
}

function toggleTool(code: string) {
  const list: string[] = form.value.tool_codes || []
  const idx = list.indexOf(code)
  if (idx >= 0) {
    list.splice(idx, 1)
  }
  else {
    list.push(code)
  }
  form.value.tool_codes = [...list]
}

async function loadMeta() {
  loading.value = true
  try {
    const res = await request.mutateAsync({
      path: 'ai/onboarding/meta',
      method: 'GET',
    })
    meta.value = res?.data || {}
    form.value.provider_base_url = protocolDefaultBaseUrl(form.value.provider_protocol)
  }
  catch (e: any) {
    message.error(e?.message || '加载向导元数据失败')
  }
  finally {
    loading.value = false
  }
}

function nextStep() {
  if (!canNext.value) {
    message.warning('请先完成当前步骤')
    return
  }
  step.value = Math.min(totalSteps.value, step.value + 1)
}

function prevStep() {
  step.value = Math.max(1, step.value - 1)
}

function buildPayload() {
  return {
    scene: form.value.scene,
    provider: form.value.provider_mode === 'reuse'
      ? { reuse_id: form.value.provider_reuse_id }
      : {
          protocol: form.value.provider_protocol,
          name: form.value.provider_name,
          api_key: form.value.provider_api_key,
          base_url: form.value.provider_base_url,
        },
    chat_model: form.value.chat_model_mode === 'reuse'
      ? { reuse_id: form.value.chat_model_reuse_id }
      : {
          name: form.value.chat_model_name,
          model: form.value.chat_model,
        },
    embedding_model: isKnowledgeScene.value
      ? (form.value.embedding_model_mode === 'reuse'
          ? { reuse_id: form.value.embedding_model_reuse_id }
          : {
              name: form.value.embedding_model_name,
              model: form.value.embedding_model,
            })
      : null,
    agent: {
      name: form.value.agent_name,
      instructions: form.value.agent_instructions,
      tool_codes: form.value.tool_codes || [],
    },
    im: isIMScene.value
      ? {
          bot_mode: form.value.bot_mode,
          bot_codes: form.value.bot_mode === 'reuse' ? (form.value.bot_codes || []) : [],
          bot: form.value.bot_mode === 'create'
            ? {
                name: newBot.value.name,
                code: newBot.value.code,
                platform: newBot.value.platform,
                verify_secret: newBot.value.verify_secret,
                config: newBot.value.config || {},
                remark: newBot.value.remark,
              }
            : null,
        }
      : null,
    knowledge: isKnowledgeScene.value
      ? {
          storage_id: form.value.storage_id,
          vector_id: form.value.vector_id,
          parse_provider_id: form.value.parse_provider_id || null,
        }
      : null,
  }
}

function normalizeBotCode(name: string, code: string): string {
  const input = String(code || '').trim()
  if (input) {
    return input
  }
  const seed = String(name || '').trim().toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g, '')
  if (seed) {
    return `bot_${seed}_${Date.now().toString(36)}`
  }
  return `bot_${Date.now().toString(36)}`
}

async function refreshBots() {
  const res = await request.mutateAsync({
    path: 'boot/bot/options',
    method: 'GET',
    query: { enabled: 1 },
  })
  const list = Array.isArray(res?.data) ? res.data : []
  meta.value = {
    ...meta.value,
    bots: list.map((item: any) => ({
      value: String(item.value || ''),
      label: String(item.label || item.value || ''),
      platform: String(item.platform || ''),
    })).filter((item: any) => item.value !== ''),
  }
}

async function submit() {
  submitting.value = true
  try {
    const res = await request.mutateAsync({
      path: 'ai/onboarding/submit',
      method: 'POST',
      payload: buildPayload(),
    })
    const data = res?.data || {}
    message.success('配置已创建完成')
    createdResult.value = data
  }
  catch (e: any) {
    message.error(e?.message || '创建失败')
  }
  finally {
    submitting.value = false
  }
}

function gotoChat() {
  const code = String(createdResult.value?.agent_code || '').trim()
  if (!code)
    return
  router.push(manage.getRoutePath(`/ai/agent/chat/${encodeURIComponent(code)}`))
}

function gotoAgentEdit() {
  const id = Number(createdResult.value?.agent_id || 0)
  if (!id)
    return
  router.push(manage.getRoutePath(`/ai/agent/edit/${id}`))
}

function gotoProvider() {
  router.push(manage.getRoutePath('/ai/provider'))
}

function gotoModel() {
  router.push(manage.getRoutePath('/ai/model'))
}

function gotoRagProvider() {
  router.push(manage.getRoutePath('/ai/ragProvider'))
}

onMounted(() => {
  loadMeta()
})

watch(() => form.value.scene, () => {
  if (!isIMScene.value) {
    form.value.bot_codes = []
    form.value.bot_mode = 'reuse'
  }
  if (step.value > totalSteps.value) {
    step.value = totalSteps.value
  }
})

watch(currentStepKey, (key) => {
  if (key === 'channel' && isIMScene.value) {
    refreshBots().then(() => {
      if (!botOptions.value.length) {
        form.value.bot_mode = 'create'
      }
    }).catch(() => {})
  }
})

watch(() => newBot.value.name, (value) => {
  if (form.value.bot_mode !== 'create') {
    return
  }
  const current = String(newBot.value.code || '').trim()
  if (current !== '') {
    return
  }
  newBot.value.code = normalizeBotCode(String(value || ''), '')
})
</script>

<template>
  <DuxPage>
    <div class="min-h-screen bg-gradient-to-br from-bg-default via-bg-elevated to-bg-default">
      <!-- Hero 头部 -->
      <div class="relative overflow-hidden bg-gradient-to-br from-primary/5 via-primary/10 to-transparent border-b border-muted">
        <div class="absolute inset-0 bg-grid-pattern opacity-5" />
        <div class="relative px-6 py-12">
          <div class="max-w-4xl mx-auto">
            <div class="flex items-center justify-between mb-8">
              <NButton text @click="router.push(manage.getRoutePath('/ai/home'))">
                <template #icon>
                  <i class="i-tabler:arrow-left" />
                </template>
                返回首页
              </NButton>
              <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-primary/10 border border-primary/20">
                <i class="i-tabler:sparkles text-primary" />
                <span class="text-sm font-medium text-primary">快速配置向导</span>
              </div>
            </div>

            <div class="space-y-3">
              <h1 class="text-3xl md:text-4xl font-bold text-default">
                {{ stepInfo.title }}
              </h1>
              <p class="text-lg text-muted max-w-2xl">
                {{ stepInfo.desc }}
              </p>
            </div>

            <!-- 进度条 -->
            <div class="mt-8 flex items-center gap-2">
              <template v-for="i in totalSteps" :key="i">
                <div
                  class="h-1.5 rounded-full flex-1 transition-all duration-500"
                  :class="i <= step ? 'bg-primary' : 'bg-muted/30'"
                />
              </template>
              <span class="text-sm text-muted ml-2 flex-none">{{ step }} / {{ totalSteps }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- 主内容 -->
      <div class="max-w-4xl mx-auto px-6 py-10">
        <!-- Loading -->
        <div v-if="loading" class="flex flex-col items-center justify-center py-20 gap-4">
          <div class="size-12 border-4 border-primary/30 border-t-primary rounded-full animate-spin" />
          <p class="text-muted">正在加载配置信息...</p>
        </div>

        <template v-else>
          <!-- Scene -->
          <div v-if="currentStepKey === 'scene'" class="space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
              <div
                v-for="scene in meta.scenes"
                :key="scene.value"
                class="group relative rounded-2xl border-2 p-6 cursor-pointer transition-all duration-300 hover:shadow-xl hover:-translate-y-1"
                :class="form.scene === scene.value
                  ? 'border-primary bg-primary/5 shadow-lg shadow-primary/10'
                  : 'border-muted bg-default hover:border-primary/50'"
                @click="form.scene = scene.value"
              >
                <!-- 选中指示器 -->
                <div
                  class="absolute top-4 right-4 size-6 rounded-full border-2 flex items-center justify-center transition-all"
                  :class="form.scene === scene.value
                    ? 'border-primary bg-primary'
                    : 'border-muted'"
                >
                  <i v-if="form.scene === scene.value" class="i-tabler:check text-white text-sm" />
                </div>

                <!-- 图标 -->
                <div
                  class="size-14 rounded-xl flex items-center justify-center mb-4 transition-transform group-hover:scale-110"
                  :class="{
                    'bg-blue-500/15': (sceneConfig[scene.value]?.color || 'blue') === 'blue',
                    'bg-green-500/15': sceneConfig[scene.value]?.color === 'green',
                    'bg-purple-500/15': sceneConfig[scene.value]?.color === 'purple',
                  }"
                >
                  <i
                    class="text-2xl"
                    :class="[
                      sceneConfig[scene.value]?.icon || 'i-tabler:robot',
                      {
                        'text-blue-500': (sceneConfig[scene.value]?.color || 'blue') === 'blue',
                        'text-green-500': sceneConfig[scene.value]?.color === 'green',
                        'text-purple-500': sceneConfig[scene.value]?.color === 'purple',
                      },
                    ]"
                  />
                </div>

                <h3 class="text-lg font-semibold text-default mb-2">
                  {{ sceneConfig[scene.value]?.label || scene.label }}
                </h3>
                <p class="text-sm text-muted leading-relaxed">
                  {{ sceneConfig[scene.value]?.desc || scene.label }}
                </p>
              </div>
            </div>
          </div>

          <!-- Provider -->
          <div v-else-if="currentStepKey === 'provider'" class="space-y-6">
            <div class="bg-default rounded-2xl border border-muted p-6">
              <div class="flex items-center gap-3 mb-5">
                <div class="size-10 rounded-lg bg-primary/15 flex items-center justify-center">
                  <i class="i-tabler:server text-primary" />
                </div>
                <div>
                  <h3 class="font-semibold text-default">AI 服务商</h3>
                  <p class="text-sm text-muted">选择已有的或填入一个新的服务商</p>
                </div>
              </div>

              <div class="grid grid-cols-2 gap-3 mb-5">
                <div
                  v-for="opt in [
                    { value: 'reuse', label: '使用已有服务商' },
                    { value: 'create', label: '添加新服务商' },
                  ]"
                  :key="opt.value"
                  class="rounded-lg border px-4 py-2.5 text-center cursor-pointer text-sm transition-all"
                  :class="form.provider_mode === opt.value
                    ? 'border-primary bg-primary/5 text-primary font-medium'
                    : 'border-muted text-muted hover:border-primary/50'"
                  @click="form.provider_mode = opt.value"
                >
                  {{ opt.label }}
                </div>
              </div>

              <NForm label-placement="top">
                <template v-if="form.provider_mode === 'reuse'">
                  <NFormItem label="选择服务商">
                    <NSelect v-model:value="form.provider_reuse_id" :options="providerOptions" placeholder="选择一个已有的服务商" />
                  </NFormItem>
                </template>
                <template v-else>
                  <NFormItem label="协议类型">
                    <NSelect
                      v-model:value="form.provider_protocol"
                      :options="protocolOptions"
                      @update:value="onProtocolChange"
                    />
                  </NFormItem>
                  <NFormItem label="服务商名称">
                    <NInput v-model:value="form.provider_name" placeholder="给它起个名字，比如：我的 OpenAI" />
                  </NFormItem>
                  <NFormItem label="API Key">
                    <NInput v-model:value="form.provider_api_key" type="password" show-password-on="mousedown" placeholder="填入你的 API Key" />
                  </NFormItem>
                  <NFormItem label="接口地址 (Base URL)">
                    <NInput v-model:value="form.provider_base_url" placeholder="服务商提供的接口地址" />
                  </NFormItem>
                </template>
              </NForm>
            </div>
          </div>

          <!-- Model -->
          <div v-else-if="currentStepKey === 'model'" class="space-y-6">
            <div class="flex items-center gap-3 p-4 rounded-xl bg-blue-500/10 border border-blue-500/20">
              <i class="i-tabler:info-circle text-xl text-blue-500 flex-none" />
              <p class="text-sm text-blue-700 dark:text-blue-300">
                只需填写模型 ID 即可快速开始，其他高级参数可以稍后到模型管理页面补充
              </p>
            </div>

            <!-- Chat 模型 -->
            <div class="bg-default rounded-2xl border border-muted p-6">
              <div class="flex items-center gap-3 mb-5">
                <div class="size-10 rounded-lg bg-blue-500/15 flex items-center justify-center">
                  <i class="i-tabler:message-chatbot text-blue-500" />
                </div>
                <div>
                  <h3 class="font-semibold text-default">对话模型</h3>
                  <p class="text-sm text-muted">用于智能体对话的核心模型</p>
                </div>
              </div>

              <div class="grid grid-cols-2 gap-3 mb-4">
                <div
                  v-for="opt in [
                    { value: 'reuse', label: '使用已有模型' },
                    { value: 'create', label: '添加新模型' },
                  ]"
                  :key="opt.value"
                  class="rounded-lg border px-4 py-2.5 text-center cursor-pointer text-sm transition-all"
                  :class="form.chat_model_mode === opt.value
                    ? 'border-primary bg-primary/5 text-primary font-medium'
                    : 'border-muted text-muted hover:border-primary/50'"
                  @click="form.chat_model_mode = opt.value"
                >
                  {{ opt.label }}
                </div>
              </div>

              <NForm label-placement="top">
                <NFormItem v-if="form.chat_model_mode === 'reuse'" label="选择模型">
                  <NSelect v-model:value="form.chat_model_reuse_id" :options="chatModelOptions" placeholder="选择一个已有的对话模型" />
                </NFormItem>
                <template v-else>
                  <NFormItem label="模型名称">
                    <NInput v-model:value="form.chat_model_name" placeholder="比如：助手主模型" />
                  </NFormItem>
                  <NFormItem label="远端模型 ID">
                    <NInput v-model:value="form.chat_model" placeholder="比如：gpt-4.1、qwen-plus、deepseek-chat" />
                  </NFormItem>
                </template>
              </NForm>
            </div>

            <!-- Embedding 模型（仅知识库场景） -->
            <div v-if="isKnowledgeScene" class="bg-default rounded-2xl border border-muted p-6">
              <div class="flex items-center gap-3 mb-5">
                <div class="size-10 rounded-lg bg-purple-500/15 flex items-center justify-center">
                  <i class="i-tabler:vector text-purple-500" />
                </div>
                <div>
                  <h3 class="font-semibold text-default">向量模型</h3>
                  <p class="text-sm text-muted">将文档转换为向量，让 AI 能理解你的知识</p>
                </div>
              </div>

              <div class="grid grid-cols-2 gap-3 mb-4">
                <div
                  v-for="opt in [
                    { value: 'reuse', label: '使用已有模型' },
                    { value: 'create', label: '添加新模型' },
                  ]"
                  :key="opt.value"
                  class="rounded-lg border px-4 py-2.5 text-center cursor-pointer text-sm transition-all"
                  :class="form.embedding_model_mode === opt.value
                    ? 'border-primary bg-primary/5 text-primary font-medium'
                    : 'border-muted text-muted hover:border-primary/50'"
                  @click="form.embedding_model_mode = opt.value"
                >
                  {{ opt.label }}
                </div>
              </div>

              <NForm label-placement="top">
                <NFormItem v-if="form.embedding_model_mode === 'reuse'" label="选择模型">
                  <NSelect v-model:value="form.embedding_model_reuse_id" :options="embeddingModelOptions" placeholder="选择一个已有的向量模型" />
                </NFormItem>
                <template v-else>
                  <NFormItem label="模型名称">
                    <NInput v-model:value="form.embedding_model_name" placeholder="比如：知识库向量模型" />
                  </NFormItem>
                  <NFormItem label="远端模型 ID">
                    <NInput v-model:value="form.embedding_model" placeholder="比如：text-embedding-v3" />
                  </NFormItem>
                </template>
              </NForm>
            </div>
          </div>

          <!-- Channel (IM only) -->
          <div v-else-if="currentStepKey === 'channel'" class="space-y-6">
            <div class="flex items-center gap-3 p-4 rounded-xl bg-green-500/10 border border-green-500/20">
              <i class="i-tabler:brand-wechat text-xl text-green-500 flex-none" />
              <p class="text-sm text-green-700 dark:text-green-300">
                先选择或创建机器人渠道，再进入助手配置
              </p>
            </div>

            <div class="bg-default rounded-2xl border border-muted p-6">
              <div class="flex items-center gap-3 mb-5">
                <div class="size-10 rounded-lg bg-green-500/15 flex items-center justify-center">
                  <i class="i-tabler:message-circle text-green-500" />
                </div>
                <div>
                  <h3 class="font-semibold text-default">机器人渠道</h3>
                  <p class="text-sm text-muted">选择已有的或快速创建一个新机器人</p>
                </div>
              </div>

              <div class="grid grid-cols-2 gap-3 mb-5">
                <div
                  v-for="opt in [
                    { value: 'reuse', label: '使用已有机器人' },
                    { value: 'create', label: '添加新机器人' },
                  ]"
                  :key="opt.value"
                  class="rounded-lg border px-4 py-2.5 text-center cursor-pointer text-sm transition-all"
                  :class="form.bot_mode === opt.value
                    ? 'border-primary bg-primary/5 text-primary font-medium'
                    : 'border-muted text-muted hover:border-primary/50'"
                  @click="form.bot_mode = opt.value"
                >
                  {{ opt.label }}
                </div>
              </div>

              <template v-if="form.bot_mode === 'reuse'">
                <NForm label-placement="top">
                  <NFormItem label="绑定机器人" required>
                    <NSelect
                      v-model:value="form.bot_codes"
                      :options="botOptions"
                      multiple
                      filterable
                      placeholder="至少选择一个机器人渠道"
                    />
                  </NFormItem>
                </NForm>
                <div v-if="!botOptions.length" class="mt-2 rounded-xl border border-amber-500/20 bg-amber-500/5 p-4">
                  <div class="flex items-center gap-2 text-sm text-amber-700 dark:text-amber-300">
                    <i class="i-tabler:alert-circle" />
                    当前还没有可用机器人
                  </div>
                  <div class="mt-3 flex items-center gap-3">
                    <NButton size="small" type="primary" @click="form.bot_mode = 'create'">
                      去创建机器人
                    </NButton>
                    <NButton size="small" tertiary @click="refreshBots">
                      刷新列表
                    </NButton>
                  </div>
                </div>
              </template>

              <template v-else>
                <NForm label-placement="top">
                  <NFormItem label="机器人名称" required>
                    <NInput v-model:value="newBot.name" placeholder="比如：官网客服机器人" />
                  </NFormItem>
                  <NFormItem label="机器人编码（可选）">
                    <NInput v-model:value="newBot.code" placeholder="留空将自动生成唯一编码" />
                  </NFormItem>
                  <NFormItem label="平台">
                    <NSelect v-model:value="newBot.platform" :options="botPlatformOptions" />
                  </NFormItem>
                  <NFormItem label="实例回调密钥（可选）" description="用于 webhook 额外验签，不同平台可按需配置">
                    <NInput v-model:value="newBot.verify_secret" />
                  </NFormItem>

                  <template v-if="isDingtalkBot">
                    <NFormItem label="消息 Webhook" required description="钉钉自定义机器人完整 webhook 地址">
                      <NInput v-model:value="newBot.config.webhook" />
                    </NFormItem>
                    <NFormItem label="Webhook 加签密钥（可选）">
                      <NInput v-model:value="newBot.config.sign_secret" type="password" show-password-on="mousedown" />
                    </NFormItem>
                  </template>

                  <template v-if="isFeishuBot">
                    <NFormItem label="App ID" required>
                      <NInput v-model:value="newBot.config.app_id" />
                    </NFormItem>
                    <NFormItem label="App Secret" required>
                      <NInput v-model:value="newBot.config.app_secret" type="password" show-password-on="mousedown" />
                    </NFormItem>
                    <NFormItem label="Verification Token（可选）">
                      <NInput v-model:value="newBot.config.verification_token" />
                    </NFormItem>
                    <NFormItem label="Encrypt Key（可选）">
                      <NInput v-model:value="newBot.config.encrypt_key" type="password" show-password-on="mousedown" />
                    </NFormItem>
                  </template>

                  <template v-if="isQQBot">
                    <NFormItem label="App ID" required>
                      <NInput v-model:value="newBot.config.app_id" />
                    </NFormItem>
                    <NFormItem label="App Secret" required>
                      <NInput v-model:value="newBot.config.app_secret" type="password" show-password-on="mousedown" />
                    </NFormItem>
                    <NFormItem label="回调 Token（可选）">
                      <NInput v-model:value="newBot.config.token" />
                    </NFormItem>
                  </template>

                  <template v-if="isWecomBot">
                    <NFormItem label="企业 ID (CorpID)" required>
                      <NInput v-model:value="newBot.config.corp_id" />
                    </NFormItem>
                    <NFormItem label="应用 Secret" required>
                      <NInput v-model:value="newBot.config.app_secret" type="password" show-password-on="mousedown" />
                    </NFormItem>
                    <NFormItem label="应用 AgentId" required>
                      <NInputNumber v-model:value="newBot.config.agent_id" :min="1" class="w-full" />
                    </NFormItem>
                    <NFormItem label="回调 Token（可选）">
                      <NInput v-model:value="newBot.config.token" />
                    </NFormItem>
                    <NFormItem label="EncodingAESKey（可选）">
                      <NInput v-model:value="newBot.config.aes_key" />
                    </NFormItem>
                  </template>

                  <NFormItem label="备注（可选）">
                    <NInput v-model:value="newBot.remark" placeholder="用于区分不同渠道配置" />
                  </NFormItem>
                </NForm>
                <div class="mt-2 text-sm text-muted">
                  该机器人会在最后一步和服务商/模型/助手一起创建，避免中途产生半成品配置。
                </div>
              </template>
            </div>
          </div>

          <!-- Assistant -->
          <div v-else-if="currentStepKey === 'assistant'" class="space-y-6">
            <div class="bg-default rounded-2xl border border-muted p-6 space-y-5">
              <div class="flex items-center gap-3 mb-2">
                <div class="size-10 rounded-lg bg-primary/15 flex items-center justify-center">
                  <i class="i-tabler:robot text-primary" />
                </div>
                <div>
                  <h3 class="font-semibold text-default">基本信息</h3>
                  <p class="text-sm text-muted">给你的 AI 助手一个身份</p>
                </div>
              </div>

              <NForm label-placement="top">
                <NFormItem label="助手名称" required>
                  <NInput v-model:value="form.agent_name" placeholder="比如：客服小助手、文档问答专家" />
                </NFormItem>
                <NFormItem label="性格设定（系统提示词）">
                  <NInput
                    v-model:value="form.agent_instructions"
                    type="textarea"
                    :rows="3"
                    placeholder="告诉 AI 它是谁、该怎么说话，比如：你是一个友好的客服助手，回答要简洁明了..."
                  />
                </NFormItem>
              </NForm>
            </div>

            <!-- 能力选择 -->
            <div v-if="toolOptions.length" class="bg-default rounded-2xl border border-muted p-6">
              <div class="flex items-center gap-3 mb-5">
                <div class="size-10 rounded-lg bg-amber-500/15 flex items-center justify-center">
                  <i class="i-tabler:puzzle text-amber-500" />
                </div>
                <div>
                  <h3 class="font-semibold text-default">能力扩展</h3>
                  <p class="text-sm text-muted">勾选后 AI 就能使用这些技能（可选）</p>
                </div>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div
                  v-for="item in toolOptions"
                  :key="item.value"
                  class="flex items-start gap-3 rounded-xl border p-4 cursor-pointer transition-all select-none"
                  :class="(form.tool_codes || []).includes(item.value)
                    ? 'border-primary/50 bg-primary/5'
                    : 'border-muted hover:border-primary/30'"
                  @click="toggleTool(item.value)"
                >
                  <div
                    class="size-5 rounded border-2 flex items-center justify-center flex-none mt-0.5 transition-all"
                    :class="(form.tool_codes || []).includes(item.value)
                      ? 'border-primary bg-primary'
                      : 'border-muted'"
                  >
                    <i v-if="(form.tool_codes || []).includes(item.value)" class="i-tabler:check text-white text-xs" />
                  </div>
                  <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                      <span class="font-medium text-default text-sm">{{ item.label }}</span>
                      <span v-if="item.needsConfig" class="inline-flex items-center rounded-full bg-amber-500/10 px-1.5 py-0.5 text-[11px] text-amber-600 leading-none">需配置</span>
                    </div>
                    <p v-if="item.description" class="text-xs text-muted mt-1 line-clamp-1">
                      {{ item.description }}
                    </p>
                  </div>
                </div>
              </div>

              <div v-if="selectedConfigToolOptions.length" class="mt-4 flex items-center gap-2 p-3 rounded-lg bg-amber-500/5 border border-amber-500/15 text-sm text-amber-700 dark:text-amber-300">
                <i class="i-tabler:info-circle flex-none" />
                <span>
                  <span class="font-medium">{{ selectedConfigToolOptions.map(t => t.label).join('、') }}</span>
                  创建后需要到助手详情补充配置才能正常使用
                </span>
              </div>
            </div>

            <!-- 知识库场景 -->
            <div v-if="isKnowledgeScene" class="bg-default rounded-2xl border border-muted p-6">
              <div class="flex items-center gap-3 mb-4">
                <div class="size-10 rounded-lg bg-purple-500/15 flex items-center justify-center">
                  <i class="i-tabler:database text-purple-500" />
                </div>
                <div>
                  <h3 class="font-semibold text-default">知识库配置</h3>
                  <p class="text-sm text-muted">配置文档的存储和检索方式</p>
                </div>
              </div>

              <NForm label-placement="top">
                <NFormItem label="存储驱动" required>
                  <NSelect v-model:value="form.storage_id" :options="storageOptions" placeholder="选择文件存储方式" />
                </NFormItem>
                <NFormItem label="向量数据库" required>
                  <NSelect v-model:value="form.vector_id" :options="vectorOptions" placeholder="选择向量存储" />
                </NFormItem>
                <NFormItem label="文档解析（可选）">
                  <NSelect v-model:value="form.parse_provider_id" :options="parserOptions" clearable placeholder="不选则使用默认解析" />
                </NFormItem>
              </NForm>
            </div>
          </div>

          <!-- Confirm -->
          <div v-else class="space-y-6">
            <template v-if="createdResult">
              <!-- 成功状态 -->
              <div class="text-center py-8">
                <div class="size-20 rounded-full bg-green-500/15 flex items-center justify-center mx-auto mb-6">
                  <i class="i-tabler:check text-4xl text-green-500" />
                </div>
                <h2 class="text-2xl font-bold text-default mb-3">
                  配置完成！
                </h2>
                <p class="text-muted text-lg">
                  你的 AI 助手已经准备就绪，现在就去试试吧
                </p>
              </div>

              <!-- 创建结果 -->
              <div class="bg-default rounded-2xl border border-muted p-6">
                <h3 class="font-semibold text-default mb-4">创建详情</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                  <div class="flex items-center gap-3 p-3 rounded-lg bg-muted/5">
                    <i class="i-tabler:layout-grid text-muted" />
                    <span class="text-muted">场景</span>
                    <span class="ml-auto font-medium text-default">{{ sceneConfig[createdResult.scene]?.label || createdResult.scene || '-' }}</span>
                  </div>
                  <div class="flex items-center gap-3 p-3 rounded-lg bg-muted/5">
                    <i class="i-tabler:server text-muted" />
                    <span class="text-muted">服务商</span>
                    <span class="ml-auto font-medium text-default">{{ providerDisplayName }}</span>
                  </div>
                  <div class="flex items-center gap-3 p-3 rounded-lg bg-muted/5">
                    <i class="i-tabler:message-chatbot text-muted" />
                    <span class="text-muted">对话模型</span>
                    <span class="ml-auto font-medium text-default">{{ chatModelDisplayName }}</span>
                  </div>
                  <div class="flex items-center gap-3 p-3 rounded-lg bg-muted/5">
                    <i class="i-tabler:vector text-muted" />
                    <span class="text-muted">向量模型</span>
                    <span class="ml-auto font-medium text-default">{{ embeddingModelDisplayName }}</span>
                  </div>
                  <div class="flex items-center gap-3 p-3 rounded-lg bg-muted/5">
                    <i class="i-tabler:robot text-muted" />
                    <span class="text-muted">智能体</span>
                    <span class="ml-auto font-medium text-default">{{ agentDisplayName }}</span>
                  </div>
                  <div v-if="createdResult.rag_provider_id" class="flex items-center gap-3 p-3 rounded-lg bg-muted/5">
                    <i class="i-tabler:books text-muted" />
                    <span class="text-muted">知识库配置</span>
                    <span class="ml-auto font-medium text-default">{{ ragDisplayName }}</span>
                  </div>
                </div>
              </div>

              <!-- 操作按钮 -->
              <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div
                  class="group rounded-2xl border border-muted bg-default p-5 cursor-pointer transition-all duration-300 hover:border-primary hover:shadow-lg hover:-translate-y-1"
                  @click="gotoChat"
                >
                  <div class="size-10 rounded-lg bg-primary/15 flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                    <i class="i-tabler:message-circle text-primary" />
                  </div>
                  <div class="font-medium text-default text-sm">开始对话</div>
                </div>
                <div
                  class="group rounded-2xl border border-muted bg-default p-5 cursor-pointer transition-all duration-300 hover:border-blue-500 hover:shadow-lg hover:-translate-y-1"
                  @click="gotoAgentEdit"
                >
                  <div class="size-10 rounded-lg bg-blue-500/15 flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                    <i class="i-tabler:settings text-blue-500" />
                  </div>
                  <div class="font-medium text-default text-sm">编辑助手</div>
                </div>
                <div
                  class="group rounded-2xl border border-muted bg-default p-5 cursor-pointer transition-all duration-300 hover:border-purple-500 hover:shadow-lg hover:-translate-y-1"
                  @click="gotoProvider"
                >
                  <div class="size-10 rounded-lg bg-purple-500/15 flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                    <i class="i-tabler:server text-purple-500" />
                  </div>
                  <div class="font-medium text-default text-sm">服务商管理</div>
                </div>
                <div
                  class="group rounded-2xl border border-muted bg-default p-5 cursor-pointer transition-all duration-300 hover:border-amber-500 hover:shadow-lg hover:-translate-y-1"
                  @click="gotoModel"
                >
                  <div class="size-10 rounded-lg bg-amber-500/15 flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                    <i class="i-tabler:cpu text-amber-500" />
                  </div>
                  <div class="font-medium text-default text-sm">模型管理</div>
                </div>
              </div>

              <div v-if="createdResult.rag_provider_id" class="flex justify-center">
                <NButton @click="gotoRagProvider">
                  <template #icon>
                    <i class="i-tabler:books" />
                  </template>
                  知识库配置
                </NButton>
              </div>
            </template>

            <template v-else>
              <!-- 确认预览 -->
              <div class="bg-default rounded-2xl border border-muted p-8 text-center">
                <div class="size-16 rounded-full bg-primary/15 flex items-center justify-center mx-auto mb-6">
                  <i class="i-tabler:rocket text-3xl text-primary" />
                </div>
                <h2 class="text-xl font-bold text-default mb-3">
                  确认无误，准备起飞？
                </h2>
                <p class="text-muted mb-6 max-w-lg mx-auto">
                  点击下方按钮后，系统将自动为你创建
                  <span class="text-primary font-medium">{{ sceneConfig[form.scene]?.label || meta.scenes.find((s: any) => s.value === form.scene)?.label || form.scene }}</span>
                  场景所需的服务商、模型和智能体配置
                </p>

                <div class="inline-flex items-center gap-4 px-6 py-4 rounded-xl bg-muted/5 text-sm flex-wrap justify-center">
                  <template v-for="(item, idx) in confirmFlowSteps" :key="idx">
                    <i v-if="idx > 0" class="i-tabler:arrow-right text-muted/50" />
                    <div class="flex items-center gap-2">
                      <i :class="item.icon" class="text-primary" />
                      <span class="text-muted">{{ item.label }}</span>
                    </div>
                  </template>
                </div>
              </div>
            </template>
          </div>

          <!-- 底部操作栏 -->
          <div class="mt-10 flex items-center justify-between">
            <NButton
              :disabled="step === 1 || !!createdResult"
              size="large"
              @click="prevStep"
            >
              <template #icon>
                <i class="i-tabler:arrow-left" />
              </template>
              上一步
            </NButton>

            <NSpace>
              <NButton
                v-if="step < totalSteps"
                type="primary"
                size="large"
                :disabled="!canNext"
                @click="nextStep"
              >
                继续
                <template #icon>
                  <i class="i-tabler:arrow-right" />
                </template>
              </NButton>
              <NButton
                v-else-if="!createdResult"
                type="primary"
                size="large"
                :loading="submitting"
                @click="submit"
              >
                <template #icon>
                  <i class="i-tabler:rocket" />
                </template>
                一键创建
              </NButton>
            </NSpace>
          </div>
        </template>
      </div>
    </div>
  </DuxPage>
</template>

<style scoped>
.bg-grid-pattern {
  background-image:
    linear-gradient(to right, rgba(0, 0, 0, 0.05) 1px, transparent 1px),
    linear-gradient(to bottom, rgba(0, 0, 0, 0.05) 1px, transparent 1px);
  background-size: 20px 20px;
}
</style>
