<template>
  <div>
    <PointPicker
      :points="modelValue" :active="active" :center="site.map.center" :zoom="site.map.zoom" :basemaps="site.map.basemaps"
      @pick="pick" @activate="(i) => { active = i; panel = i }"
    />
    <div class="d-flex align-center mt-4 mb-2">
      <h3>{{ modelValue.length }} point{{ modelValue.length > 1 ? 's' : '' }}</h3>
      <v-spacer />
      <v-btn variant="tonal" size="small" prepend-icon="mdi-plus" @click="add">Ajouter un point</v-btn>
    </div>
    <p v-if="errors.points" class="text-error text-body-2 mb-2">{{ errors.points }}</p>

    <v-expansion-panels v-model="panel" variant="accordion" class="gs-points">
      <v-expansion-panel v-for="(p, i) in pageItems" :key="p._k" :value="offset + i" @group:selected="({ value }) => value && (active = offset + i)">
        <v-expansion-panel-title>
          <span class="gs-points__title">
            <strong>{{ p.name || `Point ${offset + i + 1}` }}</strong>
            <span class="text-muted">{{ summary(p) }}</span>
          </span>
          <v-icon v-if="hasError(offset + i)" color="error" size="18" class="ml-2">mdi-alert-circle</v-icon>
        </v-expansion-panel-title>
        <v-expansion-panel-text>
          <div class="gs-grid">
            <v-text-field v-model="p.name" label="Nom du point / station" class="gs-span-2" />
            <v-select v-model="p.crs" :items="CRS_OPTIONS" label="Système de coordonnées" class="gs-span-2" @update:model-value="convert(p)" />
            <template v-if="p.crs === 'EPSG:4326'">
              <v-text-field v-model="p.latitude" label="Latitude *" type="number" step="any" hint="Degrés décimaux, ex. 48.6921" persistent-hint :error-messages="err(offset + i, 'latitude')" />
              <v-text-field v-model="p.longitude" label="Longitude *" type="number" step="any" hint="Degrés décimaux, ex. 6.1844" persistent-hint :error-messages="err(offset + i, 'longitude')" />
            </template>
            <template v-else>
              <v-text-field v-model="p.x" label="X / Est (m) *" type="number" step="any" @update:model-value="convert(p)" />
              <v-text-field v-model="p.y" label="Y / Nord (m) *" type="number" step="any" @update:model-value="convert(p)" />
              <p class="gs-span-2 text-body-2 text-muted mt-n2">
                <template v-if="p._convError">{{ p._convError }}</template>
                <template v-else-if="p.latitude !== ''">WGS84 : {{ p.latitude }}, {{ p.longitude }}</template>
              </p>
            </template>
            <v-text-field v-model="p.elevation" label="Altitude" suffix="m" type="number" step="any" hint="Négative pour une profondeur" persistent-hint :error-messages="err(offset + i, 'elevation')" />
            <v-text-field v-model="p.verticalDatum" label="Référentiel altimétrique" :placeholder="site.settings.verticalDatum || ''" />
            <v-text-field v-model="p.observedAt" label="Date / début" placeholder="2024-05-17 ou 2024-05-17 10:30" :error-messages="err(offset + i, 'observedAt')" />
            <v-text-field v-model="p.observedEnd" label="Fin (si période)" placeholder="facultatif" :error-messages="err(offset + i, 'observedEnd')" />
            <FieldInput
              v-for="f in pointFields" :key="f.key" v-model="p.properties[f.key]" :field="f"
              :error="err(offset + i, `properties.${f.key}`)" :class="{ 'gs-span-2': f.type === 'textarea' }"
            />
          </div>
          <div class="d-flex justify-end mt-2">
            <v-btn variant="text" size="small" prepend-icon="mdi-content-copy" @click="duplicate(offset + i)">Dupliquer</v-btn>
            <v-btn variant="text" size="small" color="error" prepend-icon="mdi-delete-outline" :disabled="modelValue.length === 1" @click="remove(offset + i)">Retirer</v-btn>
          </div>
        </v-expansion-panel-text>
      </v-expansion-panel>
    </v-expansion-panels>
    <v-pagination v-if="modelValue.length > PER" v-model="pg" :length="Math.ceil(modelValue.length / PER)" density="compact" class="mt-2" />
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { CRS_OPTIONS, fromWgs84, toWgs84 } from '@/utils/crs'
import { fmtElevation } from '@/utils/format'
import { newPoint } from '@/utils/points'
import FieldInput from './FieldInput.vue'
import PointPicker from './PointPicker.vue'

const props = defineProps({ modelValue: Array, site: Object, errors: { type: Object, default: () => ({}) } })
const PER = 20
const active = ref(0)
const panel = ref(props.modelValue.length === 1 ? 0 : null)
const pg = ref(1)
const offset = computed(() => (pg.value - 1) * PER)
const pageItems = computed(() => props.modelValue.slice(offset.value, offset.value + PER))
const pointFields = computed(() => props.site.fields.filter((f) => f.scope === 'point'))

let k = 0

function add() {
  props.modelValue.push(newPoint())
  active.value = props.modelValue.length - 1
  pg.value = Math.ceil(props.modelValue.length / PER)
  panel.value = active.value
}
function duplicate(i) {
  const src = props.modelValue[i]
  props.modelValue.splice(i + 1, 0, { ...JSON.parse(JSON.stringify(src)), id: undefined, _k: `d${Date.now()}${k++}`, name: src.name ? `${src.name} (copie)` : '' })
  active.value = i + 1
  panel.value = i + 1
}
function remove(i) {
  props.modelValue.splice(i, 1)
  active.value = Math.max(0, Math.min(active.value, props.modelValue.length - 1))
}
function pick({ lat, lon }) {
  const p = props.modelValue[active.value]
  if (!p) return
  p.latitude = lat
  p.longitude = lon
  if (p.crs !== 'EPSG:4326') {
    const { x, y } = fromWgs84(p.crs, lon, lat)
    p.x = x
    p.y = y
  }
  pg.value = Math.floor(active.value / PER) + 1
  panel.value = active.value
}
function convert(p) {
  p._convError = ''
  if (p.crs === 'EPSG:4326') {
    if (p.x !== '' && p.latitude === '') { p.longitude = p.x; p.latitude = p.y }
    return
  }
  if (p.x === '' || p.y === '') {
    if (p.latitude !== '' && p.longitude !== '') { const r = fromWgs84(p.crs, p.longitude, p.latitude); p.x = r.x; p.y = r.y }
    return
  }
  try {
    const { lon, lat } = toWgs84(p.crs, p.x, p.y)
    p.longitude = lon
    p.latitude = lat
  } catch (e) {
    p._convError = e.message
  }
}
function summary(p) {
  const parts = []
  if (p.latitude !== '' && p.longitude !== '') parts.push(`${(+p.latitude).toFixed(4)}, ${(+p.longitude).toFixed(4)}`)
  else parts.push('position à renseigner')
  if (p.elevation !== '' && p.elevation !== null) parts.push(fmtElevation(+p.elevation))
  if (p.observedAt) parts.push(String(p.observedAt).slice(0, 16).replace('T', ' '))
  return parts.join(' — ')
}
const err = (i, f) => props.errors[`points[${i}].${f}`] || ''
const hasError = (i) => Object.keys(props.errors).some((e) => e.startsWith(`points[${i}]`))
</script>

<style scoped>
.gs-points__title { display: flex; flex-direction: column; gap: 2px; }
.gs-points__title .text-muted { font-size: 0.82rem; }
.gs-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 16px; }
.gs-span-2 { grid-column: span 2; }
@media (max-width: 640px) { .gs-grid { grid-template-columns: 1fr; } .gs-span-2 { grid-column: auto; } }
</style>
