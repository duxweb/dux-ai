<script setup lang="ts">
import { useCustomMutation } from '@duxweb/dvha-core'
import { DuxPage } from '@duxweb/dvha-pro'
import { DuxSelect } from '@duxweb/dvha-naiveui'
import { NCard, NButton, NEmpty, NInput, NInputNumber, NSpin, useMessage } from 'naive-ui'
import { marked } from 'marked'
import { ref } from 'vue'

const knowledgeId = ref<number | null>(null)
const keyword = ref('')
const limit = ref(5)
const results = ref<Array<{ title?: string; content?: string; type?: string }>>([])
const expanded = ref<Set<number>>(new Set())
const message = useMessage()
const { mutateAsync, isLoading } = useCustomMutation()

const handleSearch = async () => {
  if (!knowledgeId.value) {
    message.error('请选择文档库')
    return
  }

  if (!keyword.value.trim()) {
    message.error('请输入关键词')
    return
  }

  try {
    const response = await mutateAsync({
      path: `ai/ragKnowledge/${knowledgeId.value}/query`,
      method: 'GET',
      query: {
        keyword: keyword.value.trim(),
        limit: limit.value,
      },
    })
    const payload = response?.data || {}
    results.value = Array.isArray(payload.items) ? payload.items : []
    expanded.value = new Set()
    if (!results.value.length) {
      message.warning('未检索到相关内容')
    }
    else {
      message.success('查询完成')
    }
  }
  catch (error) {
    message.error((error as Error)?.message || '查询失败')
  }
}

const toggleExpand = (index: number) => {
  const set = new Set(expanded.value)
  if (set.has(index))
    set.delete(index)
  else
    set.add(index)
  expanded.value = set
}

const isExpanded = (index: number) => expanded.value.has(index)
</script>

<template>
  <DuxPage :scrollbar="false">
    <div class="flex flex-col lg:flex-row h-full">
      <div class="lg:w-[350px]">
        <NCard title="检索参数" :bordered="false" size="small" class="lg:h-full">
          <div class="space-y-4 flex-1">
            <div class="space-y-2">
              <div class="text-sm font-medium">选择文档库</div>
              <DuxSelect
                v-model:value="knowledgeId"
                path="ai/ragKnowledge"
                label-field="name"
                value-field="id"
                placeholder="请选择文档库"
              />
            </div>
            <div>
              <div class="text-sm text-muted mb-1">返回条数</div>
              <NInputNumber v-model:value="limit" :min="1" :max="20" placeholder="TopK" />
            </div>
            <div class="space-y-2 flex-1">
              <div class="text-sm font-medium">检索内容</div>
              <NInput
                type="textarea"
                v-model:value="keyword"
                placeholder="请输入要检索的关键词"
                @keyup.enter="handleSearch"
              />
            </div>
          </div>
          <div class="pt-4 flex justify-end">
            <NButton type="primary" :loading="isLoading" @click="handleSearch">开始检索</NButton>
          </div>
        </NCard>
      </div>

      <div class="flex-1 h-full">
        <NCard title="检索结果" :bordered="false" size="small" class="lg:h-full" content-class="min-h-0">
          <div v-if="!results.length" class="h-full flex-1 py-24 flex items-center justify-center">
            <NEmpty description="暂未查询到相关内容" />
          </div>
          <div v-else class="space-y-4 h-full overflow-y-auto">
            <div
              v-for="(item, index) in results"
              :key="index"
              class="rounded-lg border border-muted/60 p-4 space-y-2"
            >
              <div class="text-sm uppercase tracking-wider text-muted">{{ item.type || '文本' }}</div>
              <div class="text-base font-semibold">{{ item.title || '-' }}</div>
              <div
                class="text-sm max-w-none transition-all bg-muted p-4"
                :class="isExpanded(index) ? '' : 'max-h-48 overflow-hidden'"
                v-html="marked(item.content ?? '')"
              />
              <div class="text-right">
                <NButton size="tiny" text @click="toggleExpand(index)">
                  {{ isExpanded(index) ? '收起内容' : '展开更多' }}
                </NButton>
              </div>
            </div>
          </div>
        </NCard>
      </div>
    </div>
  </DuxPage>
</template>

<style scoped></style>
