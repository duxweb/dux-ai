<script setup>
import { DuxDynamicData } from "@duxweb/dvha-pro"
import { NButton, NCard, NInput, NModal, NSelect, NSwitch } from "naive-ui"
import { computed, ref } from "vue"
import FieldListPreview from "./FieldListPreview.vue"

const props = defineProps({
  modelValue: { type: Object, default: null },
  fieldTypeOptions: { type: Array, default: () => [] },
  textPlaceholder: { type: String, default: "" },
  jsonDescription: { type: String, default: "" },
  showLabelInput: { type: Boolean, default: false },
  labelPlaceholder: { type: String, default: "" },
  showRequiredSwitch: { type: Boolean, default: false },
  requiredLabel: { type: String, default: "" },
})

const emit = defineEmits(["update:modelValue"])
const showEditor = ref(false)
const editorRows = ref([])

function normalizeItems(list) {
  const source = Array.isArray(list) ? list : []
  return source.map((item, index) => ({
    name: String(item?.name || ""),
    type: String(item?.type || "text") || "text",
    content: String(item?.content || ""),
    description: String(item?.description || ""),
    label: String(item?.label || ""),
    required: Boolean(item?.required),
    __key: item?.__key || `k_${Date.now().toString(36)}_${index}`,
  }))
}

function createDefaultItem(index = 0) {
  return {
    name: index === 0 ? "input" : `field_${index + 1}`,
    type: "text",
    content: "",
    description: "",
    label: "",
    required: false,
    __key: `k_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 8)}`,
  }
}

function emitRows(rows) {
  const nextItems = normalizeItems(rows)
  emit("update:modelValue", {
    mode: "json",
    items: nextItems.length > 0 ? nextItems : [createDefaultItem(0)],
  })
}

const items = computed(() => normalizeItems(props.modelValue?.items))

const fieldTypeOptions = computed(() => {
  return Array.isArray(props.fieldTypeOptions) ? props.fieldTypeOptions : []
})

const columns = computed(() => ([
  {
    key: "name",
    title: "字段名",
    width: 150,
    schema: {
      tag: NInput,
      attrs: {
        "v-model:value": "row.name",
        placeholder: "字段名",
      },
    },
  },
  {
    key: "type",
    title: "字段类型",
    width: 140,
    schema: {
      tag: NSelect,
      attrs: {
        "v-model:value": "row.type",
        options: fieldTypeOptions.value,
        placeholder: "字段类型",
      },
    },
  },
  {
    key: "label",
    title: "字段名称",
    width: 150,
    schema: {
      tag: NInput,
      attrs: {
        "v-model:value": "row.label",
        placeholder: "展示名称",
      },
    },
  },
  {
    key: "required",
    title: "必填",
    width: 80,
    schema: {
      tag: NSwitch,
      attrs: {
        "v-model:value": "row.required",
        size: "small",
      },
    },
  },
  {
    key: "content",
    title: "默认值",
    width: 150,
    schema: {
      tag: NInput,
      attrs: {
        "v-model:value": "row.content",
        placeholder: props.textPlaceholder || "默认值",
      },
    },
  },
]))

const typeLabelMap = {
  text: "文本",
  textarea: "文本框",
  number: "数字",
  boolean: "布尔",
  date: "日期",
  json: "JSON",
  image: "图片",
  images: "多图片",
  file: "文件",
  files: "多文件",
}

function resolveTypeLabel(type) {
  const key = String(type || "").trim()
  return typeLabelMap[key] || key || "-"
}

const previewRows = computed(() => {
  return items.value.map(item => ({
    name: item?.name || "-",
    type: resolveTypeLabel(item?.type),
    typeKey: String(item?.type || "").trim().toLowerCase(),
    required: Boolean(item?.required),
  }))
})

function handleCreate() {
  editorRows.value = [...editorRows.value, createDefaultItem(editorRows.value.length)]
}

function openEditor() {
  const list = normalizeItems(props.modelValue?.items)
  editorRows.value = list.length > 0 ? list : [createDefaultItem(0)]
  showEditor.value = true
}

function closeEditor() {
  showEditor.value = false
}

function handleSave() {
  emitRows(editorRows.value)
  showEditor.value = false
}
</script>

<template>
  <NCard size="small" class="border border-muted/20" :segmented="{ content: true }">
    <template #header>
      <div class="flex items-center justify-between gap-2">
        <div class="text-sm font-medium">
          字段配置
        </div>
        <NButton size="small" type="primary" secondary @click="openEditor">
          配置
        </NButton>
      </div>
    </template>
    <FieldListPreview :rows="previewRows" empty-text="未配置输入字段" />
  </NCard>

  <NModal v-model:show="showEditor" :mask-closable="false">
    <NCard style="width: min(860px, calc(100vw - 32px));" title="配置输入字段" :bordered="false" size="small">
      <template #header-extra>
        <NButton quaternary circle size="small" @click="closeEditor">
          <template #icon>
            <i class="i-tabler:x size-4" />
          </template>
        </NButton>
      </template>
      <DuxDynamicData
        v-model:value="editorRows"
        :columns="columns"
        @create="handleCreate"
      />
      <div v-if="jsonDescription" class="text-xs text-muted mt-2">
        {{ jsonDescription }}
      </div>
      <template #footer>
        <div class="flex justify-end">
          <NButton type="primary" @click="handleSave">
            完成
          </NButton>
        </div>
      </template>
    </NCard>
  </NModal>
</template>
