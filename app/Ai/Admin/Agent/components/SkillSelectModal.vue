<script setup lang="ts">
import type { TableColumn } from '@duxweb/dvha-naiveui'
import { DuxModalPage, DuxTable, useTableColumn } from '@duxweb/dvha-pro'
import { NButton, NCheckbox, NTag } from 'naive-ui'
import { h, ref } from 'vue'

const props = defineProps<{
  selectedCodes?: string[]
  onClose: () => void
  onConfirm: (codes: string[]) => void
}>()

const column = useTableColumn()
const selectedCodes = ref<string[]>(Array.isArray(props.selectedCodes) ? [...props.selectedCodes] : [])

function isSelected(code: string): boolean {
  return selectedCodes.value.includes(code)
}

function toggleSelect(code: string): void {
  if (!code)
    return
  if (isSelected(code)) {
    selectedCodes.value = selectedCodes.value.filter(item => item !== code)
    return
  }
  selectedCodes.value = [...selectedCodes.value, code]
}

function clearSelected(): void {
  selectedCodes.value = []
}

const columns: TableColumn[] = [
  {
    title: '',
    key: 'select',
    width: 54,
    render: (row: any) => {
      const code = String(row?.value || row?.name || '')
      return h(NCheckbox, {
        checked: isSelected(code),
        onClick: (e: MouseEvent) => e.stopPropagation(),
        'onUpdate:checked': () => toggleSelect(code),
      })
    },
  },
  {
    title: '技能',
    key: 'label',
    minWidth: 320,
    render: column.renderMedia({
      title: 'label',
      desc: 'value',
    }),
  },
  {
    title: '兼容',
    key: 'compatibility_name',
    width: 120,
    render: (row: any) => h(NTag, {
      type: row?.compatibility === 'full' ? 'success' : row?.compatibility === 'partial' ? 'warning' : 'default',
      round: true,
      bordered: false,
    }, { default: () => row?.compatibility_name || '未知' }),
  },
  {
    title: '来源',
    key: 'source_type_name',
    width: 120,
  },
]

function handleClose(): void {
  props.onClose()
}

function handleConfirm(): void {
  props.onConfirm(selectedCodes.value)
}

function rowProps(row: any) {
  const code = String(row?.value || row?.name || '')
  return {
    style: 'cursor:pointer',
    onClick: () => toggleSelect(code),
  }
}
</script>

<template>
  <DuxModalPage @close="handleClose">
    <div class="space-y-3">
      <div class="flex items-center justify-between">
        <div class="text-sm text-muted">
          已选择 {{ selectedCodes.length }}
        </div>
        <NButton tertiary @click="clearSelected">
          清空选择
        </NButton>
      </div>
      <div class="border border-muted rounded-lg">
        <DuxTable
          path="ai/skill/options"
          :columns="columns"
          :pagination="false"
          :row-props="rowProps"
        />
      </div>
    </div>
    <template #footer>
      <NButton @click="handleClose">
        取消
      </NButton>
      <NButton type="primary" @click="handleConfirm">
        确认选择
      </NButton>
    </template>
  </DuxModalPage>
</template>
