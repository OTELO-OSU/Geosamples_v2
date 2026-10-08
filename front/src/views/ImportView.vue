<template>
  <div class="gs-page">
    <h1>Import en lot</h1>
    <p class="text-muted mt-2 gs-prose">
      Une ligne du tableur correspond à un point de prélèvement. Les lignes qui partagent la même
      « dataset_ref » forment un seul jeu de données. Pour modifier des jeux existants, exportez-les
      depuis <router-link to="/espace">Mes données</router-link>, corrigez le fichier puis réimportez-le.
    </p>
    <div v-if="site.importTemplate?.instructions" class="gs-panel pa-4 mt-4 gs-prose" v-html="markdown(site.importTemplate.instructions)" />

    <v-stepper v-model="step" :items="['Modèle', 'Fichier', 'Vérification', 'Import']" alt-labels flat color="primary" class="mt-6 bg-transparent" hide-actions>
      <template #item.1>
        <div class="gs-cols">
          <div class="gs-panel pa-5">
            <h2>Modèle vide</h2>
            <p class="text-muted my-2">Colonnes du site, listes de valeurs et feuille d'aide incluses.</p>
            <div class="d-flex ga-2 mt-4">
              <v-btn color="primary" prepend-icon="mdi-microsoft-excel" :loading="dl === 'xlsx'" @click="template('xlsx')">Télécharger (.xlsx)</v-btn>
              <v-btn variant="outlined" :loading="dl === 'csv'" @click="template('csv')">CSV</v-btn>
            </div>
          </div>
          <div class="gs-panel pa-5">
            <h2>Modèle d'après un jeu existant</h2>
            <p class="text-muted my-2">Le fichier reprend les valeurs du jeu choisi, à adapter pour vos nouveaux jeux.</p>
            <v-autocomplete
              v-model="fromUuid" :items="searchItems" :loading="searching" item-title="title" item-value="uuid" label="Rechercher un jeu"
              no-filter hide-details class="mt-3" @update:search="search"
            />
            <v-btn class="mt-3" variant="outlined" :disabled="!fromUuid" :loading="dl === 'from'" prepend-icon="mdi-table-arrow-down" @click="fromExisting">Télécharger le modèle</v-btn>
          </div>
        </div>
        <div class="d-flex mt-6"><v-spacer /><v-btn color="primary" @click="step = 2">J'ai rempli mon fichier</v-btn></div>
      </template>

      <template #item.2>
        <v-file-input v-model="file" label="Fichier CSV ou XLSX" accept=".csv,.xlsx,.txt" prepend-icon="" prepend-inner-icon="mdi-paperclip" show-size @update:model-value="read" />
        <template v-if="table">
          <p class="my-3"><strong>{{ table.rows.length }}</strong> ligne(s) lue(s). Vérifiez la correspondance des colonnes :</p>
          <v-table density="compact" class="gs-panel">
            <thead><tr><th>Colonne du fichier</th><th>Correspond à</th><th>Exemple</th></tr></thead>
            <tbody>
              <tr v-for="h in table.headers" :key="h">
                <td>{{ h }}</td>
                <td style="min-width: 260px">
                  <v-select v-model="mapping[h]" :items="columnItems" density="compact" hide-details variant="plain" placeholder="Ignorer cette colonne" clearable :aria-label="`Correspondance de ${h}`" />
                </td>
                <td class="text-muted text-truncate" style="max-width: 260px">{{ table.rows[0]?.[h] }}</td>
              </tr>
            </tbody>
          </v-table>
          <v-alert v-if="missingRequired.length" type="warning" variant="tonal" density="compact" class="mt-3">
            Colonnes obligatoires non associées : {{ missingRequired.join(', ') }}
          </v-alert>
        </template>
        <div class="d-flex mt-6 ga-2"><v-btn variant="text" @click="step = 1">Retour</v-btn><v-spacer /><v-btn color="primary" :disabled="!table" :loading="busy" @click="preview">Vérifier</v-btn></div>
      </template>

      <template #item.3>
        <v-alert v-for="w in warnings" :key="w" type="warning" variant="tonal" density="compact" class="mb-2">{{ w }}</v-alert>
        <template v-if="result">
          <p class="mb-3">
            <strong>{{ result.datasets.length }}</strong> jeu(x) détecté(s) :
            <span class="text-success">{{ result.valid }} valide(s)</span><span v-if="result.invalid">, <span class="text-error">{{ result.invalid }} à corriger</span></span>.
          </p>
          <v-table density="compact" class="gs-panel">
            <thead><tr><th>Jeu</th><th>Action</th><th class="text-end">Points</th><th>Lignes</th><th>Contrôle</th></tr></thead>
            <tbody>
              <tr v-for="d in result.datasets" :key="d.ref">
                <td>{{ d.title || d.ref }}</td>
                <td>{{ d.action === 'create' ? 'Création' : 'Mise à jour' }}</td>
                <td class="text-end">{{ d.points }}</td>
                <td>{{ lines(d.lines) }}</td>
                <td>
                  <span v-if="isEmpty(d.errors)" class="text-success"><v-icon size="16">mdi-check</v-icon> OK</span>
                  <ul v-else class="gs-errs"><li v-for="(m, k) in d.errors" :key="k"><template v-if="String(k).startsWith('ligne')"><strong>{{ String(k).split(' · ')[0] }}</strong> : </template>{{ m }}</li></ul>
                </td>
              </tr>
            </tbody>
          </v-table>
          <v-checkbox v-model="submit" class="mt-4" hide-details :label="submitLabel" />
          <p class="text-muted text-body-2">Sinon, les jeux sont créés comme brouillons, à relire dans « Mes données ».</p>
        </template>
        <div class="d-flex mt-6 ga-2">
          <v-btn variant="text" @click="step = 2">Retour</v-btn><v-spacer />
          <v-btn color="primary" :disabled="!result || result.invalid > 0 || !result.valid" :loading="busy" @click="commit">Importer {{ result?.valid || '' }} jeu(x)</v-btn>
        </div>
        <p v-if="result?.invalid" class="text-body-2 text-error text-end mt-2">Corrigez le fichier puis rechargez-le : l'import se fait en une seule fois, tout ou rien.</p>
      </template>

      <template #item.4>
        <v-alert v-if="done" type="success" variant="tonal">
          {{ done.created.length }} jeu(x) créé(s), {{ done.updated.length }} mis à jour —
          {{ done.status === 'published' ? 'publiés' : done.status === 'submitted' ? 'en attente de validation' : 'enregistrés comme brouillons' }}.
        </v-alert>
        <div class="d-flex ga-2 mt-4">
          <v-btn color="primary" to="/espace">Voir mes données</v-btn>
          <v-btn variant="text" @click="reset">Importer un autre fichier</v-btn>
        </div>
      </template>
    </v-stepper>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import api, { errorDetails, errorMessage } from '@/api/client'
import { useSiteStore } from '@/stores/site'
import { useUiStore } from '@/stores/ui'
import { markdown } from '@/utils/format'
import { autoMapping, downloadTable, mapRows, readTable } from '@/utils/spreadsheet'

const site = computed(() => useSiteStore().site)
const ui = useUiStore()
const step = ref(1)
const columns = ref([])
const dl = ref(null)
const file = ref(null)
const table = ref(null)
const mapping = ref({})
const warnings = ref([])
const result = ref(null)
const submit = ref(false)
const busy = ref(false)
const done = ref(null)
const fromUuid = ref(null)
const searchItems = ref([])
const searching = ref(false)
let mapped = []

const included = computed(() => columns.value.filter((c) => c.include))
const columnItems = computed(() => columns.value.map((c) => ({ value: c.key, title: `${c.label} (${c.key})` })))
const missingRequired = computed(() => {
  const used = new Set(Object.values(mapping.value))
  return columns.value.filter((c) => c.required && !used.has(c.key)).map((c) => c.label)
})
const submitLabel = computed(() => (!site.value.settings.moderation || site.value.me.canManage ? 'Publier directement les jeux importés' : 'Soumettre directement les jeux à validation'))

onMounted(async () => {
  columns.value = (await api.get(`/api/sites/${site.value.slug}/import/columns`)).data.columns
  search('')
})

async function template(format) {
  dl.value = format
  try {
    await downloadTable({ columns: included.value, format, filename: `${site.value.slug}-modele-import`, title: site.value.title, instructions: site.value.importTemplate?.instructions })
  } finally { dl.value = null }
}

let st = null
function search(q) {
  clearTimeout(st)
  st = setTimeout(async () => {
    searching.value = true
    try {
      const scope = site.value.me.canManage ? 'manage' : 'public'
      const { data } = await api.get(`/api/sites/${site.value.slug}/datasets`, { params: { q, perPage: 15, scope } })
      searchItems.value = data.items
    } finally { searching.value = false }
  }, 250)
}

async function fromExisting() {
  dl.value = 'from'
  try {
    const { data } = await api.get(`/api/sites/${site.value.slug}/export`, { params: { ids: fromUuid.value, template: 1 } })
    await downloadTable({ columns: data.columns, rows: data.rows, filename: `${site.value.slug}-modele-${fromUuid.value.slice(-8)}`, title: site.value.title })
  } catch (e) { ui.error(errorMessage(e)) } finally { dl.value = null }
}

async function read(f) {
  const one = Array.isArray(f) ? f[0] : f
  table.value = null
  result.value = null
  if (!one) return
  try {
    table.value = await readTable(one)
    if (!table.value.rows.length) { ui.error('Le fichier ne contient aucune ligne de données'); table.value = null; return }
    mapping.value = autoMapping(table.value.headers, columns.value)
  } catch (e) {
    ui.error(`Lecture impossible : ${e.message}`)
  }
}

async function preview() {
  busy.value = true
  try {
    const r = mapRows(table.value.rows, mapping.value)
    mapped = r.rows
    warnings.value = r.warnings
    result.value = (await api.post(`/api/sites/${site.value.slug}/import/preview`, { rows: mapped })).data
    step.value = 3
  } catch (e) {
    ui.error(errorMessage(e))
  } finally { busy.value = false }
}

async function commit() {
  busy.value = true
  try {
    done.value = (await api.post(`/api/sites/${site.value.slug}/import/commit`, { rows: mapped, submit: submit.value })).data
    step.value = 4
  } catch (e) {
    ui.error(errorMessage(e))
    const det = errorDetails(e)
    if (Object.keys(det).length) await preview()
  } finally { busy.value = false }
}

function reset() { step.value = 2; file.value = null; table.value = null; result.value = null; done.value = null }
const isEmpty = (o) => !o || (Array.isArray(o) ? !o.length : !Object.keys(o).length)
function lines(ls) { return ls.length > 3 ? `${ls[0]}–${ls[ls.length - 1]}` : ls.join(', ') }
</script>

<style scoped>
.gs-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
@media (max-width: 760px) { .gs-cols { grid-template-columns: 1fr; } }
.gs-errs { margin: 0; padding-left: 16px; color: rgb(var(--v-theme-error)); font-size: 0.85rem; }
</style>
