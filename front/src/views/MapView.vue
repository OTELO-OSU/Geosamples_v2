<template>
  <div class="gs-mv" :class="{ 'gs-mv--side-closed': !sideOpen, 'gs-mv--preview': !!selected }">
    <aside class="gs-mv__side" aria-label="Recherche">
      <div class="gs-mv__intro">
        <div v-if="site.description" class="gs-prose gs-mv__desc" :class="{ 'gs-mv__desc--clamp': !introOpen }" v-html="markdown(site.description)" />
        <button v-if="site.description && site.description.length > 180" class="gs-link" @click="introOpen = !introOpen">{{ introOpen ? 'Réduire' : 'Lire la suite' }}</button>
        <p class="gs-mv__stats">
          <strong>{{ fmtNumber(facets?.datasets ?? 0, 0) }}</strong> jeu{{ (facets?.datasets ?? 0) > 1 ? 'x' : '' }} de données,
          <strong>{{ fmtNumber(facets?.points ?? 0, 0) }}</strong> point{{ (facets?.points ?? 0) > 1 ? 's' : '' }}
          <span v-if="filterCount" class="text-muted">avec les filtres actuels</span>
        </p>
      </div>
      <v-tabs v-model="tab" density="compact" color="primary" grow class="gs-mv__tabs">
        <v-tab value="filters">Filtres<v-badge v-if="filterCount" :content="filterCount" inline color="primary" /></v-tab>
        <v-tab value="list">Liste<span class="gs-mv__tabcount">{{ list.total }}</span></v-tab>
      </v-tabs>
      <div class="gs-mv__scroll">
        <FilterPanel v-show="tab === 'filters'" v-model="filters" :fields="site.fields" :facets="facets" class="pa-4" />
        <div v-show="tab === 'list'" class="pa-2">
          <div class="d-flex align-center ga-2 px-2 pb-2">
            <v-checkbox v-model="inView" label="Seulement la zone affichée" hide-details density="compact" />
            <v-spacer />
            <v-menu>
              <template #activator="{ props }"><v-btn v-bind="props" variant="text" size="small" icon="mdi-download" aria-label="Exporter la sélection" /></template>
              <v-list density="compact">
                <v-list-item title="Exporter en tableur (.xlsx)" @click="exportList('xlsx')" />
                <v-list-item title="Exporter en CSV" @click="exportList('csv')" />
              </v-list>
            </v-menu>
          </div>
          <v-select v-model="sort" :items="sorts" density="compact" hide-details class="px-2 pb-2" aria-label="Tri" />
          <p v-if="!list.items.length && !listLoading" class="text-muted pa-4">Aucun jeu ne correspond à ces critères. Élargissez la zone ou retirez un filtre.</p>
          <v-list lines="two" class="bg-transparent py-0">
            <v-list-item
              v-for="it in list.items" :key="it.uuid" :active="it.uuid === selected" color="primary" rounded="lg"
              @click="select(it.uuid, it.bbox)"
            >
              <template v-if="it.thumbnail" #prepend><v-avatar rounded="md" size="44"><v-img :src="it.thumbnail" cover alt="" /></v-avatar></template>
              <v-list-item-title class="gs-mv__ltitle">{{ it.title }}</v-list-item-title>
              <v-list-item-subtitle>
                {{ [fmtPeriod(it.temporalStart, it.temporalEnd), it.pointsCount > 1 ? `${it.pointsCount} points` : ''].filter(Boolean).join(' — ') }}
              </v-list-item-subtitle>
            </v-list-item>
          </v-list>
          <v-pagination v-if="list.total > list.perPage" v-model="page" :length="Math.ceil(list.total / list.perPage)" density="compact" :total-visible="5" class="mt-2" />
        </div>
      </div>
    </aside>

    <section class="gs-mv__map">
      <v-btn class="gs-mv__toggle" size="small" :icon="sideOpen ? 'mdi-chevron-left' : 'mdi-tune-variant'" :aria-label="sideOpen ? 'Masquer le panneau' : 'Afficher la recherche'" @click="sideOpen = !sideOpen" />
      <SampleMap :site="site" :params="mapParams" :selected="selected" :focus="focus" @select="select" @view="onView" />
      <div v-if="facets?.temporal?.histogram?.length > 1" class="gs-mv__timeline">
        <TimelineBar :histogram="facets.temporal.histogram" :unit="facets.temporal.unit" :from="filters.from" :to="filters.to" @change="(p) => (filters = { ...filters, ...p })" />
      </div>

      <transition name="gs-slide">
        <aside v-if="selected" class="gs-mv__preview" aria-label="Aperçu du jeu de données">
          <div class="gs-mv__preview-bar">
            <v-btn variant="text" size="small" prepend-icon="mdi-open-in-new" :to="`/jeux/${selected}`">Ouvrir la fiche</v-btn>
            <v-spacer />
            <v-btn icon="mdi-close" variant="text" size="small" aria-label="Fermer l'aperçu" @click="select(null)" />
          </div>
          <div class="gs-mv__preview-body">
            <v-progress-linear v-if="!preview" indeterminate color="primary" />
            <DatasetDetail v-else :ds="preview" :site="site" compact>
              <template #actions><DatasetActions :ds="preview" :site="site" size="small" @changed="(d) => { preview = d; refresh() }" @deleted="() => { select(null); refresh() }" /></template>
            </DatasetDetail>
          </div>
        </aside>
      </transition>
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api, { errorMessage } from '@/api/client'
import { useSiteStore } from '@/stores/site'
import { useUiStore } from '@/stores/ui'
import { emptyFilters, fromQuery, toParams, activeCount } from '@/utils/filters'
import { fmtNumber, fmtPeriod, markdown } from '@/utils/format'
import { downloadTable } from '@/utils/spreadsheet'
import SampleMap from '@/components/map/SampleMap.vue'
import FilterPanel from '@/components/map/FilterPanel.vue'
import TimelineBar from '@/components/map/TimelineBar.vue'
import DatasetDetail from '@/components/dataset/DatasetDetail.vue'
import DatasetActions from '@/components/dataset/DatasetActions.vue'

const siteStore = useSiteStore()
const ui = useUiStore()
const route = useRoute()
const router = useRouter()
const site = computed(() => siteStore.site)

const filters = ref(fromQuery(route.query, site.value.fields))
const selected = ref(route.query.jeu || null)
const preview = ref(null)
const facets = ref(null)
const list = ref({ total: 0, items: [], perPage: 20 })
const listLoading = ref(false)
const page = ref(1)
const sort = ref('updated')
const tab = ref('filters')
const inView = ref(false)
const view = ref({ bbox: null, zoom: null })
const focus = ref(null)
const introOpen = ref(false)
const sideOpen = ref(window.innerWidth > 900)
const sorts = [
  { value: 'updated', title: 'Mis à jour récemment' },
  { value: 'date', title: 'Date (récent d\'abord)' },
  { value: 'date_asc', title: 'Date (ancien d\'abord)' },
  { value: 'title', title: 'Titre' },
]

const params = computed(() => toParams(filters.value))
const mapParams = computed(() => params.value)
const filterCount = computed(() => activeCount(filters.value))

// Synchronisation avec l'URL (liens partageables)
watch([params, selected], () => {
  const q = { ...params.value }
  if (selected.value) q.jeu = selected.value
  router.replace({ query: q })
})

let ft = null
watch(params, () => { clearTimeout(ft); ft = setTimeout(() => { loadFacets(); page.value = 1; loadList() }, 150) })
watch([page, sort], loadList)
watch(inView, () => { page.value = 1; loadList() })
watch(selected, loadPreview, { immediate: true })

onMounted(() => { loadFacets(); loadList() })

async function loadFacets() {
  try {
    // La frise et les bornes d'altitude ignorent leurs propres filtres pour rester lisibles
    const p = toParams(filters.value, { omit: ['from', 'to', 'elevMin', 'elevMax'] })
    const full = (await api.get(`/api/sites/${site.value.slug}/facets`, { params: p })).data
    if (filterCount.value && (filters.value.from || filters.value.to || filters.value.elevMin !== null || filters.value.elevMax !== null)) {
      const cur = (await api.get(`/api/sites/${site.value.slug}/facets`, { params: params.value })).data
      full.datasets = cur.datasets
      full.points = cur.points
    }
    facets.value = full
  } catch (e) {
    ui.error(errorMessage(e))
  }
}

async function loadList() {
  listLoading.value = true
  try {
    const p = { ...params.value, page: page.value, perPage: 20, sort: sort.value }
    if (inView.value && view.value.bbox) p.bbox = view.value.bbox
    list.value = (await api.get(`/api/sites/${site.value.slug}/datasets`, { params: p })).data
  } catch (e) {
    ui.error(errorMessage(e))
  } finally {
    listLoading.value = false
  }
}

async function loadPreview() {
  preview.value = null
  if (!selected.value) return
  try {
    preview.value = (await api.get(`/api/sites/${site.value.slug}/datasets/${selected.value}`)).data
  } catch (e) {
    ui.error(errorMessage(e, 'Jeu introuvable'))
    selected.value = null
  }
}

function select(uuid, bbox) {
  selected.value = uuid
  if (bbox) focus.value = [...bbox]
}

let vt = null
function onView(v) {
  view.value = v
  if (inView.value) { clearTimeout(vt); vt = setTimeout(() => { page.value = 1; loadList() }, 300) }
}

function refresh() { loadFacets(); loadList(); focus.value = null }

async function exportList(format) {
  try {
    const p = { ...params.value }
    if (inView.value && view.value.bbox) p.bbox = view.value.bbox
    if (format === 'csv') {
      const qs = new URLSearchParams({ ...p, format: 'csv' }).toString()
      window.location.href = `/api/sites/${site.value.slug}/export?${qs}`
      return
    }
    const { data } = await api.get(`/api/sites/${site.value.slug}/export`, { params: p })
    await downloadTable({ columns: data.columns, rows: data.rows, filename: `${site.value.slug}-export`, title: site.value.title })
  } catch (e) {
    ui.error(errorMessage(e))
  }
}
</script>

<style scoped>
.gs-mv { display: grid; grid-template-columns: 380px 1fr; height: calc(100dvh - 60px); }
.gs-mv--side-closed { grid-template-columns: 0 1fr; }
.gs-mv__side { display: flex; flex-direction: column; min-height: 0; background: rgb(var(--v-theme-surface)); border-right: 1px solid rgb(var(--v-theme-line)); overflow: hidden; }
.gs-mv--side-closed .gs-mv__side { border: 0; }
.gs-mv__intro { padding: 18px 20px 12px; border-bottom: 1px solid rgb(var(--v-theme-line)); }
.gs-mv__desc { font-size: 0.92rem; }
.gs-mv__desc--clamp { display: -webkit-box; -webkit-line-clamp: 3; line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
.gs-mv__desc :deep(p:last-child) { margin-bottom: 0; }
.gs-mv__stats { margin: 10px 0 0; font-size: 0.9rem; }
.gs-mv__stats strong { font-family: var(--gs-font-display); font-size: 1.25rem; font-variant-numeric: tabular-nums; }
.gs-link { background: none; border: 0; padding: 0; color: rgb(var(--v-theme-primary)); cursor: pointer; font-size: 0.85rem; margin-top: 2px; }
.gs-mv__tabs { border-bottom: 1px solid rgb(var(--v-theme-line)); flex: none; }
.gs-mv__tabcount { margin-left: 6px; font-variant-numeric: tabular-nums; color: rgb(var(--v-theme-muted)); }
.gs-mv__scroll { overflow-y: auto; flex: 1; min-height: 0; }
.gs-mv__ltitle { white-space: normal; line-height: 1.3; font-weight: 500; }

.gs-mv__map { position: relative; min-width: 0; display: flex; flex-direction: column; }
.gs-mv__map > :deep(.gs-map) { flex: 1; }
.gs-mv__toggle { position: absolute; z-index: 600; left: 12px; top: 48px; }
.gs-mv__timeline { background: rgb(var(--v-theme-surface)); border-top: 1px solid rgb(var(--v-theme-line)); flex: none; }

.gs-mv__preview {
  position: absolute; z-index: 700; top: 0; right: 0; bottom: 0; width: min(460px, 100%);
  background: rgb(var(--v-theme-surface)); border-left: 1px solid rgb(var(--v-theme-line));
  display: flex; flex-direction: column; box-shadow: -8px 0 24px rgba(0, 0, 0, 0.08);
}
.gs-mv--preview :deep(.leaflet-right), .gs-mv--preview :deep(.gs-map__legend) { right: min(460px, 100%); }
@media (min-width: 901px) { .gs-mv--preview :deep(.leaflet-right) { margin-right: 0; } }
.gs-mv__preview-bar { display: flex; align-items: center; padding: 6px 8px; border-bottom: 1px solid rgb(var(--v-theme-line)); }
.gs-mv__preview-body { overflow-y: auto; padding: 18px 20px 32px; }
.gs-slide-enter-active, .gs-slide-leave-active { transition: transform 0.22s ease; }
.gs-slide-enter-from, .gs-slide-leave-to { transform: translateX(100%); }

@media (max-width: 900px) {
  .gs-mv { grid-template-columns: 1fr; grid-template-rows: 1fr; position: relative; }
  .gs-mv__side { position: absolute; z-index: 800; inset: 0 0 0 0; width: 100%; }
  .gs-mv--side-closed .gs-mv__side { display: none; }
  .gs-mv:not(.gs-mv--side-closed) .gs-mv__toggle { z-index: 900; }
}
</style>
