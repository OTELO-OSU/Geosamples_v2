<template>
  <div class="gs-page">
    <h1>Mes données</h1>

    <v-card v-if="!site.me.canContribute" class="gs-panel pa-5 mt-6" max-width="640">
      <template v-if="site.me.pending">
        <h2>Demande envoyée</h2>
        <p class="mt-2">Un responsable du site doit valider votre participation. Vous recevrez un e-mail dès que ce sera fait.</p>
      </template>
      <template v-else-if="site.settings.allowJoinRequests">
        <h2>Contribuer à {{ site.title }}</h2>
        <p class="mt-2 mb-4">Pour déposer des jeux de données, demandez à rejoindre le projet. Le responsable recevra votre demande.</p>
        <v-textarea v-model="joinMessage" label="Message au responsable (facultatif)" rows="3" />
        <v-btn color="primary" :loading="busy" @click="join">Demander à contribuer</v-btn>
      </template>
      <p v-else>Les contributions à ce site se font sur invitation. Contactez le responsable{{ site.settings.contactEmail ? ` (${site.settings.contactEmail})` : '' }}.</p>
    </v-card>

    <template v-else>
      <v-tabs v-model="tab" color="primary" class="mt-4">
        <v-tab value="mine">Mes jeux</v-tab>
        <v-tab v-if="site.me.canManage" value="review">À valider<v-badge v-if="pendingCount" :content="pendingCount" inline color="amber-darken-2" /></v-tab>
        <v-tab v-if="site.me.canManage" value="all">Tous les jeux du site</v-tab>
      </v-tabs>
      <div class="d-flex flex-wrap align-center ga-3 my-4">
        <v-text-field v-model="q" prepend-inner-icon="mdi-magnify" label="Rechercher" density="compact" hide-details clearable style="max-width: 320px" />
        <v-select v-if="tab !== 'review'" v-model="status" :items="statusItems" label="Statut" density="compact" hide-details clearable style="max-width: 240px" />
        <v-spacer />
        <v-btn variant="outlined" prepend-icon="mdi-table-arrow-down" :disabled="!selection.length" :loading="exporting" @click="exportSel">
          Exporter pour modification ({{ selection.length }})
        </v-btn>
        <v-btn color="primary" prepend-icon="mdi-plus" to="/deposer">Déposer</v-btn>
      </div>
      <v-data-table-server
        v-model="selection" :headers="headers" :items="items" :items-length="total" :loading="loading"
        :items-per-page="perPage" :page="page" item-value="uuid" show-select class="gs-panel"
        no-data-text="Aucun jeu pour l'instant. Déposez-en un via le formulaire ou importez un tableur."
        @update:options="(o) => { page = o.page; perPage = o.itemsPerPage; load() }"
      >
        <template #item.title="{ item }"><router-link :to="`/jeux/${item.uuid}`" class="gs-tlink">{{ item.title }}</router-link></template>
        <template #item.status="{ item }"><v-chip :color="STATUS[item.status].color" size="small" variant="tonal">{{ STATUS[item.status].label }}</v-chip></template>
        <template #item.period="{ item }">{{ fmtPeriod(item.temporalStart, item.temporalEnd) }}</template>
        <template #item.updatedAt="{ item }">{{ fmtDate(item.updatedAt) }}</template>
        <template #item.actions="{ item }">
          <v-btn icon="mdi-pencil-outline" variant="text" size="small" :to="`/jeux/${item.uuid}/modifier`" :aria-label="`Modifier ${item.title}`" />
        </template>
      </v-data-table-server>
      <p class="text-muted text-body-2 mt-3">
        « Exporter pour modification » produit un tableur avec l'identifiant de chaque jeu : après correction,
        <router-link to="/importer">réimportez-le</router-link> pour mettre les jeux à jour en une fois.
      </p>
    </template>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import api, { errorMessage } from '@/api/client'
import { useSiteStore } from '@/stores/site'
import { useUiStore } from '@/stores/ui'
import { STATUS, fmtDate, fmtPeriod } from '@/utils/format'
import { downloadTable } from '@/utils/spreadsheet'

const siteStore = useSiteStore()
const site = computed(() => siteStore.site)
const ui = useUiStore()
const tab = ref('mine')
const q = ref('')
const status = ref(null)
const items = ref([])
const total = ref(0)
const page = ref(1)
const perPage = ref(25)
const loading = ref(false)
const selection = ref([])
const exporting = ref(false)
const pendingCount = ref(0)
const joinMessage = ref('')
const busy = ref(false)

const statusItems = Object.entries(STATUS).map(([value, s]) => ({ value, title: s.label }))
const headers = computed(() => [
  { title: 'Titre', key: 'title', sortable: false },
  { title: 'Statut', key: 'status', sortable: false },
  { title: 'Période', key: 'period', sortable: false },
  { title: 'Points', key: 'pointsCount', sortable: false, align: 'end' },
  ...(tab.value !== 'mine' ? [{ title: 'Déposant', key: 'owner', sortable: false }] : []),
  { title: 'Mis à jour', key: 'updatedAt', sortable: false },
  { title: '', key: 'actions', sortable: false, align: 'end' },
])

let t = null
watch([tab, q, status], () => { clearTimeout(t); t = setTimeout(() => { page.value = 1; selection.value = []; load() }, 250) })

async function load() {
  if (!site.value.me.canContribute) return
  loading.value = true
  try {
    const params = { page: page.value, perPage: perPage.value, q: q.value || undefined, scope: tab.value === 'mine' ? 'mine' : 'manage' }
    if (tab.value === 'review') params.status = 'submitted'
    else if (status.value) params.status = status.value
    const { data } = await api.get(`/api/sites/${site.value.slug}/datasets`, { params })
    items.value = data.items
    total.value = data.total
    if (site.value.me.canManage) {
      const r = await api.get(`/api/sites/${site.value.slug}/datasets`, { params: { scope: 'manage', status: 'submitted', perPage: 1 } })
      pendingCount.value = r.data.total
    }
  } catch (e) {
    ui.error(errorMessage(e))
  } finally {
    loading.value = false
  }
}

async function exportSel() {
  exporting.value = true
  try {
    const { data } = await api.get(`/api/sites/${site.value.slug}/export`, { params: { ids: selection.value.join(',') } })
    await downloadTable({ columns: data.columns, rows: data.rows, filename: `${site.value.slug}-a-modifier`, title: site.value.title, instructions: site.value.importTemplate?.instructions })
  } catch (e) {
    ui.error(errorMessage(e))
  } finally {
    exporting.value = false
  }
}

async function join() {
  busy.value = true
  try {
    await api.post(`/api/sites/${site.value.slug}/join`, { message: joinMessage.value })
    await siteStore.reload()
    ui.notify('Demande envoyée')
  } catch (e) {
    ui.error(errorMessage(e))
  } finally {
    busy.value = false
  }
}
</script>

<style scoped>
.gs-tlink { color: inherit; font-weight: 500; text-decoration: none; }
.gs-tlink:hover { text-decoration: underline; color: rgb(var(--v-theme-primary)); }
</style>
