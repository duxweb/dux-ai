<script setup lang="ts">
import { useCustomMutation, useManage } from '@duxweb/dvha-core'
import { DuxPage } from '@duxweb/dvha-pro'
import { NButton, NEmpty, NSpin, useMessage } from 'naive-ui'
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'

const request = useCustomMutation()
const message = useMessage()
const router = useRouter()
const manage = useManage()

const agents = ref<any[]>([])
const flows = ref<any[]>([])
const sessions = ref<any[]>([])
const loading = ref(false)

const parseList = (res: any) => (Array.isArray(res?.data) ? res.data : (res?.data?.data || []))

async function fetchData() {
  loading.value = true
  try {
    const [agentRes, flowRes, sessionRes] = await Promise.allSettled([
      request.mutateAsync({ path: 'ai/agent', method: 'GET', query: { limit: 100 } }),
      request.mutateAsync({ path: 'ai/flow', method: 'GET', query: { limit: 100 } }),
      request.mutateAsync({ path: 'ai/message/v1/sessions', method: 'GET', query: { limit: 10 } }),
    ])

    if (agentRes.status === 'fulfilled') {
      agents.value = parseList(agentRes.value)
    }
    if (flowRes.status === 'fulfilled') {
      flows.value = parseList(flowRes.value)
    }
    if (sessionRes.status === 'fulfilled') {
      sessions.value = parseList(sessionRes.value)
    }

    // sessions 接口可能返回 { error: { message } }，这里不弹错误提示，只展示空列表
    if (agentRes.status === 'rejected' || flowRes.status === 'rejected') {
      const err = agentRes.status === 'rejected'
        ? agentRes.reason
        : flowRes.status === 'rejected'
          ? flowRes.reason
          : null
      message.error(err?.error?.message || err?.message || '加载数据失败')
    }
  }
  finally {
    loading.value = false
  }
}

function handleChat(row: any, sessionId?: number) {
  const code = row.agent_code || row.code
  if (!code) {
    message.error('无效的智能体标识')
    return
  }
  const path = `/ai/agent/chat/${encodeURIComponent(code)}`
  if (sessionId) {
    router.push({
      path: manage.getRoutePath(path),
      query: { sessionId: String(sessionId) },
    })
  }
  else {
    router.push(manage.getRoutePath(path))
  }
}

function handleExecute(flow: any) {
  if (!flow.id)
    return
  router.push(manage.getRoutePath(`/ai/flow/chat/${flow.id}`))
}

function handleOnboarding() {
  router.push(manage.getRoutePath('/ai/onboarding'))
}

onMounted(() => {
  fetchData()
})
</script>

<template>
  <DuxPage>
    <div class="min-h-screen bg-gradient-to-br from-bg-default via-bg-elevated to-bg-default">
      <!-- Hero Section -->
      <div class="relative overflow-hidden bg-gradient-to-br from-primary/5 via-primary/10 to-transparent border-b border-muted">
        <div class="absolute inset-0 bg-grid-pattern opacity-5" />
        <div class="relative px-6 py-16">
          <div class="max-w-7xl mx-auto">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-8">
              <!-- 左侧标题区 -->
              <div class="flex-1 space-y-6">
                <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-primary/10 border border-primary/20">
                  <i class="i-tabler:sparkles text-primary" />
                  <span class="text-sm font-medium text-primary">AI 智能助手平台</span>
                </div>

                <div>
                  <h1 class="text-4xl md:text-5xl font-bold text-default dark:text-white mb-4 leading-tight">
                    探索 AI 的
                    <span class="text-transparent bg-clip-text bg-gradient-to-r from-primary to-primary/60">无限可能</span>
                  </h1>
                  <p class="text-lg text-muted dark:text-white/80 max-w-2xl">
                    使用智能体和工作流，让 AI 成为你的得力助手。自动化任务，提升效率，释放创造力。
                  </p>
                </div>

                <!-- 快速统计 -->
                <div class="flex items-center gap-8">
                  <div class="flex items-center gap-3">
                    <div class="size-12 rounded-xl bg-blue-500/10 flex items-center justify-center">
                      <i class="i-tabler:robot text-2xl text-blue-500" />
                    </div>
                    <div>
                      <div class="text-2xl font-bold text-default dark:text-white">
                        {{ agents.length }}
                      </div>
                      <div class="text-sm text-muted dark:text-white/70">
                        智能体
                      </div>
                    </div>
                  </div>

                  <div class="flex items-center gap-3">
                    <div class="size-12 rounded-xl bg-purple-500/10 flex items-center justify-center">
                      <i class="i-tabler:bolt text-2xl text-purple-500" />
                    </div>
                    <div>
                      <div class="text-2xl font-bold text-default dark:text-white">
                        {{ flows.length }}
                      </div>
                      <div class="text-sm text-muted dark:text-white/70">
                        工作流
                      </div>
                    </div>
                  </div>

                  <div class="flex items-center gap-3">
                    <div class="size-12 rounded-xl bg-primary/10 flex items-center justify-center">
                      <i class="i-tabler:message-circle text-2xl text-primary" />
                    </div>
                    <div>
                      <div class="text-2xl font-bold text-default dark:text-white">
                        {{ sessions.length }}
                      </div>
                      <div class="text-sm text-muted dark:text-white/70">
                        会话记录
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- 右侧科技感动效 -->
              <div class="hidden md:flex items-center justify-center md:w-80">
                <div class="relative size-52">
                  <!-- 旋转光圈 -->
                  <div class="absolute inset-0 rounded-full border-2 border-primary/30 animate-spin-slow" style="animation-duration: 8s;" />
                  <div class="absolute inset-4 rounded-full border-2 border-blue-500/30 animate-spin-reverse" style="animation-duration: 6s;" />
                  <div class="absolute inset-8 rounded-full border-2 border-purple-500/30 animate-spin-slow" style="animation-duration: 10s;" />

                  <!-- 中心核心 -->
                  <div class="absolute inset-0 flex items-center justify-center">
                    <div class="size-24 rounded-full bg-gradient-to-br from-primary/20 via-blue-500/20 to-purple-500/20 backdrop-blur-sm border border-primary/30 flex items-center justify-center animate-pulse-glow">
                      <i class="i-tabler:cpu text-5xl text-primary/80" />
                    </div>
                  </div>

                  <!-- 环绕粒子 -->
                  <div class="absolute top-0 left-1/2 -translate-x-1/2 -translate-y-1/2 size-3 rounded-full bg-blue-500 animate-orbit-1 shadow-lg shadow-blue-500/50" />
                  <div class="absolute top-1/4 right-0 translate-x-1/2 size-2.5 rounded-full bg-purple-500 animate-orbit-2 shadow-lg shadow-purple-500/50" />
                  <div class="absolute bottom-1/4 left-0 -translate-x-1/2 size-2 rounded-full bg-primary animate-orbit-3 shadow-lg shadow-primary/50" />
                  <div class="absolute bottom-0 right-1/3 translate-y-1/2 size-2.5 rounded-full bg-blue-400 animate-orbit-4 shadow-lg shadow-blue-400/50" />

                  <!-- 扫描线效果 -->
                  <div class="absolute inset-0 rounded-full overflow-hidden">
                    <div class="absolute inset-0 bg-gradient-to-b from-transparent via-primary/10 to-transparent animate-scan" />
                  </div>

                  <!-- 角落装饰 -->
                  <div class="absolute -top-2 -right-2 size-12 rounded-lg border-2 border-t-primary border-r-primary border-b-transparent border-l-transparent rotate-45 animate-pulse-slow" />
                  <div class="absolute -bottom-2 -left-2 size-12 rounded-lg border-2 border-t-transparent border-r-transparent border-b-blue-500 border-l-blue-500 rotate-45 animate-pulse-slow" style="animation-delay: 0.5s;" />
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- 主内容区 -->
      <div v-if="loading" class="flex justify-center py-20">
        <NSpin size="large" />
      </div>

      <div v-else class="max-w-7xl mx-auto px-6 py-12 space-y-16">
        <!-- 快速配置向导入口（置顶，新用户一眼可见） -->
        <section v-if="!agents.length">
          <!-- 新用户：大卡片强引导 -->
          <div
            class="group relative overflow-hidden rounded-3xl p-8 md:p-12 cursor-pointer transition-all duration-500 hover:shadow-2xl hover:shadow-primary/20 border-2 border-primary/30 bg-gradient-to-br from-primary/8 via-primary/4 to-transparent hover:border-primary/60"
            @click="handleOnboarding"
          >
            <div class="absolute top-0 right-0 w-64 h-64 bg-primary/5 rounded-full -translate-y-32 translate-x-32" />
            <div class="absolute bottom-0 left-0 w-96 h-96 bg-primary/5 rounded-full translate-y-48 -translate-x-48" />

            <div class="relative flex flex-col md:flex-row items-center gap-8">
              <div class="size-20 rounded-2xl bg-primary/15 flex items-center justify-center flex-none group-hover:scale-110 transition-transform duration-500">
                <i class="i-tabler:route-2 text-4xl text-primary" />
              </div>

              <div class="flex-1 text-center md:text-left">
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-primary/10 text-sm font-medium text-primary mb-3">
                  <i class="i-tabler:sparkles" />
                  <span>首次使用推荐</span>
                </div>
                <h2 class="text-2xl md:text-3xl font-bold text-default mb-3">
                  快速配置向导
                </h2>
                <p class="text-muted text-lg max-w-xl">
                  只需几步，帮你自动配好服务商、模型和智能体，开箱即用
                </p>
              </div>

              <div class="flex items-center gap-2 text-primary font-medium flex-none">
                <span class="text-lg hidden md:inline">开始配置</span>
                <div class="size-12 rounded-full bg-primary/15 flex items-center justify-center group-hover:bg-primary group-hover:text-white transition-all duration-300">
                  <i class="i-tabler:arrow-right text-xl" />
                </div>
              </div>
            </div>
          </div>
        </section>

        <section v-else>
          <!-- 老用户：轻量横条 -->
          <div
            class="group flex items-center gap-4 rounded-2xl border border-muted hover:border-primary/50 bg-default px-6 py-4 cursor-pointer transition-all duration-300 hover:shadow-lg"
            @click="handleOnboarding"
          >
            <div class="size-10 rounded-xl bg-primary/10 flex items-center justify-center flex-none group-hover:scale-110 transition-transform">
              <i class="i-tabler:route-2 text-lg text-primary" />
            </div>
            <div class="flex-1 min-w-0">
              <span class="font-medium text-default">快速配置向导</span>
              <span class="text-muted text-sm ml-2">一键创建新的服务商、模型和智能体</span>
            </div>
            <div class="flex items-center gap-1.5 text-sm text-primary font-medium flex-none">
              <span class="hidden md:inline">去配置</span>
              <i class="i-tabler:chevron-right" />
            </div>
          </div>
        </section>

        <!-- 最近会话区 -->
        <section>
          <div class="mb-8">
            <h2 class="text-2xl font-bold text-default mb-2">
              最近会话
            </h2>
            <p class="text-muted">
              继续之前的对话，接着上次的话题聊下去
            </p>
          </div>

          <div v-if="sessions.length" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
            <div
              v-for="session in sessions"
              :key="session.id"
              class="group relative bg-default rounded-2xl border border-muted hover:border-primary p-6 cursor-pointer transition-all duration-300 hover:shadow-xl hover:-translate-y-1"
              @click="handleChat(session, session.id)"
            >
              <div class="absolute inset-0 bg-gradient-to-br from-primary/5 to-transparent rounded-2xl transition-opacity" />

              <div class="relative">
                <!-- 顶部：图标 + 标题 + 日期 -->
                <div class="flex items-start gap-4 mb-4">
                  <div class="size-14 rounded-xl bg-gradient-to-br from-primary/20 to-primary/10 flex items-center justify-center flex-none group-hover:scale-110 transition-transform shadow-lg shadow-primary/10">
                    <i class="i-tabler:message-circle text-2xl text-primary" />
                  </div>

                  <div class="flex-1 min-w-0">
                    <h3 class="text-lg font-semibold text-default truncate mb-1">
                      {{ session.title || '新会话' }}
                    </h3>
                    <div class="flex items-center gap-2 text-sm text-muted">
                      <i class="i-tabler:robot" />
                      <span class="truncate">{{ session.agent_name || session.agent_code }}</span>
                    </div>
                  </div>

                  <div class="px-3 py-1 rounded-full bg-primary/10 text-sm font-medium text-primary flex-none">
                    {{ session.last_message_at?.substring(5, 10) || '今天' }}
                  </div>
                </div>

                <!-- 底部：继续对话提示 -->
                <div class="flex items-center justify-end text-sm text-primary font-medium group-hover:gap-2 transition-all">
                  <span>继续对话</span>
                  <i class="i-tabler:arrow-right" />
                </div>
              </div>
            </div>
          </div>

          <div v-else class="bg-default rounded-2xl border border-muted p-16">
            <NEmpty description="暂无会话记录" size="large">
              <template #extra>
                <NButton type="primary" size="large" round @click="() => router.push(manage.getRoutePath('/ai/agent'))">
                  <template #icon>
                    <i class="i-tabler:plus" />
                  </template>
                  开始第一个对话
                </NButton>
              </template>
            </NEmpty>
          </div>
        </section>

        <!-- 智能体卡片区 -->
        <section>
          <div class="mb-8">
            <h2 class="text-2xl font-bold text-default mb-2">
              智能体
            </h2>
            <p class="text-muted">
              选择一个智能体开始对话
            </p>
          </div>

          <div v-if="agents.length" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
            <div
              v-for="agent in agents"
              :key="agent.id"
              class="group relative bg-default rounded-2xl border border-muted hover:border-blue-500 p-6 cursor-pointer transition-all duration-300 hover:shadow-xl hover:-translate-y-1"
              @click="handleChat(agent)"
            >
              <div class="absolute inset-0 bg-gradient-to-br from-blue-500/5 to-transparent rounded-2xl transition-opacity" />

              <div class="relative">
                <!-- 顶部：图标 + 标题 + 模型标签 -->
                <div class="flex items-start gap-4 mb-4">
                  <div class="size-14 rounded-xl bg-gradient-to-br from-blue-500 to-blue-500/80 flex items-center justify-center flex-none shadow-lg shadow-blue-500/20 group-hover:scale-110 transition-transform">
                    <i class="i-tabler:robot text-2xl text-white" />
                  </div>

                  <div class="flex-1 min-w-0">
                    <h3 class="text-lg font-semibold text-default truncate mb-1">
                      {{ agent.name }}
                    </h3>
                    <div class="flex items-center gap-2 text-sm text-muted font-mono">
                      <i class="i-tabler:code" />
                      <span class="truncate">{{ agent.code }}</span>
                    </div>
                  </div>
                </div>

                <!-- 描述 -->
                <p class="text-sm text-muted line-clamp-2 mb-2 min-h-[40px]">
                  {{ agent.description || '智能助手，随时为你服务' }}
                </p>

                <!-- 底部：开始对话提示 -->
                <div class="flex items-center justify-end text-sm text-blue-600 font-medium group-hover:gap-2 transition-all">
                  <span>开始对话</span>
                  <i class="i-tabler:arrow-right" />
                </div>
              </div>
            </div>
          </div>

          <div v-else class="bg-default rounded-2xl border border-muted p-16">
            <NEmpty description="没有找到匹配的智能体" size="large" />
          </div>
        </section>

        <!-- 工作流区 -->
        <section>
          <div class="mb-8">
            <h2 class="text-2xl font-bold text-default mb-2">
              工作流
            </h2>
            <p class="text-muted">
              自动化你的任务流程，一键执行复杂操作
            </p>
          </div>

          <div v-if="flows.length" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
            <div
              v-for="flow in flows"
              :key="flow.id"
              class="group relative bg-default rounded-2xl border border-muted hover:border-purple-500 p-6 cursor-pointer transition-all duration-300 hover:shadow-xl hover:-translate-y-1"
              @click="handleExecute(flow)"
            >
              <div class="absolute inset-0 bg-gradient-to-br from-purple-500/5 to-transparent rounded-2xl transition-opacity" />

              <div class="relative">
                <!-- 顶部：图标 + 标题 + 运行标签 -->
                <div class="flex items-start gap-4 mb-4">
                  <div class="size-14 rounded-xl bg-gradient-to-br from-purple-500 to-purple-500/80 flex items-center justify-center flex-none shadow-lg shadow-purple-500/20 group-hover:scale-110 transition-transform">
                    <i class="i-tabler:bolt text-2xl text-white" />
                  </div>

                  <div class="flex-1 min-w-0">
                    <h3 class="text-lg font-semibold text-default truncate mb-1">
                      {{ flow.name }}
                    </h3>
                    <div class="flex items-center gap-2 text-sm text-muted font-mono">
                      <i class="i-tabler:workflow" />
                      <span class="truncate">工作流自动化</span>
                    </div>
                  </div>
                </div>

                <!-- 描述 -->
                <p class="text-sm text-muted line-clamp-2 mb-2 min-h-[40px]">
                  {{ flow.description || '点击运行该工作流' }}
                </p>

                <!-- 底部：立即执行提示 -->
                <div class="flex items-center justify-end text-sm text-purple-600 font-medium group-hover:gap-2 transition-all">
                  <span>立即执行</span>
                  <i class="i-tabler:arrow-right" />
                </div>
              </div>
            </div>
          </div>

          <div v-else class="bg-default rounded-2xl border border-muted p-16">
            <NEmpty description="暂无工作流" size="large" />
          </div>
        </section>
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

/* 科技感动画 */
@keyframes spin-slow {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

@keyframes spin-reverse {
  from { transform: rotate(360deg); }
  to { transform: rotate(0deg); }
}

@keyframes pulse-glow {
  0%, 100% {
    box-shadow: 0 0 20px rgba(var(--primary-color), 0.3);
    transform: scale(1);
  }
  50% {
    box-shadow: 0 0 40px rgba(var(--primary-color), 0.6);
    transform: scale(1.05);
  }
}

@keyframes pulse-slow {
  0%, 100% { opacity: 0.4; }
  50% { opacity: 1; }
}

@keyframes scan {
  0% { transform: translateY(-100%); }
  100% { transform: translateY(100%); }
}

@keyframes orbit-1 {
  0% { transform: translate(-50%, -50%) rotate(0deg) translateX(100px) rotate(0deg); }
  100% { transform: translate(-50%, -50%) rotate(360deg) translateX(100px) rotate(-360deg); }
}

@keyframes orbit-2 {
  0% { transform: translate(50%, 0%) rotate(90deg) translateX(100px) rotate(-90deg); }
  100% { transform: translate(50%, 0%) rotate(450deg) translateX(100px) rotate(-450deg); }
}

@keyframes orbit-3 {
  0% { transform: translate(-50%, 0%) rotate(180deg) translateX(100px) rotate(-180deg); }
  100% { transform: translate(-50%, 0%) rotate(540deg) translateX(100px) rotate(-540deg); }
}

@keyframes orbit-4 {
  0% { transform: translate(0%, 50%) rotate(270deg) translateX(100px) rotate(-270deg); }
  100% { transform: translate(0%, 50%) rotate(630deg) translateX(100px) rotate(-630deg); }
}

.animate-spin-slow { animation: spin-slow 8s linear infinite; }
.animate-spin-reverse { animation: spin-reverse 6s linear infinite; }
.animate-pulse-glow { animation: pulse-glow 2s ease-in-out infinite; }
.animate-pulse-slow { animation: pulse-slow 3s ease-in-out infinite; }
.animate-scan { animation: scan 4s linear infinite; }
.animate-orbit-1 { animation: orbit-1 8s linear infinite; }
.animate-orbit-2 { animation: orbit-2 10s linear infinite; }
.animate-orbit-3 { animation: orbit-3 12s linear infinite; }
.animate-orbit-4 { animation: orbit-4 14s linear infinite; }
</style>
