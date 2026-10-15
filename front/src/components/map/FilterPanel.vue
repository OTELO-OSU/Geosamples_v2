<template>
  <div class="gs-filters">
    <v-text-field
      v-model="q" prepend-inner-icon="mdi-magnify" label="Rechercher" placeholder="Titre, description, nom de point…"
      clearable hide-details density="compact" @click:clear="q = ''"
    />

    <section v-if="facets?.keywords?.length" class="gs-filters__block">
      <h3 class="gs-filters__h">Mots-clés</h3>
      <v-autocomplete
        :model-value="modelValue.kw" :items="facets.keywords" item-title="value" item-value="value"
        multiple chips closable-chips hide-details density="compact" placeholder="Choisir…"
        @update:model-value="set({ kw: $event })"
      >
        <template #item="{ props: p, item }">
          <v-list-item v-bind="p"><template #append><span class="gs-count">{{ item.raw.count }}</span></template></v-list-item>
        </template>
      </v-autocomplete>
    </section>

    <section v-if="natureOptions.length" class="gs-filters__block">
      <h3 class="gs-filters__h">Type de mesure</h3>
      <v-autocomplete
        :model-value="modelValue.nature || []"
        :items="natureOptions" item-title="value" item-value="value"
        multiple chips closable-chips hide-details density="compact" placeholder="Choisir…"
        @update:model-value="set({ nature: $event })"
      >
        <template #item="{ props: p, item }">
          <v-list-item v-bind="p">
            <template #append><span class="gs-count">{{ item.raw.count }}</span></template>
          </v-list-item>
        </template>
      </v-autocomplete>
    </section>

    <section v-if="sampleTypeOptions.length" class="gs-filters__block">
      <h3 class="gs-filters__h">Type d'échantillon</h3>
      <v-autocomplete
        :model-value="modelValue['sample-type'] || []"
        :items="sampleTypeOptions" item-title="value" item-value="value"
        multiple chips closable-chips hide-details density="compact" placeholder="Choisir…"
        @update:model-value="set({ 'sample-type': $event })"
      >
        <template #item="{ props: p, item }">
          <v-list-item v-bind="p">
            <template #append><span class="gs-count">{{ item.raw.count }}</span></template>
          </v-list-item>
        </template>
      </v-autocomplete>
    </section>

    <section v-if="elev.max !== null && elev.min !== elev.max" class="gs-filters__block">
      <h3 class="gs-filters__h d-flex">Altitude <v-spacer /><span class="gs-count">{{ Math.round(elevLive[0]) }} – {{ Math.round(elevLive[1]) }} m</span></h3>
      <v-range-slider
        v-model="elevLive" :min="elev.min" :max="elev.max" :step="elevStep" color="primary"
        hide-details density="compact" class="mx-1" :thumb-size="14" aria-label="Altitude"
        @end="(v) => set({ elevMin: v[0] <= elev.min ? null : v[0], elevMax: v[1] >= elev.max ? null : v[1] })"
      />
    </section>

    <section v-for="fd in filterFields" :key="fd.key" class="gs-filters__block">
      <h3 class="gs-filters__h">{{ fd.label }} <span v-if="fd.unit" class="text-muted">({{ fd.unit }})</span></h3>
      <v-autocomplete
        v-if="fd.filter === 'select'"
        :model-value="modelValue.f[fd.key] || []" :items="optionsFor(fd)" item-title="value" item-value="value"
        multiple chips closable-chips hide-details density="compact" placeholder="Toutes les valeurs"
        @update:model-value="setField(fd.key, $event)"
      >
        <template #item="{ props: p, item }">
          <v-list-item v-bind="p"><template #append><span class="gs-count">{{ item.raw.count ?? '' }}</span></template></v-list-item>
        </template>
      </v-autocomplete>
      <div v-else-if="fd.filter === 'range'" class="d-flex ga-2">
        <v-text-field
          :model-value="modelValue.f[fd.key]?.min" type="number" label="Min." hide-details density="compact"
          :placeholder="String(facets?.fields?.[fd.key]?.min ?? '')" @change="(e) => setRange(fd.key, 'min', e.target.value)"
        />
        <v-text-field
          :model-value="modelValue.f[fd.key]?.max" type="number" label="Max." hide-details density="compact"
          :placeholder="String(facets?.fields?.[fd.key]?.max ?? '')" @change="(e) => setRange(fd.key, 'max', e.target.value)"
        />
      </div>
      <v-text-field
        v-else :model-value="modelValue.f[fd.key]" hide-details density="compact" clearable
        @change="(e) => setField(fd.key, e.target.value)" @click:clear="setField(fd.key, '')"
      />
    </section>

    <v-btn v-if="count" block variant="tonal" class="mt-4" prepend-icon="mdi-filter-remove-outline" @click="$emit('update:modelValue', emptyFilters())">
      Effacer les filtres ({{ count }})
    </v-btn>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { activeCount, emptyFilters } from '@/utils/filters'

const props = defineProps({
  modelValue: { type: Object, required: true },
  fields: { type: Array, default: () => [] },
  facets: { type: Object, default: null },
})
const emit = defineEmits(['update:modelValue'])

const filterFields = computed(() => props.fields.filter((f) => f.filter))
const count = computed(() => activeCount({ ...props.modelValue, from: null, to: null }))

// Recherche texte avec temporisation
const q = ref(props.modelValue.q)
let t = null
watch(q, (v) => { clearTimeout(t); t = setTimeout(() => set({ q: v || '' }), 350) })
watch(() => props.modelValue.q, (v) => { if (v !== q.value) q.value = v })

const elev = computed(() => ({ min: floor(props.facets?.elevation?.min), max: ceil(props.facets?.elevation?.max) }))
const elevStep = computed(() => Math.max(1, Math.round((elev.value.max - elev.value.min) / 200)))
const elevRange = computed(() => [props.modelValue.elevMin ?? elev.value.min, props.modelValue.elevMax ?? elev.value.max])
const elevLive = ref([0, 0])
watch(elevRange, (v) => { elevLive.value = [...v] }, { immediate: true })
function floor(v) { return v === null || v === undefined ? null : Math.floor(v) }
function ceil(v) { return v === null || v === undefined ? null : Math.ceil(v) }

function optionsFor(fd) {
  const vals = props.facets?.fields?.[fd.key]?.values
  if (vals?.length) {
    const seen = new Set(vals.map((v) => v.value))
    // les valeurs sélectionnées restent proposées même si elles n'ont plus de résultat
    const extra = (props.modelValue.f[fd.key] || []).filter((v) => !seen.has(v)).map((v) => ({ value: v, count: 0 }))
    return [...vals, ...extra]
  }
  return (fd.options || []).map((o) => ({ value: o }))
}

function withSelected(list, selected) {
  const vals = list || []
  const seen = new Set(vals.map((v) => v.value))
  // les valeurs sélectionnées restent proposées même sans résultat
  const extra = (selected || []).filter((v) => !seen.has(v)).map((v) => ({ value: v, count: 0 }))
  return [...vals, ...extra]
}

const natureOptions = computed(() =>
  withSelected(props.facets?.measurement?.nature, props.modelValue.nature))
const sampleTypeOptions = computed(() =>
  withSelected(props.facets?.sample?.['sample-type'], props.modelValue['sample-type']))

function set(patch) { emit('update:modelValue', { ...props.modelValue, ...patch }) }
function setField(key, value) { set({ f: { ...props.modelValue.f, [key]: value } }) }
function setRange(key, side, value) {
  const cur = { ...(props.modelValue.f[key] || {}) }
  cur[side] = value === '' ? null : Number(value)
  setField(key, cur)
}
</script>

<style scoped>
.gs-filters__block { margin-top: 18px; }
.gs-filters__h { font-family: var(--gs-font-body); font-size: 0.86rem; font-weight: 600; margin-bottom: 6px; }
.gs-count { font-size: 0.78rem; color: rgb(var(--v-theme-muted)); font-variant-numeric: tabular-nums; }
</style>
