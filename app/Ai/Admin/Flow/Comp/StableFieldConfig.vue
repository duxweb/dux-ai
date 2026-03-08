<script setup>
import { NButton, NInput, NSelect, NSwitch } from 'naive-ui'
import { computed, ref, watch, watchEffect } from 'vue'

const props = defineProps({
  modelValue: { type: Object, default: null },
  modeOptions: { type: Array, default: () => [] },
  fieldTypeOptions: { type: Array, default: () => [] },
  textPlaceholder: { type: String, default: '' },
  jsonDescription: { type: String, default: '' },
  showLabelInput: { type: Boolean, default: false },
  labelPlaceholder: { type: String, default: '' },
  showRequiredSwitch: { type: Boolean, default: false },
  requiredLabel: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue'])

function createKey() {
  return `k_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 10)}`
}

function defaultItem(index = 0) {
  return {
    name: index === 0 ? 'content' : `content_${index + 1}`,
    type: 'text',
    content: '',
    description: '',
    label: '',
    required: false,
    __key: createKey(),
  }
}

function defaultValue() {
  return {
    mode: 'json',
    items: [defaultItem(0)],
  }
}

const inner = ref(props.modelValue ? { ...props.modelValue } : defaultValue())

watch(
  () => props.modelValue,
  (val) => {
    inner.value = val ? { ...val } : defaultValue()
  },
  { deep: true },
)

function normalizeItems(items) {
  return items.map((it) => ({
    ...it,
    name: String(it?.name ?? ''),
    type: String(it?.type ?? 'text') || 'text',
    __key: it?.__key || createKey(),
  }))
}

function update(next) {
  const normalized = { ...(next || {}) }
  normalized.mode = 'json'
  if (!Array.isArray(normalized.items) || normalized.items.length === 0) {
    normalized.items = [defaultItem(0)]
  } else {
    normalized.items = normalizeItems(normalized.items)
  }
  inner.value = normalized
  emit('update:modelValue', normalized)
}

watchEffect(() => {
  const current = inner.value || {}
  const items = Array.isArray(current.items) ? normalizeItems(current.items) : []
  if (items.length === 0 || current.mode !== 'json' || items.some(it => !it.__key)) {
    update({ ...current, mode: 'json', items: items.length ? items : [defaultItem(0)] })
  }
})

const fieldTypeOptions = computed(() => Array.isArray(props.fieldTypeOptions) ? props.fieldTypeOptions : [])
const items = computed(() => Array.isArray(inner.value?.items) ? normalizeItems(inner.value.items) : [])

function setItems(nextItems) {
  const current = inner.value || {}
  update({ ...current, items: nextItems, mode: 'json' })
}

function updateItem(index, key, value) {
  const next = items.value.map((it, i) => (i === index ? { ...it, [key]: value } : it))
  setItems(next)
}

function addItem() {
  setItems([...items.value, defaultItem(items.value.length)])
}

function removeItem(index) {
  if (items.value.length <= 1) {
    setItems([defaultItem(0)])
    return
  }
  setItems(items.value.filter((_, i) => i !== index))
}
</script>

<template>
  <div class="space-y-3">
    <div
      v-for="(item, idx) in items"
      :key="item.__key || idx"
      class="rounded-md border border-default bg-default p-3 space-y-2"
    >
      <div class="flex flex-col gap-2">
        <div class="flex items-center gap-2">
          <NInput
            class="flex-1"
            :value="item.name"
            placeholder="字段名称"
            @update:value="val => updateItem(idx, 'name', val || '')"
          />
          <NSelect
            class="flex-1"
            :value="item.type"
            :options="fieldTypeOptions"
            placeholder="字段类型"
            @update:value="val => updateItem(idx, 'type', val || 'text')"
          />
          <NButton
            circle
            tertiary
            size="small"
            type="error"
            :disabled="items.length <= 1"
            @click="removeItem(idx)"
          >
            <div class="i-tabler:x size-4" />
          </NButton>
        </div>

        <NInput
          v-if="showLabelInput"
          :value="item.label || ''"
          :placeholder="labelPlaceholder || '字段展示名称（默认同字段名）'"
          @update:value="val => updateItem(idx, 'label', val || '')"
        />
      </div>

      <NInput
        :value="item.content || ''"
        type="textarea"
        :autosize="{ minRows: 2, maxRows: 6 }"
        :placeholder="textPlaceholder || ''"
        @update:value="val => updateItem(idx, 'content', val || '')"
      />

      <NInput
        :value="item.description || ''"
        placeholder="备注说明"
        @update:value="val => updateItem(idx, 'description', val || '')"
      />

      <div v-if="showRequiredSwitch" class="flex items-center justify-between text-sm text-muted">
        <span>{{ requiredLabel || '是否必填' }}</span>
        <NSwitch
          size="small"
          :value="!!item.required"
          @update:value="val => updateItem(idx, 'required', !!val)"
        />
      </div>
    </div>

    <NButton dashed block type="primary" @click="addItem">
      新增字段
    </NButton>
    <div v-if="jsonDescription" class="text-xs text-muted">
      {{ jsonDescription }}
    </div>
  </div>
</template>

