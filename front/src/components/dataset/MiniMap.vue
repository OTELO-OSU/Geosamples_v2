<template><div ref="el" class="gs-minimap" role="img" :aria-label="`Localisation de ${points.length} point(s)`" /></template>

<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import L from 'leaflet'
import { BASEMAPS } from '@/utils/basemaps'

const props = defineProps({ points: { type: Array, default: () => [] }, basemap: { type: String, default: 'osm' } })
const el = ref(null)
let map = null
let layer = null

onMounted(() => {
  map = L.map(el.value, { scrollWheelZoom: false, attributionControl: true, zoomControl: true })
  const b = BASEMAPS[props.basemap] || BASEMAPS.osm
  L.tileLayer(b.url, { attribution: b.attribution, maxZoom: b.maxZoom, subdomains: 'abc' }).addTo(map)
  layer = L.featureGroup().addTo(map)
  draw()
})
onBeforeUnmount(() => map?.remove())
watch(() => props.points, draw, { deep: true })

function primary() {
  const v = getComputedStyle(el.value).getPropertyValue('--v-theme-primary').trim()
  return v ? `rgb(${v})` : '#00616F'
}

function draw() {
  if (!map) return
  layer.clearLayers()
  const seen = new Set()
  for (const p of props.points) {
    if (!Number.isFinite(+p.latitude) || !Number.isFinite(+p.longitude)) continue
    const k = `${(+p.latitude).toFixed(6)},${(+p.longitude).toFixed(6)}`
    if (seen.has(k)) continue
    seen.add(k)
    L.circleMarker([+p.latitude, +p.longitude], { radius: 6, weight: 2, color: '#fff', fillColor: primary(), fillOpacity: 1 })
      .bindTooltip(p.name || k).addTo(layer)
  }
  if (seen.size === 1) map.setView(layer.getBounds().getCenter(), 12)
  else if (seen.size > 1) map.fitBounds(layer.getBounds().pad(0.2), { maxZoom: 14 })
  else map.setView([46.6, 2.4], 5)
}
</script>

<style scoped>
.gs-minimap { height: 260px; border-radius: 8px; border: 1px solid rgb(var(--v-theme-line)); z-index: 0; }
</style>
