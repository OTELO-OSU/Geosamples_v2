<template>
  <div class="gs-timeline" :class="{ 'gs-timeline--active': active }">
    <div class="gs-timeline__head">
      <span class="gs-timeline__label">{{ label }}</span>
      <v-btn v-if="active" size="x-small" variant="text" @click="reset">Toute la période</v-btn>
    </div>
    <svg class="gs-timeline__bars" :viewBox="`0 0 ${buckets.length * 10} 40`" preserveAspectRatio="none" aria-hidden="true">
      <rect
        v-for="(b, i) in buckets" :key="b.bucket"
        :x="i * 10 + 1" :width="8" :y="40 - h(b.count)" :height="h(b.count)"
        :class="i >= range[0] && i <= range[1] ? 'in' : 'out'"
      />
    </svg>
    <v-range-slider
      v-model="range" :min="0" :max="Math.max(0, buckets.length - 1)" :step="1"
      hide-details density="compact" color="primary" track-size="2" thumb-size="14"
      :aria-label="'Période'" class="gs-timeline__slider" @end="commit"
    />
    <div class="gs-timeline__axis"><span>{{ fmt(buckets[0]) }}</span><span>{{ fmt(buckets[buckets.length - 1]) }}</span></div>
  </div>
</template>

<script setup>
/** Frise temporelle : histogramme des observations + sélection d'une période. */
import { computed, ref, watch } from 'vue'
import dayjs from 'dayjs'

const props = defineProps({
  histogram: { type: Array, default: () => [] },
  unit: { type: String, default: 'year' },
  from: { type: String, default: null },
  to: { type: String, default: null },
})
const emit = defineEmits(['change'])

// Complète les périodes vides pour garder une échelle continue
const buckets = computed(() => {
  const h = props.histogram
  if (!h.length) return []
  const counts = Object.fromEntries(h.map((b) => [b.bucket, b.count]))
  const out = []
  const step = props.unit === 'month' ? 'month' : 'year'
  const fmtKey = step === 'month' ? 'YYYY-MM' : 'YYYY'
  let d = dayjs(h[0].bucket + (step === 'year' ? '-01-01' : '-01'))
  const end = dayjs(h[h.length - 1].bucket + (step === 'year' ? '-01-01' : '-01'))
  while (!d.isAfter(end) && out.length < 600) {
    const k = d.format(fmtKey)
    out.push({ bucket: k, count: counts[k] || 0 })
    d = d.add(1, step)
  }
  return out
})
const max = computed(() => Math.max(1, ...buckets.value.map((b) => b.count)))
const h = (c) => (c ? Math.max(3, (Math.sqrt(c) / Math.sqrt(max.value)) * 38) : 0)

const range = ref([0, 0])
function syncFromProps() {
  const n = buckets.value.length
  const i0 = props.from ? buckets.value.findIndex((b) => b.bucket >= props.from.slice(0, b.bucket.length)) : 0
  let i1 = n - 1
  if (props.to) {
    const idx = buckets.value.map((b) => b.bucket <= props.to.slice(0, b.bucket.length)).lastIndexOf(true)
    if (idx >= 0) i1 = idx
  }
  range.value = [Math.max(0, i0), Math.max(0, i1)]
}
watch([buckets, () => props.from, () => props.to], syncFromProps, { immediate: true })

const active = computed(() => !!(props.from || props.to))
const label = computed(() => {
  if (!buckets.value.length) return 'Aucune date'
  const a = buckets.value[range.value[0]], b = buckets.value[range.value[1]]
  const n = buckets.value.slice(range.value[0], range.value[1] + 1).reduce((s, x) => s + x.count, 0)
  const period = a.bucket === b.bucket ? fmt(a) : `${fmt(a)} – ${fmt(b)}`
  return `${period} · ${n.toLocaleString('fr-FR')} observation${n > 1 ? 's' : ''}`
})

function fmt(b) {
  if (!b) return ''
  return props.unit === 'month' ? dayjs(b.bucket + '-01').format('MMM YYYY') : b.bucket
}
function commit() {
  const n = buckets.value.length
  const full = range.value[0] === 0 && range.value[1] === n - 1
  emit('change', full ? { from: null, to: null } : { from: buckets.value[range.value[0]].bucket, to: buckets.value[range.value[1]].bucket })
}
function reset() { emit('change', { from: null, to: null }) }
</script>

<style scoped>
.gs-timeline { padding: 8px 14px 6px; }
.gs-timeline__head { display: flex; justify-content: space-between; align-items: center; min-height: 24px; }
.gs-timeline__label { font-size: 0.82rem; font-variant-numeric: tabular-nums; }
.gs-timeline--active .gs-timeline__label { font-weight: 600; color: rgb(var(--v-theme-primary)); }
.gs-timeline__bars { width: 100%; height: 40px; display: block; margin-top: 4px; }
.gs-timeline__bars rect.in { fill: rgb(var(--v-theme-primary)); }
.gs-timeline__bars rect.out { fill: rgb(var(--v-theme-line)); }
.gs-timeline__slider { margin: -10px -8px 0; }
.gs-timeline__axis { display: flex; justify-content: space-between; font-size: 0.75rem; color: rgb(var(--v-theme-muted)); margin-top: -6px; }
</style>
