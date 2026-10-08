<template>
  <div class="gs-picker">
    <div ref="el" class="gs-picker__map" />
    <p class="gs-picker__hint">
      <v-icon size="16">mdi-cursor-default-click-outline</v-icon>
      Cliquez sur la carte pour placer <strong>{{ activeLabel }}</strong>.
    </p>
  </div>
</template>

<script setup>
/** Carte de saisie : affiche les points du jeu, un clic positionne le point actif. */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import L from 'leaflet'
import { BASEMAPS } from '@/utils/basemaps'

const props = defineProps({ points: Array, active: Number, center: Array, zoom: Number, basemaps: Array })
const emit = defineEmits(['pick', 'activate'])
const el = ref(null)
let map = null
let layer = null

const activeLabel = computed(() => {
  const p = props.points[props.active]
  return p ? (p.name || `le point ${props.active + 1}`) : 'un point'
})

onMounted(() => {
  map = L.map(el.value, { scrollWheelZoom: true })
  const layers = {}
  for (const k of props.basemaps?.length ? props.basemaps : ['osm']) {
    const b = BASEMAPS[k]
    if (b) layers[b.label] = L.tileLayer(b.url, { attribution: b.attribution, maxZoom: b.maxZoom, subdomains: 'abc' })
  }
  Object.values(layers)[0].addTo(map)
  if (Object.keys(layers).length > 1) L.control.layers(layers).addTo(map)
  layer = L.featureGroup().addTo(map)
  map.setView(props.center || [46.6, 2.4], props.zoom || 6)
  map.on('click', (e) => emit('pick', { lat: +e.latlng.lat.toFixed(6), lon: +e.latlng.lng.toFixed(6) }))
  draw(true)
})
onBeforeUnmount(() => map?.remove())
watch(() => [props.points, props.active], () => draw(false), { deep: true })

function draw(fit) {
  if (!map) return
  layer.clearLayers()
  props.points.forEach((p, i) => {
    const lat = Number(p.latitude), lon = Number(p.longitude)
    if (!Number.isFinite(lat) || !Number.isFinite(lon) || p.latitude === '' || p.longitude === '') return
    const on = i === props.active
    L.circleMarker([lat, lon], { radius: on ? 9 : 6, weight: 2, color: '#fff', fillColor: on ? '#E07A1F' : '#3E4A59', fillOpacity: 1 })
      .bindTooltip(p.name || `Point ${i + 1}`)
      .on('click', (e) => { L.DomEvent.stopPropagation(e); emit('activate', i) })
      .addTo(layer)
  })
  if (fit && layer.getLayers().length) map.fitBounds(layer.getBounds().pad(0.3), { maxZoom: 13 })
}
</script>

<style scoped>
.gs-picker__map { height: 340px; border-radius: 8px; border: 1px solid rgb(var(--v-theme-line)); z-index: 0; cursor: crosshair; }
.gs-picker__hint { font-size: 0.82rem; color: rgb(var(--v-theme-muted)); margin: 6px 0 0; }
</style>
