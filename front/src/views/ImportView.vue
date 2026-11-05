<template>
  <div class="gs-page">
    <h1>Bulk import</h1>
    <p class="text-muted mt-2 gs-prose">
      Each spreadsheet row corresponds to a sampling point. Rows with the same
      “dataset_ref” form one dataset. To edit existing datasets, export them from
      <router-link to="/workspace">My data</router-link>, edit the file, then re-import it.
    </p>
    <div v-if="site.importTemplate?.instructions" class="gs-panel pa-4 mt-4 gs-prose" v-html="markdown(site.importTemplate.instructions)" />

    <v-stepper v-model="step" :items="['Template', 'File', 'Review', 'Import']" alt-labels flat color="primary" class="mt-6 bg-transparent" hide-actions>
      <template #item.1>
        <div class="gs-cols">
          <div class="gs-panel pa-5">
            <h2>Blank template</h2>
            <p class="text-muted my-2">Includes site columns, value lists, and a help sheet.</p>
            <div class="d-flex ga-2 mt-4">
              <v-btn color="primary" prepend-icon="mdi-microsoft-excel" :loading="dl === 'xlsx'" @click="template('xlsx')">Download (.xlsx)</v-btn>
              <v-btn variant="outlined" :loading="dl === 'csv'" @click="template('csv')">CSV</v-btn>
            </div>
          </div>
          <div class="gs-panel pa-5">
            <h2>Template from an existing dataset</h2>
            <p class="text-muted my-2">The file contains values from the selected dataset; adapt it for your new datasets.</p>
            <v-autocomplete
              v-model="fromUuid" :items="searchItems" :loading="searching" item-title="title" item-value="uuid" label="Search datasets"
              no-filter hide-details class="mt-3" @update:search="search"
            />
            <v-btn class="mt-3" variant="outlined" :disabled="!fromUuid" :loading="dl === 'from'" prepend-icon="mdi-table-arrow-down" @click="fromExisting">Download template</v-btn>
          </div>
        </div>
        <div class="d-flex mt-6"><v-spacer /><v-btn color="primary" @click="step = 2">I've filled in my file</v-btn></div>
      </template>

      <template #item.2>
        <v-file-input v-model="file" label="CSV or XLSX file" accept=".csv,.xlsx,.txt" prepend-icon="" prepend-inner-icon="mdi-paperclip" show-size @update:model-value="read" />
        <template v-if="table">
          <p class="my-3"><strong>{{ table.rows.length }}</strong> row(s) read. Check the column mapping:</p>
          <v-table density="compact" class="gs-panel">
            <thead><tr><th>File column</th><th>Maps to</th><th>Example</th></tr></thead>
            <tbody>
              <tr v-for="h in table.headers" :key="h">
                <td>{{ h }}</td>
                <td style="min-width: 260px">
                  <v-select v-model="mapping[h]" :items="columnItems" density="compact" hide-details variant="plain" placeholder="Ignore this column" clearable :aria-label="`Mapping for ${h}`" />
                </td>
                <td class="text-muted text-truncate" style="max-width: 260px">{{ table.rows[0]?.[h] }}</td>
              </tr>
            </tbody>
          </v-table>
          <v-alert v-if="missingRequired.length" type="warning" variant="tonal" density="compact" class="mt-3">
            Unmapped required columns: {{ missingRequired.join(', ') }}
          </v-alert>
        </template>
        <div class="d-flex mt-6 ga-2"><v-btn variant="text" @click="step = 1">Back</v-btn><v-spacer /><v-btn color="primary" :disabled="!table" :loading="busy" @click="preview">Review</v-btn></div>
      </template>

      <template #item.3>
        <v-alert v-for="w in warnings" :key="w" type="warning" variant="tonal" density="compact" class="mb-2">{{ w }}</v-alert>
        <template v-if="result">
          <p class="mb-3">
            <strong>{{ result.datasets.length }}</strong> dataset(s) found:
            <span class="text-success">{{ result.valid }} valid</span><span v-if="result.invalid">, <span class="text-error">{{ result.invalid }} need correction</span></span>.
          </p>
          <v-table density="compact" class="gs-panel">
            <thead><tr><th>Dataset</th><th>Action</th><th class="text-end">Points</th><th>Rows</th><th>Validation</th></tr></thead>
            <tbody>
              <tr v-for="d in result.datasets" :key="d.ref">
                <td>{{ d.title || d.ref }}</td>
                <td>{{ d.action === 'create' ? 'Create' : 'Update' }}</td>
                <td class="text-end">{{ d.points }}</td>
                <td>{{ lines(d.lines) }}</td>
                <td>
                  <span v-if="isEmpty(d.errors)" class="text-success"><v-icon size="16">mdi-check</v-icon> OK</span>
                  <ul v-else class="gs-errs"><li v-for="(m, k) in d.errors" :key="k"><template v-if="String(k).startsWith('row ')"><strong>{{ String(k).split(' · ')[0] }}</strong>: </template>{{ m }}</li></ul>
                </td>
              </tr>
            </tbody>
          </v-table>
          <v-checkbox v-model="submit" class="mt-4" hide-details :label="submitLabel" />
          <p class="text-muted text-body-2">Otherwise, datasets are created as drafts and can be reviewed under “My data”.</p>
        </template>
        <div class="d-flex mt-6 ga-2">
          <v-btn variant="text" @click="step = 2">Back</v-btn><v-spacer />
          <v-btn color="primary" :disabled="!result || result.invalid > 0 || !result.valid" :loading="busy" @click="commit">Import {{ result?.valid || '' }} dataset(s)</v-btn>
        </div>
        <p v-if="result?.invalid" class="text-body-2 text-error text-end mt-2">Correct the file and upload it again. The import is all-or-nothing.</p>
      </template>

      <template #item.4>
        <v-alert v-if="done" type="success" variant="tonal">
          {{ done.created.length }} dataset(s) created, {{ done.updated.length }} updated —
          {{ done.status === 'published' ? 'published' : done.status === 'submitted' ? 'pending review' : 'saved as drafts' }}.
        </v-alert>
        <div class="d-flex ga-2 mt-4">
          <v-btn color="primary" to="/workspace">View my data</v-btn>
          <v-btn variant="text" @click="reset">Import another file</v-btn>
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
const submitLabel = computed(() => (!site.value.settings.moderation || site.value.me.canManage ? 'Publish imported datasets directly' : 'Submit imported datasets for review'))

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
    await downloadTable({ columns: data.columns, rows: data.rows, filename: `${site.value.slug}-template-${fromUuid.value.slice(-8)}`, title: site.value.title })
  } catch (e) { ui.error(errorMessage(e)) } finally { dl.value = null }
}

async function read(f) {
  const one = Array.isArray(f) ? f[0] : f
  table.value = null
  result.value = null
  if (!one) return
  try {
    table.value = await readTable(one)
    if (!table.value.rows.length) { ui.error('The file contains no data rows'); table.value = null; return }
    mapping.value = autoMapping(table.value.headers, columns.value)
  } catch (e) {
    ui.error(`Unable to read file: ${e.message}`)
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
