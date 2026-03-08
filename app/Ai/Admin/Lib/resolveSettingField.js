import { DuxSelect } from '@duxweb/dvha-naiveui'
import { NDynamicInput, NInput, NInputNumber, NSelect, NSwitch } from 'naive-ui'

export function createKVItem() {
  return { name: '', value: '' }
}

export function resolveSettingField(field, options = {}) {
  const type = field?.component || 'text'
  const createItem = options.createKVItem || createKVItem

  const map = {
    'dux-select': {
      component: DuxSelect,
      props: {
        ...(field?.componentProps || {}),
        placeholder: field?.placeholder,
      },
    },
    'select': {
      component: NSelect,
      props: {
        options: field?.options || [],
        placeholder: field?.placeholder,
      },
    },
    'number': {
      component: NInputNumber,
      props: {
        min: field?.min,
        max: field?.max,
        step: field?.step,
        placeholder: field?.placeholder,
        ...(field?.componentProps || {}),
      },
    },
    'switch': { component: NSwitch, props: {} },
    'kv-input': { component: NDynamicInput, props: { onCreate: createItem } },
    'textarea': {
      component: NInput,
      props: {
        type: 'textarea',
        rows: field?.componentProps?.rows || 3,
        placeholder: field?.componentProps?.placeholder || field?.placeholder,
      },
    },
    'note': {
      component: 'div',
      props: {
        class: 'text-xs text-muted leading-relaxed whitespace-pre-wrap',
        innerHTML: field?.componentProps?.content || field?.defaultValue || '',
      },
    },
    'text': { component: NInput, props: { placeholder: field?.placeholder } },
  }

  return map[type] || map.text
}
