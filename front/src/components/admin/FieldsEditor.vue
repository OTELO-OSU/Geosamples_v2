<template>
  <div>
    <p class="text-muted mb-4 gs-prose">
      Common fields (title, description, keywords, authors, license, coordinates, elevation, dates) are available
      on every site. Add project-specific fields here; they appear in the form,
      the import template, dataset details, and optionally the map filters.
    </p>
    <v-expansion-panels v-model="open" variant="accordion">
      <v-expansion-panel v-for="(f, i) in modelValue" :key="f._k || f.key || i" :value="i">
        <v-expansion-panel-title>
          <div class="gs-ftitle">
            <strong>{{ f.label || 'New field' }}</strong>
            <span class="text-muted">{{ f.key }} · {{ typeLabel(f.type) }}{{ f.scope === 'point' ? ' · per point' : '' }}{{ f.filter ? ' · filterable' : '' }}{{ f.required ? ' · required' : '' }}</span>
          </div>
          <v-icon v-if="hasErr(i)" color="error" size="18" class="ml-2">mdi-alert-circle</v-icon>
        </v-expansion-panel-title>
        <v-expansion-panel-text>
          <div class="gs-grid">
            <v-text-field v-model="f.label" label="Label *" @update:model-value="autoKey(f)" />
            <v-text-field v-model="f.key" label="Key (import column) *" hint="lowercase letters, digits, and _; do not change after data has been entered" persistent-hint :error-messages="errors[`fields[${i}].key`]" />
            <v-select v-model="f.type" :items="TYPES" label="Type" :error-messages="errors[`fields[${i}].type`]" />
            <v-select v-model="f.scope" :items="SCOPES" label="Scope" hint="“Per point” for a value measured at each point / date" persistent-hint />
            <v-combobox
              v-if="['select', 'multiselect'].includes(f.type)" v-model="f.options" label="Allowed values *" multiple chips closable-chips
              class="gs-span-2" hint="Press Enter after each value" persistent-hint :error-messages="errors[`fields[${i}].options`]"
            />
            <v-text-field v-if="['number', 'integer'].includes(f.type)" v-model="f.unit" label="Unit" placeholder="ppm, °C, m…" />
            <v-text-field v-model="f.group" label="Form section" placeholder="e.g. Geology, Measurements" />
            <v-text-field v-model="f.help" label="Input help" class="gs-span-2" />
            <v-select v-model="f.filter" :items="filterItems(f)" label="Map filter" clearable />
            <div class="d-flex flex-wrap ga-4 align-center">
              <v-switch v-model="f.required" label="Required" color="primary" hide-details inset />
              <v-switch v-model="f.popup" label="Highlighted" color="primary" hide-details inset />
            </div>
          </div>
          <div class="d-flex mt-2">
            <v-btn variant="text" size="small" prepend-icon="mdi-arrow-up" :disabled="i === 0" @click="move(i, -1)">Move up</v-btn>
            <v-btn variant="text" size="small" prepend-icon="mdi-arrow-down" :disabled="i === modelValue.length - 1" @click="move(i, 1)">Move down</v-btn>
            <v-spacer />
            <v-btn variant="text" size="small" color="error" prepend-icon="mdi-delete-outline" @click="modelValue.splice(i, 1)">Delete field</v-btn>
          </div>
        </v-expansion-panel-text>
      </v-expansion-panel>
    </v-expansion-panels>
    <v-btn class="mt-4" variant="tonal" prepend-icon="mdi-plus" @click="add">Add field</v-btn>
    <v-alert type="info" variant="tonal" density="compact" class="mt-4">
      Deleting a field does not delete existing values; it only hides them.
    </v-alert>
  </div>
</template>

<script setup>
import { ref } from 'vue'

const props = defineProps({ modelValue: Array, errors: { type: Object, default: () => ({}) } })
const open = ref(null)
const TYPES = [
  { value: 'text', title: 'Short text' }, { value: 'textarea', title: 'Long text' }, { value: 'number', title: 'Decimal number' },
  { value: 'integer', title: 'Integer' }, { value: 'date', title: 'Date' }, { value: 'select', title: 'Single-select list' },
  { value: 'multiselect', title: 'Multi-select list' }, { value: 'boolean', title: 'Yes / no' }, { value: 'url', title: 'Web link' },
]
const SCOPES = [{ value: 'dataset', title: 'Dataset' }, { value: 'point', title: 'Per point' }]
const typeLabel = (t) => TYPES.find((x) => x.value === t)?.title || t
const hasErr = (i) => Object.keys(props.errors).some((k) => k.startsWith(`fields[${i}]`))

function filterItems(f) {
  if (['select', 'multiselect', 'boolean'].includes(f.type)) return [{ value: 'select', title: 'Choose from values' }]
  if (['number', 'integer'].includes(f.type)) return [{ value: 'range', title: 'Min. / max. range' }]
  return [{ value: 'text', title: 'Text search' }, { value: 'select', title: 'Choose from values' }]
}
function slug(s) {
  return s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').replace(/^(\d)/, 'f_$1').slice(0, 60)
}
function autoKey(f) { if (f._new) f.key = slug(f.label || '') }
function add() {
  props.modelValue.push({ _k: `k${Date.now()}`, _new: true, key: '', label: '', type: 'text', scope: 'dataset', options: [], required: false, filter: null, popup: false, unit: '', group: '', help: '' })
  open.value = props.modelValue.length - 1
}
function move(i, d) {
  const [x] = props.modelValue.splice(i, 1)
  props.modelValue.splice(i + d, 0, x)
  open.value = i + d
}
</script>

<style scoped>
.gs-ftitle { display: flex; flex-direction: column; gap: 2px; }
.gs-ftitle .text-muted { font-size: 0.8rem; }
.gs-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 16px; }
.gs-span-2 { grid-column: span 2; }
@media (max-width: 640px) { .gs-grid { grid-template-columns: 1fr; } .gs-span-2 { grid-column: auto; } }
</style>
