<script setup lang="ts">
import { DuxSelect } from '@duxweb/dvha-naiveui'
import { DuxFormItem, DuxFormLayout, DuxPanelCard, DuxSettingForm } from '@duxweb/dvha-pro'
import { NButton, NInput, NInputNumber, NTabPane } from 'naive-ui'
import { ref } from 'vue'

const model = ref<Record<string, any>>({
  default_chat_model_id: undefined,
  default_embedding_model_id: undefined,
  default_image_model_id: undefined,
  default_video_model_id: undefined,
  default_parse_provider_id: undefined,
  default_rag_provider_id: undefined,
  rate_limit: {
    tpm: undefined,
    concurrency: undefined,
    max_wait_ms: 8000,
  },
  editor: {
    timeout: 60,
    system_prompt: '你是 AIEditor 的写作助手。直接返回正文内容，不要解释，不要添加前后缀，不要输出 ``` 代码块。',
  },
})
</script>

<template>
  <DuxSettingForm v-slot="result" :data="model" default-tab="model" path="ai/setting" action="edit" tabs>
    <NTabPane name="model" tab="模型设置" display-directive="show" class="flex flex-col gap-4">
      <DuxPanelCard title="默认模型" description="统一管理 AI 模块的默认模型绑定">
        <template #actions>
          <NButton secondary type="primary" @click="() => result.onSubmit()">
            保存设置
          </NButton>
        </template>
        <DuxFormLayout class="px-4" divider label-placement="setting">
          <DuxFormItem label="默认大语言模型" description="AI 模块默认聊天、文本生成和增强 AI 编辑器都会优先使用这个模型" path="default_chat_model_id">
            <DuxSelect
              v-model:value="model.default_chat_model_id"
              path="ai/model"
              :params="{ tab: 'chat' }"
              label-field="name"
              value-field="id"
              clearable
              placeholder="请选择默认对话模型"
            />
          </DuxFormItem>
          <DuxFormItem label="默认图片模型" description="文章封面图生成等图片能力未单独指定模型时自动使用" path="default_image_model_id">
            <DuxSelect
              v-model:value="model.default_image_model_id"
              path="ai/model"
              :params="{ tab: 'image' }"
              label-field="name"
              value-field="id"
              clearable
              placeholder="请选择默认图片模型"
            />
          </DuxFormItem>
          <DuxFormItem label="默认视频模型" description="视频生成能力未单独指定模型时可作为统一默认预设" path="default_video_model_id">
            <DuxSelect
              v-model:value="model.default_video_model_id"
              path="ai/model"
              :params="{ tab: 'video' }"
              label-field="name"
              value-field="id"
              clearable
              placeholder="请选择默认视频模型"
            />
          </DuxFormItem>
          <DuxFormItem label="默认 Embeddings 模型" description="知识库引擎未单独选择 Embeddings 模型时自动使用" path="default_embedding_model_id">
            <DuxSelect
              v-model:value="model.default_embedding_model_id"
              path="ai/model"
              :params="{ tab: 'embedding' }"
              label-field="name"
              value-field="id"
              clearable
              placeholder="请选择默认 Embeddings 模型"
            />
          </DuxFormItem>
        </DuxFormLayout>
      </DuxPanelCard>

      <DuxPanelCard title="默认引擎" description="常用解析与知识库引擎的默认预设">
        <DuxFormLayout class="px-4" divider label-placement="setting">
          <DuxFormItem label="默认解析配置" description="知识库或附件解析未单独指定时自动使用" path="default_parse_provider_id">
            <DuxSelect
              v-model:value="model.default_parse_provider_id"
              path="ai/parseProvider"
              label-field="name"
              value-field="id"
              clearable
              placeholder="请选择默认解析配置"
            />
          </DuxFormItem>
          <DuxFormItem label="默认知识库引擎" description="知识库未单独选择引擎时自动使用" path="default_rag_provider_id">
            <DuxSelect
              v-model:value="model.default_rag_provider_id"
              path="ai/ragProvider/options"
              label-field="label"
              value-field="value"
              clearable
              placeholder="请选择默认知识库引擎"
            />
          </DuxFormItem>
        </DuxFormLayout>
      </DuxPanelCard>
    </NTabPane>

    <NTabPane name="rate-limit" tab="限速设置" display-directive="show" class="flex flex-col gap-4">
      <DuxPanelCard title="全局限速" description="作为模型级 TPM 配置的兜底值，适合统一控制火山等平台的突发请求">
        <template #actions>
          <NButton secondary type="primary" @click="() => result.onSubmit()">
            保存设置
          </NButton>
        </template>
        <DuxFormLayout class="px-4" divider label-placement="setting">
          <DuxFormItem label="全局 TPM" description="每分钟允许的总 Token 数。留空或 0 表示仅使用模型自身配置" path="rate_limit.tpm">
            <NInputNumber v-model:value="model.rate_limit.tpm" :min="0" :step="1000" placeholder="例如 3000" />
          </DuxFormItem>
          <DuxFormItem label="全局并发数" description="同时允许多少个模型请求在途执行。留空或 0 表示仅使用模型自身配置" path="rate_limit.concurrency">
            <NInputNumber v-model:value="model.rate_limit.concurrency" :min="0" :step="1" placeholder="例如 2" />
          </DuxFormItem>
          <DuxFormItem label="最大等待时间" description="命中限速后最多等待多久，超时后会强制放行当前请求" path="rate_limit.max_wait_ms">
            <NInputNumber v-model:value="model.rate_limit.max_wait_ms" :min="0" :step="500" placeholder="例如 8000" />
          </DuxFormItem>
        </DuxFormLayout>
      </DuxPanelCard>
    </NTabPane>

    <NTabPane name="editor" tab="编辑器设置" display-directive="show" class="flex flex-col gap-4">
      <DuxPanelCard title="AI 编辑器" description="增强 AI 编辑器的默认行为">
        <template #actions>
          <NButton secondary type="primary" @click="() => result.onSubmit()">
            保存设置
          </NButton>
        </template>
        <DuxFormLayout class="px-4" divider label-placement="setting">
          <DuxFormItem label="请求超时（秒）" description="仅作用于编辑器 AI 请求，避免长文扩写时过早超时" path="editor.timeout">
            <NInputNumber v-model:value="model.editor.timeout" :min="10" :max="600" />
          </DuxFormItem>
          <DuxFormItem label="系统提示词" description="编辑器助手的全局系统提示，可按你的编辑场景统一调整" path="editor.system_prompt">
            <NInput
              v-model:value="model.editor.system_prompt"
              type="textarea"
              :rows="5"
              placeholder="请输入编辑器系统提示词"
            />
          </DuxFormItem>
        </DuxFormLayout>
      </DuxPanelCard>
    </NTabPane>
  </DuxSettingForm>
</template>
