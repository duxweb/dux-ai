<script setup lang="ts">
import { useCustomMutation } from '@duxweb/dvha-core'
import { DuxModalPage } from '@duxweb/dvha-pro'
import { marked } from 'marked'
import { NEmpty, NImage, NSpin, NTag, useMessage } from 'naive-ui'
import { computed, onMounted, ref } from 'vue'
import { stringifyContent } from '../Agent/chatMessageMedia'
import { mapOpenAiUiMessages } from '../Agent/chatMessageMapper'

interface ChatMessage {
  role: 'user' | 'assistant' | 'system' | 'tool'
  content: any
  meta?: Record<string, any>
}

const props = defineProps<{
  agentCode: string
  sessionId: number
  title?: string
}>()

const request = useCustomMutation()
const message = useMessage()
const loading = ref(false)
const messages = ref<ChatMessage[]>([])

const sessionTitle = computed(() => props.title || `会话 #${props.sessionId}`)

function renderMessageContent(msg: ChatMessage): string {
  const text = stringifyContent(msg.content)
  const result = marked.parse(text || '')
  return typeof result === 'string' ? result : ''
}

function renderRoleLabel(role: ChatMessage['role']): string {
  switch (role) {
    case 'assistant':
      return '助手'
    case 'system':
      return '系统'
    case 'tool':
      return '工具'
    default:
      return '用户'
  }
}

function formatMessageTime(msg: ChatMessage): string {
  const t = msg.meta?.created_at
  return t ? String(t) : '-'
}

async function loadMessages() {
  if (!props.agentCode || !props.sessionId) {
    return
  }
  loading.value = true
  try {
    const res = await request.mutateAsync({
      path: `ai/agent/chat/${encodeURIComponent(props.agentCode)}/sessions/${props.sessionId}/messages`,
      method: 'GET',
      query: { limit: 200 },
    })
    const list = Array.isArray(res?.data) ? res.data : (Array.isArray(res?.data?.data) ? res.data.data : [])
    messages.value = mapOpenAiUiMessages(list, { filterToolCallPlaceholder: true }) as ChatMessage[]
  }
  catch (error: any) {
    messages.value = []
    message.error(error?.message || '加载历史消息失败')
  }
  finally {
    loading.value = false
  }
}

onMounted(loadMessages)
</script>

<template>
  <DuxModalPage>
    <div class="space-y-4">
      <div class="flex items-center justify-between rounded-xl border border-muted bg-default px-4 py-3">
        <div class="min-w-0">
          <div class="font-medium truncate">
            {{ sessionTitle }}
          </div>
          <div class="text-xs text-muted mt-1">
            agent: {{ props.agentCode }} · session: {{ props.sessionId }}
          </div>
        </div>
        <NTag size="small" :bordered="false">
          只读历史
        </NTag>
      </div>

      <NSpin :show="loading">
        <div v-if="messages.length" class="space-y-5 md:space-y-6">
          <div
            v-for="(msg, index) in messages"
            :key="msg.meta?.id || index"
            class="flex gap-2.5 md:gap-3 animate-fade-in"
            :class="msg.role === 'user' ? 'justify-end' : 'justify-start'"
          >
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

            <div class="flex flex-col gap-1.5 max-w-[85%] md:max-w-[75%]">
              <div
                v-if="msg.role !== 'user'"
                class="text-sm font-semibold px-3"
                :class="msg.role === 'assistant'
                  ? 'text-primary'
                  : msg.role === 'tool'
                    ? 'text-warning'
                    : 'text-muted'"
              >
                {{ renderRoleLabel(msg.role) }}
              </div>

              <div
                class="group relative rounded-2xl px-4 md:px-5 py-3 md:py-3.5 text-[14px] md:text-[15px] leading-relaxed transition-all duration-200"
                :class="msg.role === 'user'
                  ? 'bg-primary text-white shadow-md shadow-primary/20 rounded-tr-sm'
                  : msg.role === 'assistant'
                    ? 'bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700 shadow-sm rounded-tl-sm'
                    : 'bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800/40 rounded-tl-sm'"
              >
                      <div v-if="Array.isArray(msg.meta?.card) && msg.meta?.card?.length" class="space-y-3">
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
                        </div>
                      </div>
                      <div
                        v-else
                        class="prose prose-sm max-w-none prose-p:my-1.5"
                        :class="msg.role === 'user'
                          ? 'text-white prose-p:leading-relaxed'
                          : msg.role === 'assistant'
                            ? 'text-default'
                            : msg.role === 'tool'
                              ? 'text-warning'
                              : 'text-default'"
                        v-html="renderMessageContent(msg)"
                      />

                <div v-if="Array.isArray(msg.meta?.images) && msg.meta?.images?.length" class="grid grid-cols-2 gap-2 mt-3">
                  <img
                    v-for="(img, i) in msg.meta?.images"
                    :key="i"
                    :src="img?.url || img"
                    object-fit="contain"
                    class="max-w-200px"
                  />
                </div>
                <div v-if="Array.isArray(msg.meta?.videos) && msg.meta?.videos?.length" class="space-y-2 mt-3">
                  <video
                    v-for="(video, i) in msg.meta?.videos"
                    :key="i"
                    :src="video?.url || video"
                    controls
                    preload="metadata"
                    class="w-full max-w-360px rounded-lg border border-default"
                  />
                </div>
                <div v-if="Array.isArray(msg.meta?.files) && msg.meta?.files?.length" class="space-y-2 mt-3">
                  <div
                    v-for="(file, i) in msg.meta?.files"
                    :key="i"
                    class="group/file flex items-center gap-2.5 px-3.5 py-2.5 rounded-xl text-sm border border-default"
                  >
                    <div class="size-8 rounded-lg flex items-center justify-center flex-none bg-primary/10">
                      <i class="i-tabler:paperclip text-base text-primary" />
                    </div>
                    <a :href="file?.url || file?.file_url || file" target="_blank" rel="noreferrer" class="truncate hover:underline flex-1 min-w-0">
                      {{ file?.filename || file?.name || file?.url || file?.file_url || file }}
                    </a>
                    <i class="i-tabler:external-link text-base opacity-50 group-hover/file:opacity-100 transition-opacity flex-none" />
                  </div>
                </div>
              </div>

              <div
                class="text-xs px-3 flex items-center gap-1.5 mt-1"
                :class="msg.role === 'user' ? 'text-gray-400 justify-end' : 'text-gray-500 dark:text-gray-500'"
              >
                <i class="i-tabler:clock text-xs" />
                <span>{{ formatMessageTime(msg) }}</span>
              </div>
            </div>

            <div v-if="msg.role === 'user'" class="flex-none">
              <div class="size-9 md:size-10 rounded-full bg-primary flex items-center justify-center">
                <i class="i-tabler:user text-lg md:text-xl text-white" />
              </div>
            </div>
          </div>
        </div>
        <div v-else class="py-16">
          <NEmpty description="暂无历史消息" />
        </div>
      </NSpin>
    </div>
  </DuxModalPage>
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
