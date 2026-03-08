<script setup lang="ts">
import { useCustomMutation } from '@duxweb/dvha-core'
import { DuxSelect } from '@duxweb/dvha-naiveui'
import { DuxFormItem, DuxFormLayout, DuxPageTabForm, useDrawer, useModal } from '@duxweb/dvha-pro'
import { NButton, NInput, NInputNumber, NSwitch, NTabPane, NTag } from 'naive-ui'
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import {
  buildToolPayload,
  createToolItem,
  ensureToolSchema,
  schemaParamFields,
  toPlain,
} from './toolHelpers.js'

const request = useCustomMutation()
const modal = useModal()
const drawer = useDrawer()
const route = useRoute()
const id = computed(() => route.params.id as string | number | undefined)

interface BotOption {
  label: string
  value: string
  desc?: string
  platform?: string
  platform_name?: string
  icon?: string
  color?: string
  style?: {
    iconClass?: string
    iconBgClass?: string
  }
}

const toolRegistry = ref<Record<string, any>>({})
const botOptions = ref<BotOption[]>([])
const botLoading = ref(false)

const defaultToolStyle = { icon: 'i-tabler:puzzle', color: 'primary', iconClass: 'text-primary', bgClass: 'bg-primary/10' }
const defaultBotStyle = { icon: 'i-tabler:robot', iconClass: 'text-primary', bgClass: 'bg-primary/10', label: '机器人' }

function normalizeIconBgClass(value: unknown, fallback: string): string {
  const text = String(value || '').trim()
  if (!text) {
    return fallback
  }
  const tokens = text.split(/\s+/).filter(Boolean).map((token) => {
    if (!token.startsWith('bg-')) {
      return token
    }
    if (token.includes('/')) {
      return token
    }
    if (token === 'bg-container') {
      return token
    }
    return `${token}/10`
  })
  return tokens.length ? tokens.join(' ') : fallback
}

function deriveVisualClassFromColor(color: unknown): { iconClass: string, bgClass: string } {
  const tone = String(color || '').trim()
  if (!tone) {
    return { iconClass: defaultToolStyle.iconClass, bgClass: defaultToolStyle.bgClass }
  }
  return {
    iconClass: `text-${tone}`,
    bgClass: `bg-${tone}/10`,
  }
}

function resolveToolStyle(tool: any) {
  const code = String(tool?.code || '')
  const meta = code ? toolRegistry.value[code] : null
  const color = String(meta?.color || tool?.color || '').trim() || defaultToolStyle.color
  const visualClass = deriveVisualClassFromColor(color)
  return {
    icon: String(meta?.icon || tool?.icon || '').trim() || defaultToolStyle.icon,
    iconClass: visualClass.iconClass,
    bgClass: normalizeIconBgClass(visualClass.bgClass, defaultToolStyle.bgClass),
  }
}

const model = ref<Record<string, any>>({
  name: undefined,
  code: undefined,
  model_id: undefined,
  instructions: undefined,
  tools: [],
  settings: {
    temperature: 0.7,
    summary_max_tokens: 50000,
    summary_messages_keep: 5,
    debug_enabled: false,
    bot_codes: undefined,
  },
  active: true,
  description: undefined,
})

async function loadToolRegistry() {
  const res = await request.mutateAsync({
    path: 'ai/agent/tool',
    method: 'GET',
  })
  const list = Array.isArray(res.data) ? res.data : []
  const map: Record<string, any> = {}
  list.forEach((item: any) => {
    const code = item.code || item.value
    if (code)
      map[String(code)] = item
  })
  toolRegistry.value = map
}

function ensureSettingsContainer() {
  if (!model.value.settings || typeof model.value.settings !== 'object') {
    model.value.settings = {}
  }
}

function normalizeBotCodes(value: unknown): string[] {
  if (!Array.isArray(value)) {
    return []
  }
  const list = value
    .map(item => String(item || '').trim())
    .filter(item => item !== '')
  return [...new Set(list)]
}

function getCurrentBotCodes(): string[] {
  return normalizeBotCodes(model.value?.settings?.bot_codes)
}

function setCurrentBotCodes(codes: string[]) {
  ensureSettingsContainer()
  model.value.settings.bot_codes = codes.length ? [...new Set(codes)] : undefined
}

async function loadBotOptions() {
  botLoading.value = true
  try {
    const res = await request.mutateAsync({
      path: 'boot/bot/options',
      method: 'GET',
      query: { enabled: 1 },
    })
    const list = Array.isArray(res.data) ? res.data : []
    botOptions.value = list
      .map((item: any) => ({
        label: String(item?.label || ''),
        value: String(item?.value || ''),
        desc: String(item?.desc || ''),
        platform: String(item?.platform || ''),
        platform_name: String(item?.platform_name || ''),
        icon: String(item?.icon || ''),
        color: String(item?.color || ''),
        style: {
          iconClass: String(item?.style?.iconClass || ''),
          iconBgClass: String(item?.style?.iconBgClass || ''),
        },
      }))
      .filter(item => item.value !== '')
  }
  finally {
    botLoading.value = false
  }
}

const botOptionsMap = computed(() => {
  return botOptions.value.reduce((acc: Record<string, BotOption>, item) => {
    acc[item.value] = item
    return acc
  }, {})
})

const selectedBots = computed<BotOption[]>(() => {
  return getCurrentBotCodes().map((code) => {
    const item = botOptionsMap.value[code]
    if (item) {
      return item
    }
    return {
      label: code,
      value: code,
      desc: '已绑定机器人',
      platform: '',
      platform_name: '',
      icon: '',
      color: '',
      style: {
        iconClass: '',
        iconBgClass: '',
      },
    }
  })
})

function resolveBotMeta(bot: BotOption) {
  return {
    icon: String(bot.icon || '').trim() || defaultBotStyle.icon,
    iconClass: String(bot.style?.iconClass || '').trim() || defaultBotStyle.iconClass,
    bgClass: normalizeIconBgClass(bot.style?.iconBgClass || '', defaultBotStyle.bgClass),
    label: String(bot.platform_name || bot.platform || '').trim() || defaultBotStyle.label,
  }
}

async function openBotSelectModal() {
  if (!botOptions.value.length) {
    await loadBotOptions()
  }
  try {
    const result = await modal.show({
      title: '选择机器人',
      width: 760,
      component: () => import('./components/BotSelectModal.vue'),
      componentProps: {
        selectedCodes: getCurrentBotCodes(),
      },
    })
    if (Array.isArray(result)) {
      setCurrentBotCodes(normalizeBotCodes(result))
    }
  }
  catch {
    // ignore
  }
}

function removeBot(code: string) {
  setCurrentBotCodes(getCurrentBotCodes().filter(item => item !== code))
}

function clearBots() {
  setCurrentBotCodes([])
}

function normalizeToolForEdit(item: any) {
  const base = { ...createToolItem(), ...toPlain(item) }
  ensureToolSchema(base)
  const meta = base.code ? toolRegistry.value[String(base.code)] : null
  if (meta?.settings)
    base._settings = meta.settings
  return base
}

async function openToolModal(tool?: any, index: number | null = null) {
  const draft = normalizeToolForEdit(tool || createToolItem())
  try {
    const result = await drawer.show({
      title: '能力配置',
      width: 780,
      component: () => import('./components/ToolConfigDrawer.vue'),
      componentProps: {
        tool: draft,
        toolRegistry: toolRegistry.value,
        schemaParamFields,
      },
    })
    if (!result)
      return

    ensureToolSchema(result)
    const payload = buildToolPayload(result)
    const tools = toPlain(model.value.tools || [])
    if (index === null || index < 0)
      tools.push(payload)
    else
      tools.splice(index, 1, payload)
    model.value.tools = tools
  }
  catch {
    // ignore
  }
}

const openCreateTool = () => openToolModal(null, null)

function openEditTool(index: number) {
  const existing = model.value.tools[index]
  if (!existing)
    return
  openToolModal(existing, index)
}

function removeTool(index: number) {
  const tools = toPlain(model.value.tools || [])
  tools.splice(index, 1)
  model.value.tools = tools
}

function resolveToolLabel(tool: any) {
  const meta = tool?.code ? toolRegistry.value[String(tool.code)] : null
  return meta?.label || meta?.name || tool?.code || '能力'
}

onMounted(async () => {
  await Promise.allSettled([
    loadToolRegistry(),
    loadBotOptions(),
  ])
})
</script>

<template>
  <DuxPageTabForm
    :id="id"
    path="ai/agent"
    :data="model"
    default-tab="base"
    invalidate="ai/agent"
  >
    <NTabPane name="base" label="基本信息">
      <DuxFormLayout label-placement="page" divider class="pb-4" label-align="left">
        <DuxFormItem label="智能体名称" required>
          <NInput v-model:value="model.name" placeholder="请输入智能体名称" />
        </DuxFormItem>
        <DuxFormItem label="唯一标识" description="可选，不填自动生成 10 位字母+数字">
          <NInput v-model:value="model.code" placeholder="如 customer_helper（可留空）" />
        </DuxFormItem>
        <DuxFormItem label="说明">
          <NInput v-model:value="model.description" type="textarea" :rows="2" placeholder="备注信息" />
        </DuxFormItem>
        <DuxFormItem label="LLM模型">
          <DuxSelect
            v-model:value="model.model_id"
            path="ai/model"
            :params="{ tab: 'chat' }"
            label-field="name"
            value-field="id"
            placeholder="选择绑定模型"
          />
        </DuxFormItem>
        <DuxFormItem label="温度">
          <NInputNumber v-model:value="model.settings.temperature" placeholder="0.7" :min="0" :max="1" :step="0.1" />
        </DuxFormItem>
        <DuxFormItem label="系统提示词">
          <NInput
            v-model:value="model.instructions"
            type="textarea"
            :rows="3"
            placeholder="为智能体设定角色、目标与安全边界"
          />
        </DuxFormItem>
        <DuxFormItem label="调试模式" description="仅在调试模式下输出详细日志">
          <NSwitch v-model:value="model.settings.debug_enabled" />
        </DuxFormItem>
        <DuxFormItem label="压缩阈值（Token）" description="超过阈值后启用历史摘要压缩">
          <NInputNumber v-model:value="model.settings.summary_max_tokens" :min="0" />
        </DuxFormItem>
        <DuxFormItem label="保留消息数" description="摘要触发后保留最近 N 条历史消息（默认 5，设为 0 可关闭摘要）">
          <NInputNumber v-model:value="model.settings.summary_messages_keep" :min="0" :precision="0" />
        </DuxFormItem>
        <DuxFormItem label="启用">
          <NSwitch v-model:value="model.active" />
        </DuxFormItem>
      </DuxFormLayout>
    </NTabPane>

    <NTabPane name="channel" label="渠道绑定">
      <div class="pb-4 max-w-4xl mx-auto">
        <!-- 头部 -->
        <div class="flex items-center justify-between py-4">
          <div>
            <div class="text-base font-medium">机器人绑定</div>
            <div class="text-sm text-muted mt-1">绑定多个机器人会话通道，异步结果可自动回写并发送</div>
          </div>
          <div class="flex items-center gap-2">
            <NButton secondary type="primary" :loading="botLoading" @click="openBotSelectModal">
              <template #icon>
                <i class="i-tabler:plus" />
              </template>
              添加
            </NButton>
            <NButton v-if="selectedBots.length" tertiary @click="clearBots">
              清空
            </NButton>
          </div>
        </div>
        <!-- 空状态 -->
        <div
          v-if="!selectedBots.length"
          class="border border-dashed border-muted rounded-lg py-12 text-center"
        >
          <i class="i-tabler:message-chatbot text-3xl text-muted" />
          <div class="text-sm text-muted mt-3">暂未绑定机器人</div>
          <NButton class="mt-4" type="primary" size="small" ghost @click="openBotSelectModal">
            添加机器人
          </NButton>
        </div>
        <!-- 列表 -->
        <div v-else class="border border-muted rounded-lg overflow-hidden">
          <div
            v-for="(bot, idx) in selectedBots"
            :key="bot.value"
            class="flex items-center gap-4 p-4 hover:bg-fill transition-colors"
            :class="{ 'border-t border-muted': idx > 0 }"
          >
            <div class="size-12 rounded-lg flex items-center justify-center flex-shrink-0" :class="resolveBotMeta(bot).bgClass">
              <div class="size-6" :class="[resolveBotMeta(bot).icon, resolveBotMeta(bot).iconClass]" />
            </div>
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2">
                <span class="text-base font-medium truncate">{{ resolveBotMeta(bot).label }}</span>
              </div>
              <div class="text-sm text-muted mt-0.5 truncate">{{ bot.value }}</div>
            </div>
            <div>
              <NTag type="default" round>
              {{ bot.label }}
              </NTag>
            </div>
            <NButton quaternary circle  @click="removeBot(bot.value)">
              <template #icon>
                <i class="i-tabler:x" />
              </template>
            </NButton>
          </div>
        </div>
      </div>
    </NTabPane>

    <NTabPane name="tool" label="能力配置">
      <div class="pb-4 max-w-4xl mx-auto">
        <!-- 头部 -->
        <div class="flex items-center justify-between py-4">
          <div>
            <div class="text-base font-medium">能力绑定</div>
            <div class="text-sm text-muted mt-1">为智能体添加可调用能力，扩展其执行边界</div>
          </div>
          <NButton secondary type="primary" @click="openCreateTool">
            <template #icon>
              <i class="i-tabler:plus" />
            </template>
            添加能力
          </NButton>
        </div>
        <!-- 空状态 -->
        <div
          v-if="!model.tools.length"
          class="border border-dashed border-muted rounded-lg py-12 text-center"
        >
          <i class="i-tabler:puzzle text-3xl text-muted" />
          <div class="text-sm text-muted mt-3">暂未绑定能力</div>
          <NButton class="mt-4" type="primary" size="small" ghost @click="openCreateTool">
            添加能力
          </NButton>
        </div>
        <!-- 列表 -->
        <div v-else class="border border-muted rounded-lg overflow-hidden">
          <div
            v-for="(tool, index) in model.tools"
            :key="index"
            class="flex items-center gap-4 p-4 hover:bg-fill transition-colors cursor-pointer"
            :class="{ 'border-t border-muted': Number(index) > 0 }"
            @click="openEditTool(Number(index))"
          >
            <div class="size-12 rounded-lg flex items-center justify-center flex-shrink-0" :class="resolveToolStyle(tool).bgClass">
              <i class="size-6" :class="[resolveToolStyle(tool).icon, resolveToolStyle(tool).iconClass]" />
            </div>
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2">
                <span class="text-base font-medium truncate">{{ tool.label || tool.name || resolveToolLabel(tool) || '未命名能力' }}</span>
              </div>
              <div class="text-sm text-muted mt-0.5 line-clamp-1">{{ tool.description || '点击配置能力' }}</div>
            </div>
            <div v-if="tool.code">
              <NTag type="default" round>
                {{ tool.code }}
              </NTag>
            </div>
            <NButton quaternary circle @click.stop="removeTool(Number(index))">
              <template #icon>
                <i class="i-tabler:trash" />
              </template>
            </NButton>
          </div>
        </div>
      </div>
    </NTabPane>
  </DuxPageTabForm>
</template>
