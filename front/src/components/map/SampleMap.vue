<template>
  <div class="gs-map">
    <div ref="el" class="gs-map__canvas" role="application" aria-label="Carte des jeux de données" />
    <div v-if="loading" class="gs-map__loading"><v-progress-linear indeterminate color="primary" height="3" /></div>
    <div v-if="info" class="gs-map__count" aria-live="polite">{{ info }}</div>
    <MapLegend v-if="scale.legend.length" :title="legendTitle" :items="scale.legend" :kind="scale.kind" class="gs-map__legend" />
  </div>
</template>

<script setup>
/**
 * Carte des points de prélèvement.
 * Le serveur renvoie soit des regroupements (grille dépendant du zoom), soit les points
 * eux-mêmes ; dans ce dernier cas les observations d'un même jeu au même endroit (séries
 * temporelles) sont fusionnées en une station, puis markercluster gère l'affichage fin.
 */
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import api from '@/api/client'
import L, { loadLeaflet } from '@/utils/leaflet'
import { BASEMAPS, OVERLAYS } from '@/utils/basemaps'
import { buildScale } from '@/utils/colors'
import { fmtDate, fmtElevation, fmtNumber } from '@/utils/format'
import MapLegend from './MapLegend.vue'

const props = defineProps({
  site: { type: Object, required: true },
  params: { type: Object, default: () => ({}) },
  selected: { type: String, default: null },
  focus: { type: Array, default: null }, // bbox [w,s,e,n] à cadrer
})
const emit = defineEmits(['select', 'view'])

const el = ref(null)
const map = shallowRef(null)
const loading = ref(false)
const info = ref('')
const scale = ref({ colorOf: () => null, legend: [], kind: 'none' })
let clusterLayer = null
let serverLayer = null
let abort = null
let timer = null

const colorBy = computed(() => props.site.map?.colorBy || 'none')
const colorField = computed(() => props.site.fields?.find((f) => f.key === colorBy.value))
const legendTitle = computed(() => ({ elevation: 'Altitude (m)', year: 'Année', status: 'Statut' })[colorBy.value] || colorField.value?.label || '')

onMounted(async () => {
  await loadLeaflet()
  const cfg = props.site.map || {}
  const m = L.map(el.value, { zoomControl: false, worldCopyJump: true, preferCanvas: true })
  L.control.zoom({ position: 'topright', zoomInTitle: 'Zoom avant', zoomOutTitle: 'Zoom arrière' }).addTo(m)
  L.control.scale({ imperial: false, position: 'bottomleft' }).addTo(m)

  const base = {}
  for (const key of cfg.basemaps || ['osm']) {
    const b = BASEMAPS[key]
    if (b) base[b.label] = L.tileLayer(b.url, { attribution: b.attribution, maxZoom: b.maxZoom, subdomains: 'abc' })
  }
  const def = BASEMAPS[cfg.defaultBasemap] && base[BASEMAPS[cfg.defaultBasemap].label] ? base[BASEMAPS[cfg.defaultBasemap].label] : Object.values(base)[0]
  def?.addTo(m)
  const overlays = {}
  for (const o of Object.values(OVERLAYS)) overlays[o.label] = L.tileLayer.wms(o.wms, o.options)
  L.control.layers(base, overlays, { position: 'topright' }).addTo(m)

  clusterLayer = L.markerClusterGroup({
    showCoverageOnHover: false,
    maxClusterRadius: 48,
    spiderfyOnMaxZoom: true,
    chunkedLoading: true,
    iconCreateFunction: (c) => clusterIcon(c.getChildCount()),
  }).addTo(m)
  serverLayer = L.layerGroup().addTo(m)

  m.setView(cfg.center || [46.6, 2.4], cfg.zoom || 6)
  m.on('moveend', schedule)
  map.value = m
  load()
})

onBeforeUnmount(() => { abort?.abort(); map.value?.remove() })

watch(() => props.params, schedule, { deep: true })
watch(() => props.focus, (b) => {
  if (!b || !map.value) return
  const bounds = L.latLngBounds([b[1], b[0]], [b[3], b[2]])
  map.value.flyToBounds(bounds.pad(0.3), { maxZoom: 13, duration: 0.6 })
})

function schedule() {
  clearTimeout(timer)
  timer = setTimeout(load, 220)
}

function currentBbox() {
  const b = map.value.getBounds().pad(0.15)
  const w = Math.max(-180, b.getWest()), e = Math.min(180, b.getEast())
  return [w, Math.max(-90, b.getSouth()), e, Math.min(90, b.getNorth())].map((x) => x.toFixed(5)).join(',')
}

async function load() {
  if (!map.value) return
  abort?.abort()
  abort = new AbortController()
  loading.value = true
  const zoom = map.value.getZoom()
  const bbox = currentBbox()
  emit('view', { bbox, zoom })
  try {
    const { data } = await api.get(`/api/sites/${props.site.slug}/map`, { params: { ...props.params, bbox, zoom }, signal: abort.signal })
    render(data)
  } catch (e) {
    if (e.name !== 'CanceledError') info.value = 'Chargement de la carte impossible'
  } finally {
    loading.value = false
  }
}

function render(data) {
  serverLayer.clearLayers()
  clusterLayer.clearLayers()
  const n = data.total
  info.value = n === 0 ? 'Aucun point dans cette zone' : `${fmtNumber(n, 0)} point${n > 1 ? 's' : ''} dans la vue`

  for (const c of data.clusters) {
    const mk = L.marker([c.lat, c.lon], { icon: clusterIcon(c.count), keyboard: true, title: `${c.count} points` })
    mk.on('click', () => {
      const [w, s, e, nn] = c.bbox
      if (Math.abs(e - w) < 1e-6 && Math.abs(nn - s) < 1e-6) map.value.setView([c.lat, c.lon], map.value.getZoom() + 3)
      else map.value.fitBounds([[s, w], [nn, e]], { padding: [40, 40] })
    })
    serverLayer.addLayer(mk)
  }

  // Fusion des observations d'un même jeu au même endroit (séries temporelles)
  const stations = new Map()
  for (const p of data.points) {
    const k = `${p.dataset}|${p.lat.toFixed(6)}|${p.lon.toFixed(6)}`
    const s = stations.get(k)
    if (s) { s.n++; s.dates.push(p.date); s.values.push(p.v) } else stations.set(k, { ...p, n: 1, dates: [p.date], values: [p.v] })
  }
  scale.value = colorBy.value === 'none' ? { colorOf: () => null, legend: [], kind: 'none' } : buildScale(data.points.map((p) => p.v), colorField.value, { grouping: colorBy.value !== 'year' })

  const layers = []
  for (const s of stations.values()) {
    const color = scale.value.colorOf(s.values[s.values.length - 1]) || 'rgb(var(--v-theme-primary))'
    const sel = s.dataset === props.selected
    const mk = L.marker([s.lat, s.lon], { icon: pointIcon(color, s.n, sel), keyboard: true, title: s.title, riseOnHover: true })
    mk.bindTooltip(tooltip(s), { direction: 'top', offset: [0, -8], opacity: 1, className: 'gs-tip' })
    mk.on('click', () => emit('select', s.dataset))
    layers.push(mk)
  }
  clusterLayer.addLayers(layers)
}

function clusterIcon(count) {
  const size = Math.round(26 + Math.min(24, Math.log10(count) * 9))
  const label = count >= 10000 ? `${Math.round(count / 1000)}k` : count >= 1000 ? `${(count / 1000).toFixed(1).replace('.', ',')}k` : count
  return L.divIcon({ html: `<span>${label}</span>`, className: 'gs-cluster', iconSize: [size, size] })
}

function pointIcon(color, n, selected) {
  const size = n > 1 ? 22 : 14
  const html = n > 1
    ? `<span class="gs-station" style="--c:${color}"><b>${n}</b></span>`
    : `<span class="gs-dot" style="--c:${color}"></span>`
  return L.divIcon({ html, className: `gs-pt${selected ? ' gs-pt--sel' : ''}`, iconSize: [size, size] })
}

function esc(s) { return String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]) }

function tooltip(s) {
  const lines = [`<strong>${esc(s.title)}</strong>`]
  const meta = []
  if (s.name) meta.push(esc(s.name))
  if (s.elev !== null && s.elev !== undefined) meta.push(fmtElevation(s.elev))
  if (s.n > 1) {
    const ds = s.dates.filter(Boolean).sort()
    meta.push(`${s.n} observations${ds.length ? ` (${fmtDate(ds[0])} – ${fmtDate(ds[ds.length - 1])})` : ''}`)
  } else if (s.date) meta.push(fmtDate(s.date))
  if (meta.length) lines.push(`<span>${meta.join(' · ')}</span>`)
  if (s.status && s.status !== 'published') lines.push('<em>Non publié</em>')
  return lines.join('<br>')
}

defineExpose({ reload: load, map })
</script>

<style>
.gs-map { position: relative; height: 100%; width: 100%; }
.gs-map__canvas { position: absolute; inset: 0; background: #dde3e1; }
.gs-map__loading { position: absolute; top: 0; left: 0; right: 0; z-index: 500; }
.gs-map__count {
  position: absolute; left: 12px; top: 12px; z-index: 500;
  background: rgb(var(--v-theme-surface)); color: rgb(var(--v-theme-ink));
  border: 1px solid rgb(var(--v-theme-line)); border-radius: 6px; padding: 3px 10px; font-size: 0.82rem;
}
.gs-map__legend { position: absolute; right: 12px; bottom: 28px; z-index: 500; }

/* Regroupements : cercle plein à la couleur du site, chiffre en Archivo */
.gs-cluster {
  display: grid; place-items: center; border-radius: 50%;
  background: rgb(var(--v-theme-primary)); color: rgb(var(--v-theme-on-primary));
  box-shadow: 0 0 0 2px rgb(var(--v-theme-surface)), 0 0 0 6px rgba(var(--v-theme-primary), 0.22);
  font-family: var(--gs-font-display); font-weight: 600; font-size: 13px; font-variant-numeric: tabular-nums;
}
.gs-pt { display: grid; place-items: center; }
.gs-dot { width: 12px; height: 12px; border-radius: 50%; background: var(--c); border: 2px solid #fff; box-shadow: 0 0 0 1px rgba(0,0,0,.35); }
.gs-station {
  width: 22px; height: 22px; border-radius: 4px; background: var(--c); border: 2px solid #fff; box-shadow: 0 0 0 1px rgba(0,0,0,.35);
  display: grid; place-items: center; color: #fff; font: 600 10px/1 var(--gs-font-display);
}
.gs-pt--sel .gs-dot, .gs-pt--sel .gs-station { box-shadow: 0 0 0 3px rgb(var(--v-theme-accent)); transform: scale(1.25); }
.gs-tip { font-family: var(--gs-font-body); font-size: 0.82rem; line-height: 1.4; max-width: 300px; white-space: normal; }
.leaflet-container { font-family: var(--gs-font-body); }
.marker-cluster-small, .marker-cluster-medium, .marker-cluster-large { background: transparent; }
</style>
