<script setup lang="ts">
import { DuxSelect } from '@duxweb/dvha-naiveui'
import { DuxDrawerTabForm, DuxFormItem, DuxFormLayout } from '@duxweb/dvha-pro'
import { NButton, NInput, NInputNumber, NSelect, NSwitch, NTabPane } from 'naive-ui'
import { ref } from 'vue'

const props = defineProps<{
  id?: number | string
}>()

const model = ref<Record<string, any>>({
  config_id: undefined,
  name: undefined,
  description: undefined,
  status: true,
  settings: {
    parse_provider: undefined,
    ingest: {
      chunk_size: 2200,
      separator: '\n',
      word_overlap: 0,
    },
    debug_log: false,
    sheet: {
      header_rows: 1,
      start_row: 2,
    },
  },
})

const presets = [
  { label: '默认平衡', chunk_size: 2200, separator: '\n', word_overlap: 0 },
  { label: '检索更精细', chunk_size: 1400, separator: '\n', word_overlap: 2 },
  { label: '长文档省成本', chunk_size: 3200, separator: '\n', word_overlap: 0 },
]

const separatorOptions = [
  { label: '按换行分段（推荐）', value: '\n' },
  { label: '按空格分段', value: ' ' },
  { label: '按句号分段', value: '。' },
]

function applyPreset(preset: { chunk_size: number, separator: string, word_overlap: number }) {
  model.value.settings ||= {}
  model.value.settings.ingest ||= {}
  model.value.settings.ingest.chunk_size = preset.chunk_size
  model.value.settings.ingest.separator = preset.separator
  model.value.settings.ingest.word_overlap = preset.word_overlap
}
</script>

<template>
  <DuxDrawerTabForm
    :id="props.id"
    path="ai/ragKnowledge"
    :data="model"
    default-tab="base"
    invalidate="ai/ragKnowledge"
  >
    <NTabPane name="base" label="基本信息">
      <DuxFormLayout label-placement="top" class="pb-4">
        <DuxFormItem label="知识库引擎" required tooltip="创建后不建议更换（如需更换建议新建知识库并重建索引）">
          <DuxSelect
            v-model:value="model.config_id"
            path="ai/ragProvider/options"
            label-field="label"
            value-field="value"
            placeholder="请选择驱动"
            :disabled="Boolean(props.id)"
          />
        </DuxFormItem>
        <DuxFormItem label="文档库名称" required>
          <NInput v-model:value="model.name" placeholder="请输入文档库名称" />
        </DuxFormItem>
        <DuxFormItem label="说明">
          <NInput v-model:value="model.description" type="textarea" placeholder="用途或备注" :rows="3" />
        </DuxFormItem>
        <DuxFormItem label="启用状态">
          <NSwitch v-model:value="model.status" />
        </DuxFormItem>
      </DuxFormLayout>
    </NTabPane>

    <NTabPane name="settings" label="入库参数">
      <DuxFormLayout label-placement="top" class="pb-4">
        <DuxFormItem label="解析配置" tooltip="用于解析 PDF/图片 等非纯文本（留空仅支持本地文本/markdown/csv/docx/xlsx）">
          <DuxSelect
            v-model:value="model.settings.parse_provider"
            path="ai/parseProvider"
            label-field="name"
            value-field="id"
            placeholder="选择解析配置"
            clearable
          />
        </DuxFormItem>

        <DuxFormItem label="调试日志" tooltip="开启后记录知识库向量入库/检索的调试日志（ai.rag）">
          <NSwitch v-model:value="model.settings.debug_log" />
        </DuxFormItem>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
          <DuxFormItem label="分段大小 chunk_size" tooltip="默认 2200，越大越省 token 但命中精度可能下降">
            <NInputNumber v-model:value="model.settings.ingest.chunk_size" :min="200" />
          </DuxFormItem>
          <DuxFormItem label="分段分隔符 separator" tooltip="交给 Neuron 内置 DelimiterTextSplitter">
            <NSelect
              v-model:value="model.settings.ingest.separator"
              :options="separatorOptions"
            />
          </DuxFormItem>
          <DuxFormItem label="重叠单元 word_overlap" tooltip="默认 0，建议 0~5，避免重复膨胀">
            <NInputNumber v-model:value="model.settings.ingest.word_overlap" :min="0" />
          </DuxFormItem>
        </div>

        <DuxFormItem label="推荐配置" tooltip="一键填充分段参数（可再手动微调）">
          <div class="flex flex-wrap gap-2">
            <NButton
              v-for="preset in presets"
              :key="preset.label"
              size="small"
              tertiary
              @click="applyPreset(preset)"
            >
              {{ preset.label }}
            </NButton>
          </div>
        </DuxFormItem>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
          <DuxFormItem label="表格 header 行数" tooltip="默认 1">
            <NInputNumber v-model:value="model.settings.sheet.header_rows" :min="0" :max="50" />
          </DuxFormItem>
          <DuxFormItem label="表格起始行 start_row" tooltip="默认 header_rows+1">
            <NInputNumber v-model:value="model.settings.sheet.start_row" :min="1" :max="1000" />
          </DuxFormItem>
        </div>
      </DuxFormLayout>
    </NTabPane>
  </DuxDrawerTabForm>
</template>

<style scoped></style>
