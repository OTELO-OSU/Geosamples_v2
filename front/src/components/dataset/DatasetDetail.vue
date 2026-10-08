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
        Publié dans l'entrepôt ORDaR
        <a v-if="ds.doi" :href="`https://doi.org/${ds.doi}`" target="_blank" rel="noopener">doi:{{ ds.doi }}</a>
        <a v-else :href="ds.ordarUrl" target="_blank" rel="noopener">voir la fiche</a>
      </div>
      <div class="gs-detail__actions"><slot name="actions" /></div>
    </header>

    <p v-if="ds.description" class="gs-detail__desc gs-prose">{{ ds.description }}</p>

    <div class="gs-detail__grid" :class="{ 'gs-detail__grid--stack': compact }">
      <section>
        <h2 class="gs-h">Description</h2>
        <dl class="gs-meta">
          <template v-if="ds.keywords?.length"><dt>Mots-clés</dt><dd><v-chip v-for="k in ds.keywords" :key="k" size="small" class="mr-1 mb-1" variant="outlined">{{ k }}</v-chip></dd></template>
          <template v-for="f in datasetFields" :key="f.key">
            <dt>{{ f.label }}</dt><dd>{{ fmtValue(f, ds.properties[f.key]) }}</dd>
          </template>
          <template v-if="ds.license"><dt>Licence</dt><dd>{{ ds.license }}</dd></template>
          <dt>Accès aux fichiers</dt><dd>{{ ds.accessRight === 'open' ? 'Ouvert' : 'Réservé aux membres du projet' }}</dd>
          <template v-if="ds.language"><dt>Langue</dt><dd>{{ ds.language === 'fr' ? 'Français' : ds.language === 'en' ? 'Anglais' : ds.language }}</dd></template>
          <dt>Mis à jour</dt><dd>{{ fmtDate(ds.updatedAt) }}</dd>
        </dl>
      </section>
      <section>
        <h2 class="gs-h">Localisation</h2>
        <MiniMap :points="ds.points" :basemap="site.map?.defaultBasemap" />
        <dl v-if="ds.points.length === 1" class="gs-meta mt-3">
          <template v-if="p0.name"><dt>Point</dt><dd>{{ p0.name }}</dd></template>
          <dt>Coordonnées (WGS84)</dt><dd class="gs-num">{{ fmtNumber(p0.latitude, 6) }}, {{ fmtNumber(p0.longitude, 6) }}</dd>
          <template v-if="p0.srcCrs && p0.srcCrs !== 'EPSG:4326'"><dt>Saisie d'origine</dt><dd class="gs-num">{{ p0.srcCrs }} — X {{ fmtNumber(p0.srcX, 2) }}, Y {{ fmtNumber(p0.srcY, 2) }}</dd></template>
          <template v-if="p0.elevation !== null"><dt>Altitude</dt><dd>{{ fmtElevation(p0.elevation, p0.verticalDatum) }}</dd></template>
          <template v-if="p0.observedAt"><dt>Date</dt><dd>{{ fmtPeriod(p0.observedAt, p0.observedEnd) }}</dd></template>
          <template v-for="f in pointFields" :key="f.key">
            <template v-if="p0.properties?.[f.key] !== undefined"><dt>{{ f.label }}</dt><dd>{{ fmtValue(f, p0.properties[f.key]) }}</dd></template>
          </template>
        </dl>
        <p v-else class="text-muted mt-2 text-body-2">{{ ds.points.length }} points · altitude {{ elevRange }}</p>
      </section>
    </div>

    <section v-if="ds.points.length > 1" class="mt-8">
      <h2 class="gs-h">Points et mesures</h2>
      <PointsChart v-if="chartField && chartable" :points="ds.points" :field="chartField" class="mb-4">
        <template #picker>
          <v-select v-if="numericPointFields.length > 1" v-model="chartKey" :items="numericPointFields" item-title="label" item-value="key" hide-details density="compact" style="max-width: 220px" aria-label="Mesure affichée" />
        </template>
      </PointsChart>
      <v-data-table :headers="pointHeaders" :items="pointRows" density="compact" :items-per-page="10" class="gs-table" />
    </section>

    <section v-if="ds.files.length" class="mt-8">
      <h2 class="gs-h">Fichiers</h2>
      <v-alert v-if="!ds.can.download" type="info" variant="tonal" density="compact" class="mb-3">
        Les fichiers de ce jeu sont réservés aux membres du projet.
      </v-alert>
      <div v-if="pictures.length && ds.can.download" class="gs-gallery">
        <button v-for="f in pictures" :key="f.id" class="gs-gallery__item" :aria-label="`Agrandir ${f.name}`" @click="zoom = f">
          <img :src="`${f.url}?inline=1`" :alt="f.name" loading="lazy" />
        </button>
      </div>
      <v-list density="compact" class="bg-transparent">
        <v-list-item v-for="f in otherFiles" :key="f.id" :title="f.name" :subtitle="fmtBytes(f.size)" prepend-icon="mdi-file-outline">
          <template #append>
            <v-btn v-if="ds.can.download" :href="f.url" variant="text" size="small" prepend-icon="mdi-download">Télécharger</v-btn>
            <v-icon v-else size="small">mdi-lock-outline</v-icon>
          </template>
        </v-list-item>
      </v-list>
      <v-dialog :model-value="!!zoom" max-width="1100" @update:model-value="zoom = null">
        <v-card v-if="zoom">
          <img :src="`${zoom.url}?inline=1`" :alt="zoom.name" style="width: 100%; display: block" />
          <v-card-actions><span class="text-body-2 ml-2">{{ zoom.name }}</span><v-spacer /><v-btn :href="zoom.url" prepend-icon="mdi-download">Télécharger</v-btn></v-card-actions>
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

const props = defineProps({ ds: { type: Object, required: true }, site: { type: Object, required: true }, compact: Boolean })
const zoom = ref(null)

const p0 = computed(() => props.ds.points[0] || {})
const period = computed(() => fmtPeriod(props.ds.temporalStart, props.ds.temporalEnd))
const authors = computed(() => (props.ds.authors || []).map((a) => a.name + (a.affiliation ? ` (${a.affiliation})` : '')).join(', '))
const datasetFields = computed(() => props.site.fields.filter((f) => (f.scope || 'dataset') === 'dataset' && props.ds.properties[f.key] !== undefined && props.ds.properties[f.key] !== ''))
const pointFields = computed(() => props.site.fields.filter((f) => f.scope === 'point'))
const numericPointFields = computed(() => pointFields.value.filter((f) => ['number', 'integer'].includes(f.type) && props.ds.points.some((p) => p.properties?.[f.key] !== undefined)))
const chartKey = ref(null)
watch(numericPointFields, (v) => { if (!v.find((f) => f.key === chartKey.value)) chartKey.value = v[0]?.key || null }, { immediate: true })
const chartField = computed(() => numericPointFields.value.find((f) => f.key === chartKey.value))
const chartable = computed(() => props.ds.points.filter((p) => p.observedAt).length > 2)

const elevRange = computed(() => {
  const e = props.ds.points.map((p) => p.elevation).filter((v) => v !== null && v !== undefined)
  if (!e.length) return 'non renseignée'
  const mn = Math.min(...e), mx = Math.max(...e)
  return mn === mx ? fmtElevation(mn) : `${fmtNumber(mn, 1)} à ${fmtElevation(mx)}`
})

const pointHeaders = computed(() => [
  { title: 'Point', key: 'name' },
  { title: 'Date', key: 'date' },
  { title: 'Lat.', key: 'lat', align: 'end' },
  { title: 'Lon.', key: 'lon', align: 'end' },
  { title: 'Alt. (m)', key: 'elev', align: 'end' },
  ...pointFields.value.map((f) => ({ title: f.label + (f.unit ? ` (${f.unit})` : ''), key: f.key, align: ['number', 'integer'].includes(f.type) ? 'end' : 'start' })),
])
const pointRows = computed(() => props.ds.points.map((p) => ({
  name: p.name, date: fmtDate(p.observedAt, true), lat: fmtNumber(p.latitude, 5), lon: fmtNumber(p.longitude, 5), elev: fmtNumber(p.elevation, 1),
  ...Object.fromEntries(pointFields.value.map((f) => [f.key, fmtValue({ ...f, unit: null }, p.properties?.[f.key])])),
})))

const pictures = computed(() => props.ds.files.filter((f) => f.kind === 'picture'))
const otherFiles = computed(() => props.ds.files.filter((f) => f.kind !== 'picture' || !props.ds.can.download))
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
.gs-detail__grid { display: grid; grid-template-columns: 1.1fr 1fr; gap: 32px; margin-top: 28px; }
.gs-detail__grid--stack { grid-template-columns: 1fr; gap: 24px; }
@media (max-width: 860px) { .gs-detail__grid { grid-template-columns: 1fr; } }
.gs-h { font-size: 1.02rem; margin-bottom: 12px; padding-bottom: 6px; border-bottom: 2px solid rgb(var(--v-theme-primary)); display: inline-block; }
.gs-num { font-variant-numeric: tabular-nums; }
.gs-gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 8px; margin-bottom: 12px; }
.gs-gallery__item { padding: 0; border: 0; background: none; cursor: zoom-in; aspect-ratio: 4 / 3; overflow: hidden; border-radius: 6px; }
.gs-gallery__item img { width: 100%; height: 100%; object-fit: cover; display: block; }
.gs-table { background: transparent; font-variant-numeric: tabular-nums; }
</style>
