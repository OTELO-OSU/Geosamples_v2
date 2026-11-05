<template>
  <article class="gs-detail">
    <header class="gs-detail__head">
      <div v-if="ds.status !== 'published'" class="mb-2">
        <v-chip :color="STATUS[ds.status].color" size="small" :prepend-icon="STATUS[ds.status].icon" variant="tonal">{{ STATUS[ds.status].label }}</v-chip>
      </div>
      <h1 :class="{ 'gs-detail__title--compact': compact }">{{ ds.title }}</h1>
      <p class="gs-detail__byline text-muted">
        <span v-if="period">{{ period }}</span>
        <span v-if="authors">{{ authors }}</span>
      </p>
      <v-alert v-if="ds.status === 'rejected' && ds.reviewComment" type="warning" variant="tonal" density="compact" class="mt-2" title="Commentaire du responsable">
        {{ ds.reviewComment }}
      </v-alert>
      <div v-if="ds.doi || ds.ordarUrl" class="gs-detail__ordar">
        <v-icon size="18" color="primary">mdi-database-check-outline</v-icon>
        Published in the ORDaR repository
        <a v-if="ds.doi" :href="`https://doi.org/${ds.doi}`" target="_blank" rel="noopener">doi:{{ ds.doi }}</a>
        <a v-else :href="ds.ordarUrl" target="_blank" rel="noopener">voir la fiche</a>
      </div>
      <div class="gs-detail__actions"><slot name="actions" /></div>
    </header>

    <p v-if="ds.description" class="gs-detail__desc gs-prose">{{ ds.description }}</p>

    <div class="gs-detail__grid mt-2" :class="{ 'gs-detail__grid--stack': compact }">
      <section>
        <h2 class="gs-h">Description</h2>
        <dl class="gs-meta">
          <template v-if="ds.keywords?.length"><dt>Keywords</dt><dd><v-chip v-for="k in ds.keywords" :key="k" size="small" class="mr-1 mb-1" variant="outlined">{{ k }}</v-chip></dd></template>
          <template v-for="r in datasetRows" :key="r.f.key">
            <dt>{{ r.f.label }}</dt>
            <dd v-if="r.kind === 'names'">
              {{ r.names.join(', ') }}
            </dd>
            <dd v-else>{{ plainText(r.f, r.v) }}</dd>
          </template>
          <template v-if="ds.license"><dt>Licence</dt><dd>{{ ds.license }}</dd></template>
          <dt>File access</dt><dd>{{ ds.accessRight === 'open' ? 'Open' : 'Restricted to project members' }}</dd>
          <template v-if="ds.language"><dt>Language</dt><dd>{{ ds.language === 'fr' ? 'French' : ds.language === 'en' ? 'English' : ds.language }}</dd></template>
          <dt>Updated</dt><dd>{{ fmtDate(ds.updatedAt) }}</dd>

          <template v-if="referent">
            <dt>Contact</dt>
            <dd>
              {{ referent.name }}
              <a v-if="referent.mail" :href="`mailto:${referent.mail}`">{{ referent.mail }}</a>
            </dd>
          </template>
        </dl>
        <PropertyGroups :groups="datasetGroups" />
      </section>

      <section v-if="methodologies.length" class="mt-8">
      <h2 class="gs-h">Methodology</h2>
      <dl class="gs-meta">
        <template v-for="(m, i) in methodologies" :key="i">
          <dt>{{ m.name || 'Method' }}</dt>
          <dd>{{ m.description }}</dd>
        </template>
      </dl>
    </section>

      <section class="mt-8">
        <h2 class="gs-h">Localisation</h2>
        <MiniMap :points="ds.points" :basemap="site.map?.defaultBasemap" />
        <dl v-if="ds.points.length === 1" class="gs-meta mt-3">
          <template v-if="p0.name"><dt>Point</dt><dd>{{ p0.name }}</dd></template>
          <dt>Coordinates (WGS84)</dt><dd class="gs-num">{{ fmtNumber(p0.latitude, 6) }}, {{ fmtNumber(p0.longitude, 6) }}</dd>
          <template v-if="p0.srcCrs && p0.srcCrs !== 'EPSG:4326'"><dt>Saisie d'origine</dt><dd class="gs-num">{{ p0.srcCrs }} — X {{ fmtNumber(p0.srcX, 2) }}, Y {{ fmtNumber(p0.srcY, 2) }}</dd></template>
          <template v-if="p0.elevation !== null"><dt>Altitude</dt><dd>{{ fmtElevation(p0.elevation, p0.verticalDatum) }}</dd></template>
          <template v-if="p0.observedAt"><dt>Date</dt><dd>{{ fmtPeriod(p0.observedAt, p0.observedEnd) }}</dd></template>
          <template v-for="r in p0Rows" :key="r.f.key">
            <dt>{{ r.f.label }}</dt>
            <dd v-if="r.kind === 'names'">
              {{ r.names.join(', ') }}
            </dd>
            <dd v-else>{{ plainText(r.f, r.v) }}</dd>
          </template>
        </dl>
        <p v-else class="text-muted mt-2 text-body-2">{{ ds.points.length }} points · altitude {{ elevRange }}</p>
        <PropertyGroups v-if="ds.points.length === 1" :groups="p0Groups" />
      </section>
    </div>

    <section v-if="ds.points.length > 1" class="mt-8">
      <h2 class="gs-h">Points et mesures</h2>
      <PointsChart v-if="chartField && chartable" :points="ds.points" :field="chartField" class="mb-4">
        <template #picker>
          <v-select v-if="numericPointFields.length > 1" v-model="chartKey" :items="numericPointFields" item-title="label" item-value="key" hide-details density="compact" style="max-width: 220px" aria-label="Displayed measurement" />
        </template>
      </PointsChart>
      <v-data-table :headers="pointHeaders" :items="pointRows" density="compact" :items-per-page="10" class="gs-table" />
    </section>

    <section v-if="ds.files.length" class="mt-8">
      <h2 class="gs-h">Fichiers</h2>
      <v-alert v-if="!ds.can.download" type="info" variant="tonal" density="compact" class="mb-3">
        Files for this dataset are restricted to project members.
      </v-alert>
      <div v-if="pictures.length && ds.can.download" class="gs-gallery">
        <button v-for="f in pictures" :key="f.id" class="gs-gallery__item" :aria-label="`Agrandir ${f.name}`" @click="zoom = f">
          <img :src="`${f.url}?inline=1`" :alt="f.name" loading="lazy" />
        </button>
      </div>
      <v-list density="compact" class="bg-transparent">
        <v-list-item v-for="f in otherFiles" :key="f.id" :title="f.name" :subtitle="fmtBytes(f.size)" prepend-icon="mdi-file-outline">
          <template #append>
            <v-btn
              v-if="ds.can.download"
              variant="text"
              size="small"
              prepend-icon="mdi-download"
              @click="downloadFile(f)"
            >
              Download
            </v-btn>
            <v-icon v-else size="small">mdi-lock-outline</v-icon>
          </template>
        </v-list-item>
      </v-list>
      <v-dialog :model-value="!!zoom" max-width="1100" @update:model-value="zoom = null">
        <v-card v-if="zoom">
          <img :src="`${zoom.url}?inline=1`" :alt="zoom.name" style="width: 100%; display: block" />
          <v-card-actions><span class="text-body-2 ml-2">{{ zoom.name }}</span><v-spacer /><v-btn :href="zoom.url" prepend-icon="mdi-download">Download</v-btn></v-card-actions>
        </v-card>
      </v-dialog>
    </section>
  </article>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { STATUS, fmtBytes, fmtDate, fmtElevation, fmtNumber, fmtPeriod, fmtValue } from '@/utils/format'
import MiniMap from './MiniMap.vue'
import PointsChart from './PointsChart.vue'
import PropertyGroups from './PropertyGroups.vue'
import api from '@/api/client'

const props = defineProps({ ds: { type: Object, required: true }, site: { type: Object, required: true }, compact: Boolean })
const zoom = ref(null)

/* ---------- generic helpers (keep above the computeds) ---------- */
const SPECIAL_KEYS = ['referent', 'methodology']
const isLegacy = (k) => k.startsWith('source_') // v1 leftovers
const isEmpty = (v) => v === undefined || v === null || v === '' || (Array.isArray(v) && !v.length)
const isPlainObject = (v) => !!v && typeof v === 'object' && !Array.isArray(v)
const humanize = (k) => {
  const s = k.replace(/[_-]+/g, ' ').trim().toLowerCase()
  return s.charAt(0).toUpperCase() + s.slice(1)
}

function asStructured(v) {
  if (v && typeof v === 'object') return v
  if (typeof v === 'string') {
    const s = v.trim()
    if (s[0] === '{' || s[0] === '[') {
      try { return JSON.parse(s) } catch { /* maybe several objects joined by a separator */ }
      if (s[0] === '{') {
        // {...} ; {...}  or  {...} | {...}  or  {...},{...}  ->  [{...},{...}]
        try { return JSON.parse('[' + s.replace(/\}\s*[;|,]\s*\{/g, '},{') + ']') } catch { /* not JSON */ }
      }
    }
  }
  return null
}

function entriesOf(obj) {
  const keys = Object.keys(obj)
  let prefix = ''
  if (keys.length > 1) {
    const common = keys.reduce((a, b) => {
      let i = 0
      while (i < a.length && a[i] === b[i]) i++
      return a.slice(0, i)
    })
    const cut = Math.max(common.lastIndexOf('_'), common.lastIndexOf('-'))
    prefix = cut >= 0 ? common.slice(0, cut + 1) : ''
    if (!prefix && keys.includes(common) && keys.every((k) => k === common || /^[_-]/.test(k.slice(common.length)))) {
      prefix = common
    }
  }
  return keys.map((k) => {
    const rest = k.slice(prefix.length).replace(/^[_-]+/, '')
    const v = obj[k]
    return { key: k, label: humanize(rest || k), value: v !== null && typeof v === 'object' ? JSON.stringify(v) : v }
  })
}

// 'names'  -> every item is an object with a single "name" key  -> simple list of names
// 'groups' -> every item is an object                            -> sub-section(s)
// 'plain'  -> anything else
function classify(v) {
  const s = asStructured(v)
  if (!s) return { kind: 'plain' }
  const list = Array.isArray(s) ? s : [s]
  if (!list.length) return { kind: 'plain' }
  const objs = list.map((i) => {
    const o = typeof i === 'string' ? asStructured(i) : i
    return isPlainObject(o) ? o : null
  })
  if (!objs.every(Boolean)) return { kind: 'plain' }
  const nameOnly = objs.every((o) => { const k = Object.keys(o); return k.length === 1 && k[0].toLowerCase() === 'name' })
  if (nameOnly) return { kind: 'names', names: objs.map((o) => String(Object.values(o)[0] ?? '')).filter(Boolean) }
  return { kind: 'groups', blocks: objs.map(entriesOf) }
}

function plainText(f, v) {
  return Array.isArray(v) ? v.map((x) => fmtValue(f, x)).join(', ') : fmtValue(f, v)
}
function cellText(f, v) {
  const c = classify(v)
  return c.kind === 'names' ? c.names.join(', ') : plainText(f, v)
}

const fieldDefs = computed(() => new Map(props.site.fields.map((f) => [f.key, f])))
const defOf = (k) => fieldDefs.value.get(k) ?? { key: k, label: humanize(k), type: 'string' }

function parseJson(v) {
  if (v && typeof v === 'object') return v
  if (typeof v !== 'string') return null
  try { return JSON.parse(v) } catch { return null }
}

/* ---------- dataset-level fields ---------- */
const referent = computed(() => {
  const r = parseJson(props.ds.properties?.referent)
  if (!r) return null
  const name = [r.REFERENT_FIRST_NAME, r.REFERENT_NAME].filter(Boolean).join(' ')
  const mail = r.REFERENT_MAIL || null
  return name || mail ? { name, mail } : null
})

const asArray = (v) => (Array.isArray(v) ? v : v ? [v] : [])

const methodologies = computed(() =>
  asArray(props.ds.properties?.methodology)
    .map((m) => ({
      name: m?.NAME?.replace(/^METHODOLOGY_/, '').charAt(0) + m?.NAME?.replace(/^METHODOLOGY_/, '').slice(1).toLowerCase(),
      description: m?.DESCRIPTION,
    }))
    .filter((m) => m.description && !['N/A', '-'].includes(m.description.trim()))
)

const declaredPointFields = computed(() => props.site.fields.filter((f) => f.scope === 'point'))

const datasetFields = computed(() => {
  const propsObj = props.ds.properties ?? {}
  const pointKeys = new Set(declaredPointFields.value.map((f) => f.key))
  return Object.keys(propsObj)
    .filter((k) => !SPECIAL_KEYS.includes(k) && !isLegacy(k) && !pointKeys.has(k) && !isEmpty(propsObj[k]))
    .map(defOf)
})
const datasetEntries = computed(() => datasetFields.value.map((f) => {
  const v = props.ds.properties[f.key]
  return { f, v, ...classify(v) }
}))
const datasetRows = computed(() => datasetEntries.value.filter((e) => e.kind !== 'groups'))
const datasetGroups = computed(() => datasetEntries.value.filter((e) => e.kind === 'groups'))

/* ---------- point-level fields ---------- */
// declared point fields + any other key found in the points' properties
const pointFields = computed(() => {
  const known = new Set(declaredPointFields.value.map((f) => f.key))
  const extra = []
  for (const p of props.ds.points) {
    for (const k of Object.keys(p.properties ?? {})) {
      if (!known.has(k) && !isLegacy(k)) { known.add(k); extra.push(defOf(k)) }
    }
  }
  return [...declaredPointFields.value, ...extra]
})
// a field is "structured" if at least one point has a multi-key object for it
const structuredPointKeys = computed(() => new Set(
  pointFields.value
    .filter((f) => props.ds.points.some((p) => classify(p.properties?.[f.key]).kind === 'groups'))
    .map((f) => f.key)
))
const scalarPointFields = computed(() => pointFields.value.filter((f) => !structuredPointKeys.value.has(f.key)))

function pointEntries(p) {
  return pointFields.value
    .filter((f) => !isEmpty(p?.properties?.[f.key]))
    .map((f) => { const v = p.properties[f.key]; return { f, v, ...classify(v) } })
}
const pointGroups = (item) => pointEntries(props.ds.points[(item.raw ?? item)._i]).filter((e) => e.kind === 'groups')

const p0 = computed(() => props.ds.points[0] || {})
const p0Rows = computed(() => pointEntries(p0.value).filter((e) => e.kind !== 'groups'))
const p0Groups = computed(() => pointEntries(p0.value).filter((e) => e.kind === 'groups'))

const numericPointFields = computed(() => scalarPointFields.value.filter((f) => ['number', 'integer'].includes(f.type) && props.ds.points.some((p) => p.properties?.[f.key] !== undefined)))
const chartKey = ref(null)
watch(numericPointFields, (v) => { if (!v.find((f) => f.key === chartKey.value)) chartKey.value = v[0]?.key || null }, { immediate: true })
const chartField = computed(() => numericPointFields.value.find((f) => f.key === chartKey.value))
const chartable = computed(() => props.ds.points.filter((p) => p.observedAt).length > 2)

const pointHeaders = computed(() => [
  { title: 'Point', key: 'name' },
  { title: 'Date', key: 'date' },
  { title: 'Lat.', key: 'lat', align: 'end' },
  { title: 'Lon.', key: 'lon', align: 'end' },
  { title: 'Alt. (m)', key: 'elev', align: 'end' },
  ...scalarPointFields.value.map((f) => ({ title: f.label + (f.unit ? ` (${f.unit})` : ''), key: f.key, align: ['number', 'integer'].includes(f.type) ? 'end' : 'start' })),
])
const pointRows = computed(() => props.ds.points.map((p, i) => ({
  _i: i,
  name: p.name, date: fmtDate(p.observedAt, true), lat: fmtNumber(p.latitude, 5), lon: fmtNumber(p.longitude, 5), elev: fmtNumber(p.elevation, 1),
  ...Object.fromEntries(scalarPointFields.value.map((f) => [f.key, cellText({ ...f, unit: null }, p.properties?.[f.key])])),
})))

/* ---------- header / misc (unchanged) ---------- */
const period = computed(() => fmtPeriod(props.ds.temporalStart, props.ds.temporalEnd))
const authors = computed(() => (props.ds.authors || []).map((a) => a.name + (a.affiliation ? ` (${a.affiliation})` : '')).join(', '))
const elevRange = computed(() => {
  const e = props.ds.points.map((p) => p.elevation).filter((v) => v !== null && v !== undefined)
  if (!e.length) return 'not provided'
  const mn = Math.min(...e), mx = Math.max(...e)
  return mn === mx ? fmtElevation(mn) : `${fmtNumber(mn, 1)} to ${fmtElevation(mx)}`
})
const pictures = computed(() => props.ds.files.filter((f) => f.kind === 'picture'))
const otherFiles = computed(() => props.ds.files.filter((f) => f.kind !== 'picture' || !props.ds.can.download))

function filenameFromHeaders(headers, fallback) {
  const cd = headers['content-disposition'] || ''
  const utf8 = /filename\*=UTF-8''([^;]+)/i.exec(cd)
  if (utf8) return decodeURIComponent(utf8[1])
  const plain = /filename="?([^";]+)"?/i.exec(cd)
  return plain ? plain[1] : fallback
}

async function downloadFile(f) {
  try {
    const res = await api.get(`/api/files/${f.id}`, { responseType: 'blob' })
    const url = URL.createObjectURL(res.data)
    const a = document.createElement('a')
    a.href = url
    a.download = filenameFromHeaders(res.headers, f.originalName ?? f.name ?? 'fichier')
    document.body.appendChild(a)
    a.click()
    a.remove()
    URL.revokeObjectURL(url)
  } catch (e) {
    // with responseType 'blob', the error body is also a Blob
    let msg = 'Download failed'
    try {
      const body = JSON.parse(await e.response.data.text())
      msg = body.error || msg
    } catch { /* keep default message */ }
    // show msg in a snackbar
  }
}

</script>

<style scoped>
.gs-detail__head h1 { max-width: 30ch; }
.gs-detail__title--compact { font-size: 1.35rem; }
.gs-detail__byline { display: flex; flex-wrap: wrap; gap: 4px 18px; margin: 8px 0 0; }
.gs-detail__ordar { display: inline-flex; align-items: center; gap: 6px; margin-top: 12px; font-size: 0.9rem; padding: 4px 10px; border: 1px solid rgb(var(--v-theme-line)); border-radius: 6px; }
.gs-detail__ordar a { color: rgb(var(--v-theme-primary)); }
.gs-detail__actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 16px; }
.gs-detail__actions:empty { display: none; }
.gs-detail__desc { white-space: pre-line; margin: 20px 0 0; }
.gs-detail__grid > section { min-width: 0; }
.gs-detail :deep(.gs-meta) { grid-template-columns: minmax(110px, 38%) minmax(0, 1fr); }
.gs-detail :deep(.gs-meta dd),
.gs-detail :deep(.gs-meta dt) { min-width: 0; overflow-wrap: anywhere; }
@media (max-width: 860px) { .gs-detail__grid { grid-template-columns: 1fr; } }
.gs-h { font-size: 1.02rem; margin-bottom: 12px; padding-bottom: 6px; border-bottom: 2px solid rgb(var(--v-theme-primary)); display: inline-block; }
.gs-num { font-variant-numeric: tabular-nums; }
.gs-gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 8px; margin-bottom: 12px; }
.gs-gallery__item { padding: 0; border: 0; background: none; cursor: zoom-in; aspect-ratio: 4 / 3; overflow: hidden; border-radius: 6px; }
.gs-gallery__item img { width: 100%; height: 100%; object-fit: cover; display: block; }
.gs-table { background: transparent; font-variant-numeric: tabular-nums; }
</style>
