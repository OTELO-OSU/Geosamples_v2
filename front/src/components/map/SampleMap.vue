<template>
  <div class="gs-map">
    <div ref="el" class="gs-map__canvas" role="application" aria-label="Carte des jeux de données" />
    <div v-if="loading" class="gs-map__loading"><v-progress-linear indeterminate color="primary" height="3" /></div>
    <div v-if="info" class="gs-map__count" aria-live="polite">{{ info }}</div>
    <MapLegend v-if="scale.legend.length" :title="legendTitle" :items="scale.legend" :kind="scale.kind" class="gs-map__legend" />
    <v-dialog v-model="ladderOpen" persistent no-click-animation :scrim="false" scroll-strategy="none" max-width="700" scrollable class="gs-ladder-dialog">
      <v-card>
        <v-card-title class="d-flex align-center">
          <span class="text-body-1">Observations à cet emplacement</span>
          <v-spacer />
          <v-btn icon="mdi-close" variant="text" size="small" aria-label="Fermer" @click="ladderOpen = false" />
        </v-card-title>
        <v-divider />
        <v-card-text><div ref="ladderEl" /></v-card-text>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup>
/**
 * Carte des points de prélèvement.
 * Le serveur renvoie soit des regroupements (grille dépendant du zoom), soit les points
 * eux-mêmes ; dans ce dernier cas les observations d'un même jeu au même endroit (séries
 * temporelles) sont fusionnées en une station, puis markercluster gère l'affichage fin.
 */
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch, nextTick } from 'vue'
import api from '@/api/client'
import L, { loadLeaflet } from '@/utils/leaflet'
import { BASEMAPS, OVERLAYS } from '@/utils/basemaps'
import { buildScale } from '@/utils/colors'
import { fmtDate, fmtElevation, fmtNumber } from '@/utils/format'
import { buildLadder } from '@/utils/ladder'
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
const ladderOpen = ref(false)
const ladderEl = ref(null)
const selPoint = ref(null) // sampling point id of the point currently open
let ladderObs = []
let clusterLayer = null
let serverLayer = null
let abort = null
let timer = null
let lastSig = ''
let markers = [] // { mk, color, datasets }
let pendingFocus = props.focus

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
  if (pendingFocus) fit(pendingFocus, false)
  load()
})

onBeforeUnmount(() => { abort?.abort(); map.value?.remove() })

watch(() => props.params, () => { schedule() }, { deep: true })
watch(() => props.focus, (b) => {
  if (!b) return
  if (!map.value) { pendingFocus = b; return }
  fit(b)
})
watch(selPoint, refreshSelection)
watch(() => props.selected, (id) => { if (!id) selPoint.value = null })
// The dialog content is mounted lazily: build the ladder as soon as its container exists
watch(ladderEl, (host) => {
  if (!host) return
  host.replaceChildren(buildLadder(ladderObs, {
    onSelect: (id) => { ladderOpen.value = false; emit('select', id) },
    fmtDate, fmtElevation,
    selected: props.selected,
  }))
})

function openLadder(observations) {
  ladderObs = observations
  ladderOpen.value = true
  nextTick(renderLadder) // also rebuilds when the dialog is already open on another location
}

function refreshSelection() {
  for (const m of markers) {
    m.mk.setIcon(pointIcon(m.color, selPoint.value != null && m.ids.includes(selPoint.value)))
  }
}

function renderLadder() {
  const host = ladderEl.value
  if (!host) return
  host.replaceChildren(buildLadder(ladderObs, {
    onSelect: (o) => { selPoint.value = o.id ?? null; emit('select', o.dataset) }, // dialog stays open
    fmtDate, fmtElevation,
    selectedPoint: selPoint.value,
  }))
}

function schedule() {
  clearTimeout(timer)
  timer = setTimeout(load, 220)
}

function currentBbox() {
  const b = map.value.getBounds().pad(0.15)
  const w = Math.max(-180, b.getWest()), e = Math.min(180, b.getEast())
  return [w, Math.max(-90, b.getSouth()), e, Math.min(90, b.getNorth())].map((x) => x.toFixed(5)).join(',')
}

function fit(b, animate = true) {
  const m = map.value
  if (!m || !b) return
  m.invalidateSize()
  const bounds = L.latLngBounds([b[1], b[0]], [b[3], b[2]])
  m.fitBounds(bounds.pad(0.3), { maxZoom: 13, animate })
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
  const sig = JSON.stringify([data.mode, data.total, data.clusters, data.points])
  
  if (sig === lastSig) return
  lastSig = sig

  serverLayer.clearLayers()
  clusterLayer.clearLayers()
  markers = []

  const n = data.mode === 'points' ? data.points.length : (data.locations ?? data.total)
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

  scale.value = colorBy.value === 'none'
    ? { colorOf: () => null, legend: [], kind: 'none' }
    : buildScale(data.points.map((p) => p.v), colorField.value, { grouping: colorBy.value !== 'year' })

  const layers = []
  for (const p of data.points) {
    const observations = p.observations || []
    const datasets = p.datasets || []
    const color = scale.value.colorOf(p.v) || 'rgb(var(--v-theme-primary))'

    const ids = observations.map((o) => o.id)
    const isSelected = selPoint.value != null && ids.includes(selPoint.value)

    const mk = L.marker([p.lat, p.lon], {
      icon: pointIcon(color, isSelected),
      keyboard: true,
      title: datasets.map((d) => d.title).filter(Boolean).join(', '),
      riseOnHover: true,
    })

    const firstId = observations[0]?.dataset ?? datasets[0]?.dataset ?? p.dataset

    if (observations.length > 1) {
      mk.on('click', () => openLadder(observations))
    } else if (firstId) {
      const first = observations[0] || datasets[0] || p
      mk.bindTooltip(
        tooltip({ title: first.title, name: first.name, elev: first.elev, date: first.date, status: first.status, n: 1, dates: [] }),
        { direction: 'top', offset: [0, -8], opacity: 1, className: 'gs-tip' },
      )
      mk.on('click', () => { selPoint.value = first.id ?? null; emit('select', firstId) })
    }

    markers.push({ mk, color, ids })
    layers.push(mk)
  }
  clusterLayer.addLayers(layers)
}

function clusterIcon(count) {
  const size = Math.round(26 + Math.min(24, Math.log10(count) * 9))
  const label = count >= 10000 ? `${Math.round(count / 1000)}k` : count >= 1000 ? `${(count / 1000).toFixed(1).replace('.', ',')}k` : count
  return L.divIcon({ html: `<span>${label}</span>`, className: 'gs-cluster', iconSize: [size, size] })
}

function pointIcon(color, selected) {
  return L.divIcon({
    html: `<span class="gs-dot" style="--c:${color}"></span>`,
    className: `gs-pt${selected ? ' gs-pt--sel' : ''}`,
    iconSize: [14, 14],
  })
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
.gs-pt--sel .gs-dot { box-shadow: 0 0 0 3px rgb(var(--v-theme-accent)); transform: scale(1.25); }
.gs-tip { font-family: var(--gs-font-body); font-size: 0.82rem; line-height: 1.4; max-width: 300px; white-space: normal; }
.leaflet-container { font-family: var(--gs-font-body); }
.marker-cluster-small, .marker-cluster-medium, .marker-cluster-large { background: transparent; }
.gs-ladder { font-family: var(--gs-font-body); }
.gs-ladder__rail { stroke: rgb(var(--v-theme-ink)); stroke-width: 2; stroke-linecap: round; }
.gs-ladder__rung { stroke: rgb(var(--v-theme-ink)); stroke-width: 2; opacity: .55; }
.gs-ladder__label { font-size: 11px; fill: rgb(var(--v-theme-ink)); font-variant-numeric: tabular-nums; }
.gs-ladder__node { fill: rgb(var(--v-theme-primary)); stroke: #fff; stroke-width: 2; }
.gs-ladder__node--sel { stroke: rgb(var(--v-theme-accent)); stroke-width: 3; }
.gs-ladder__dataset-title {font-size: 11px; font-weight: 600;}
.gs-ladder__scroll { max-height: 65vh; max-width: 100%; overflow: auto; margin-top: 6px; }
.gs-ladder-dialog { justify-content: flex-start !important; }
.gs-ladder-dialog > .v-overlay__content { margin: 16px 16px 16px 400px; }
</style>