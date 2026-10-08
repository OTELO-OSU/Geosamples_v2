<template>
  <figure class="gs-chart">
    <div class="gs-chart__head">
      <figcaption>{{ field.label }}<span v-if="field.unit" class="text-muted"> ({{ field.unit }})</span></figcaption>
      <slot name="picker" />
    </div>
    <svg
      ref="svg" :viewBox="`0 0 ${W} ${H}`" class="gs-chart__svg" role="img"
      :aria-label="`${field.label} au cours du temps, ${pts.length} mesures`"
      @mousemove="hover" @mouseleave="hi = null"
    >
      <g class="grid">
        <line v-for="t in yTicks" :key="'y' + t" :x1="M.l" :x2="W - M.r" :y1="y(t)" :y2="y(t)" />
      </g>
      <g class="axis">
        <text v-for="t in yTicks" :key="'yl' + t" :x="M.l - 6" :y="y(t) + 4" text-anchor="end">{{ fmtNumber(t, 2) }}</text>
        <text v-for="t in xTicks" :key="'xl' + t" :x="x(t)" :y="H - 6" text-anchor="middle">{{ fmtTick(t) }}</text>
      </g>
      <path :d="path" class="line" />
      <circle v-for="(p, i) in pts" :key="i" :cx="x(p.t)" :cy="y(p.v)" r="2.5" class="dot" />
      <g v-if="hi">
        <line :x1="x(hi.t)" :x2="x(hi.t)" :y1="M.t" :y2="H - M.b" class="cross" />
        <circle :cx="x(hi.t)" :cy="y(hi.v)" r="5" class="dot dot--hi" />
      </g>
    </svg>
    <div v-if="hi" class="gs-chart__tip" :style="{ left: `${x(hi.t)}px` }">
      <strong>{{ fmtNumber(hi.v, 3) }}{{ field.unit ? ` ${field.unit}` : '' }}</strong><br />{{ fmtDate(hi.t, true) }}
    </div>
  </figure>
</template>

<script setup>
/** Série temporelle d'une mesure « point » d'un jeu (une ligne, survol avec réticule). */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import dayjs from 'dayjs'
import { fmtDate, fmtNumber } from '@/utils/format'

const props = defineProps({ points: Array, field: Object })
const H = 220
const width = ref(640)
let ro = null
const M = { l: 48, r: 14, t: 12, b: 26 }
const W = computed(() => width.value)
const svg = ref(null)
onMounted(() => {
  ro = new ResizeObserver(([e]) => { width.value = Math.max(280, Math.round(e.contentRect.width)) })
  ro.observe(svg.value.parentElement)
})
onBeforeUnmount(() => ro?.disconnect())
const hi = ref(null)

const pts = computed(() => props.points
  .map((p) => ({ t: p.observedAt ? dayjs(p.observedAt).valueOf() : null, v: Number(p.properties?.[props.field.key]) }))
  .filter((p) => p.t && Number.isFinite(p.v))
  .sort((a, b) => a.t - b.t))

const ext = computed(() => {
  const ts = pts.value.map((p) => p.t), vs = pts.value.map((p) => p.v)
  let v0 = Math.min(...vs), v1 = Math.max(...vs)
  if (v0 === v1) { v0 -= 1; v1 += 1 }
  const pad = (v1 - v0) * 0.08
  return { t0: Math.min(...ts), t1: Math.max(...ts), v0: v0 - pad, v1: v1 + pad }
})
const x = (t) => M.l + ((t - ext.value.t0) / (ext.value.t1 - ext.value.t0 || 1)) * (W.value - M.l - M.r)
const y = (v) => H - M.b - ((v - ext.value.v0) / (ext.value.v1 - ext.value.v0)) * (H - M.t - M.b)
const path = computed(() => pts.value.map((p, i) => `${i ? 'L' : 'M'}${x(p.t).toFixed(1)},${y(p.v).toFixed(1)}`).join(''))

function niceTicks(a, b, n) {
  const step0 = (b - a) / n
  const mag = 10 ** Math.floor(Math.log10(step0))
  const step = [1, 2, 2.5, 5, 10].map((m) => m * mag).find((s) => s >= step0)
  const out = []
  for (let v = Math.ceil(a / step) * step; v <= b; v += step) out.push(+v.toFixed(10))
  return out
}
const yTicks = computed(() => niceTicks(ext.value.v0, ext.value.v1, 4))
const xTicks = computed(() => {
  const { t0, t1 } = ext.value
  const years = dayjs(t1).diff(dayjs(t0), 'year')
  const unit = years >= 2 ? 'year' : 'month'
  const step = unit === 'year' ? Math.max(1, Math.ceil(years / 6)) : Math.max(1, Math.ceil(dayjs(t1).diff(dayjs(t0), 'month') / 6))
  const out = []
  let d = dayjs(t0).startOf(unit).add(1, unit)
  while (d.valueOf() <= t1 && out.length < 10) { out.push(d.valueOf()); d = d.add(step, unit) }
  return out
})
const fmtTick = (t) => (dayjs(t).month() === 0 ? dayjs(t).format('YYYY') : dayjs(t).format('MMM YY'))

function hover(e) {
  const r = svg.value.getBoundingClientRect()
  const px = ((e.clientX - r.left) / r.width) * W.value
  let best = null, bd = Infinity
  for (const p of pts.value) { const d = Math.abs(x(p.t) - px); if (d < bd) { bd = d; best = p } }
  hi.value = best
}
</script>

<style scoped>
.gs-chart { margin: 0; position: relative; }
.gs-chart__head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 4px; }
.gs-chart__head figcaption { font-weight: 600; font-size: 0.92rem; }
.gs-chart__svg { width: 100%; height: 220px; display: block; }
.grid line { stroke: rgb(var(--v-theme-line)); stroke-width: 1; }
.axis text { fill: rgb(var(--v-theme-muted)); font-size: 11px; font-variant-numeric: tabular-nums; }
.line { fill: none; stroke: rgb(var(--v-theme-primary)); stroke-width: 2; stroke-linejoin: round; }
.dot { fill: rgb(var(--v-theme-primary)); stroke: rgb(var(--v-theme-surface)); stroke-width: 2; }
.dot--hi { stroke-width: 2.5; }
.cross { stroke: rgb(var(--v-theme-muted)); stroke-dasharray: 3 3; }
.gs-chart__tip {
  position: absolute; top: 28px; transform: translateX(-50%); pointer-events: none;
  background: rgb(var(--v-theme-surface)); border: 1px solid rgb(var(--v-theme-line)); border-radius: 6px;
  padding: 4px 8px; font-size: 0.78rem; white-space: nowrap; font-variant-numeric: tabular-nums;
}
</style>
