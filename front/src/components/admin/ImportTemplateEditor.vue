<template>
  <div>
    <p class="text-muted mb-4 gs-prose">
      Choose the columns to include in the import template and their labels. Required columns and coordinates are always included.
      Existing spreadsheets remain importable: columns are matched by key or label.
    </p>
    <v-table density="compact" class="gs-panel">
      <thead><tr><th style="width: 70px">Include</th><th>Key</th><th>Template label</th><th>Example</th></tr></thead>
      <tbody>
        <tr v-for="c in columns" :key="c.key">
          <td><v-checkbox-btn v-model="state(c).include" :disabled="locked(c)" :aria-label="`Include ${c.key}`" /></td>
          <td><code>{{ c.key }}</code><span v-if="c.level === 'point'" class="text-muted text-caption"> · point</span></td>
          <td><v-text-field v-model="state(c).label" :placeholder="c.defaultLabel" density="compact" hide-details variant="plain" /></td>
          <td><v-text-field v-model="state(c).example" density="compact" hide-details variant="plain" placeholder="—" /></td>
        </tr>
      </tbody>
    </v-table>
    <v-textarea v-model="tpl.instructions" class="mt-6" label="Instructions shown on the import page and help sheet" rows="4" hint="Markdown supported" persistent-hint />
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import api from '@/api/client'

const props = defineProps({ slug: String })
const tpl = defineModel({ type: Object, required: true })
const columns = ref([])

onMounted(async () => {
  tpl.value.columns = tpl.value.columns && !Array.isArray(tpl.value.columns) ? tpl.value.columns : {}
  const { data } = await api.get(`/api/sites/${props.slug}/import/columns`)
  columns.value = data.columns.map((c) => ({ ...c, defaultLabel: tpl.value.columns[c.key]?.label ? c.key : c.label }))
})

const locked = (c) => c.required || ['longitude', 'latitude'].includes(c.key)
function state(c) {
  if (!tpl.value.columns[c.key]) tpl.value.columns[c.key] = { include: c.include, label: '', example: c.example || '' }
  return tpl.value.columns[c.key]
}
</script>
