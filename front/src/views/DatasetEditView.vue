<template>
  <div class="gs-page gs-edit">
    <v-progress-linear v-if="loading" indeterminate color="primary" />
    <template v-else>
      <header class="mb-6">
        <h1>{{ heading }}</h1>
        <p v-if="!uuid" class="text-muted mt-2">
          Pour déposer plusieurs jeux d'un coup, utilisez l'<router-link to="/importer">import depuis un tableur</router-link>.
        </p>
        <v-alert v-if="ds?.status === 'published' && site.settings.moderation && !site.me.canManage" type="info" variant="tonal" density="compact" class="mt-3">
          Ce jeu est publié : vos modifications devront être validées par un responsable avant d'apparaître sur la carte.
        </v-alert>
      </header>

      <v-form @submit.prevent>
        <section class="gs-sec">
          <h2>Description</h2>
          <v-text-field v-model="form.title" label="Titre *" :error-messages="errors.title" counter="500" />
          <v-textarea v-model="form.description" label="Description" auto-grow rows="4" hint="Contexte, protocole, contenu des fichiers…" persistent-hint />
          <v-combobox
            v-model="form.keywords" :items="keywordSuggestions" :label="`Mots-clés${site.settings.requireKeywords ? ' *' : ''}`" multiple chips closable-chips
            hint="Validez chaque mot-clé avec Entrée" persistent-hint :error-messages="errors.keywords" class="mt-2"
          />
          <h3 class="mt-6 mb-2">Auteurs</h3>
          <div v-for="(a, i) in form.authors" :key="i" class="gs-author">
            <v-text-field v-model="a.name" label="Nom" density="compact" hide-details />
            <v-text-field v-model="a.affiliation" label="Affiliation" density="compact" hide-details />
            <v-text-field v-model="a.orcid" label="ORCID" density="compact" placeholder="0000-0000-0000-0000" :error-messages="errors[`authors[${i}].orcid`]" :hide-details="!errors[`authors[${i}].orcid`]" />
            <v-btn icon="mdi-close" variant="text" size="small" aria-label="Retirer l'auteur" @click="form.authors.splice(i, 1)" />
          </div>
          <v-btn variant="text" size="small" prepend-icon="mdi-plus" @click="form.authors.push({ name: '', affiliation: '', orcid: '' })">Ajouter un auteur</v-btn>
          <div class="gs-grid mt-4">
            <v-select v-model="form.license" :items="LICENSES" label="Licence" />
            <v-select v-model="form.accessRight" :items="accessItems" label="Accès aux fichiers" />
            <v-select v-model="form.language" :items="LANGUAGES" label="Langue des métadonnées" clearable />
          </div>
        </section>

        <section v-for="g in groups" :key="g.name" class="gs-sec">
          <h2>{{ g.name }}</h2>
          <div class="gs-grid">
            <FieldInput
              v-for="f in g.fields" :key="f.key" v-model="form.properties[f.key]" :field="f"
              :error="errors[`properties.${f.key}`]" :class="{ 'gs-span-2': f.type === 'textarea' }"
            />
          </div>
        </section>

        <section class="gs-sec">
          <h2>Localisation et dates</h2>
          <div class="gs-grid mb-2">
            <v-text-field v-model="form.temporalStart" label="Début de la période couverte" placeholder="2021, 2021-06 ou 2021-06-15" :error-messages="errors.temporalStart" hint="Laissez vide pour la déduire des dates des points" persistent-hint />
            <v-text-field v-model="form.temporalEnd" label="Fin de la période" placeholder="facultatif" :error-messages="errors.temporalEnd" />
          </div>
          <PointsEditor v-model="form.points" :site="site" :errors="errors" class="mt-4" />
        </section>

        <section class="gs-sec">
          <h2>Fichiers</h2>
          <FilesManager v-model:queue="queue" :existing="ds?.files || []" @delete="deleteFile" />
        </section>

        <section class="gs-sec">
          <h2>Publication dans ORDaR</h2>
          <p class="text-muted mb-3">Une fois le jeu publié dans l'entrepôt ORDaR, renseignez son DOI : la fiche affichera le lien.</p>
          <div class="gs-grid">
            <v-text-field v-model="form.doi" label="DOI" placeholder="10.24396/ORDAR-…" :error-messages="errors.doi" />
            <v-text-field v-model="form.ordarUrl" label="Adresse de la fiche ORDaR" :error-messages="errors.ordarUrl" />
          </div>
        </section>

        <div class="gs-bar">
          <span v-if="errorCount" class="text-error text-body-2"><v-icon size="18">mdi-alert-circle</v-icon> {{ errorCount }} champ{{ errorCount > 1 ? 's' : '' }} à corriger</span>
          <v-spacer />
          <v-btn variant="text" @click="router.back()">Annuler</v-btn>
          <v-btn v-if="canDraft" variant="outlined" :loading="saving === 'draft'" @click="save(false)">Enregistrer le brouillon</v-btn>
          <v-btn color="primary" :loading="saving === 'submit'" @click="save(true)">{{ submitLabel }}</v-btn>
        </div>
      </v-form>
    </template>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api, { errorDetails, errorMessage } from '@/api/client'
import { useSiteStore } from '@/stores/site'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { LANGUAGES, LICENSES } from '@/utils/format'
import { fromApi, newPoint, toPayload } from '@/utils/points'
import FieldInput from '@/components/dataset/FieldInput.vue'
import PointsEditor from '@/components/dataset/PointsEditor.vue'
import FilesManager from '@/components/dataset/FilesManager.vue'

const props = defineProps({ uuid: String })
const route = useRoute()
const router = useRouter()
const ui = useUiStore()
const auth = useAuthStore()
const site = computed(() => useSiteStore().site)

const loading = ref(true)
const saving = ref(null)
const ds = ref(null)
const errors = ref({})
const queue = ref([])
const keywordSuggestions = ref([])
const form = ref(blank())
const accessItems = [{ value: 'open', title: 'Ouvert à tous' }, { value: 'restricted', title: 'Réservé aux membres du projet' }]

function blank() {
  const u = auth.user
  return {
    title: '', description: '', keywords: [], language: 'fr',
    authors: u ? [{ name: u.displayName, affiliation: u.affiliation || '', orcid: u.orcid || '' }] : [],
    license: site.value.settings.defaultLicense, accessRight: site.value.settings.defaultAccessRight,
    temporalStart: '', temporalEnd: '', doi: '', ordarUrl: '', properties: {}, points: [newPoint()],
  }
}

const dateStr = (v) => (v ? String(v).slice(0, 10) : '')

const heading = computed(() => (props.uuid ? 'Modifier le jeu de données' : route.query.from ? 'Nouveau jeu (copie)' : 'Déposer un jeu de données'))
const groups = computed(() => {
  const out = []
  for (const f of site.value.fields.filter((x) => (x.scope || 'dataset') === 'dataset')) {
    const name = f.group || 'Informations du projet'
    let g = out.find((x) => x.name === name)
    if (!g) out.push((g = { name, fields: [] }))
    g.fields.push(f)
  }
  return out
})
const willPublish = computed(() => !site.value.settings.moderation || site.value.me.canManage)
const canDraft = computed(() => !ds.value || ds.value.status !== 'published')
const submitLabel = computed(() => {
  if (ds.value?.status === 'published') return 'Enregistrer'
  return willPublish.value ? 'Publier' : 'Soumettre à validation'
})
const errorCount = computed(() => Object.keys(errors.value).length)

onMounted(async () => {
  try {
    const src = props.uuid || route.query.from
    if (src) {
      const d = (await api.get(`/api/sites/${site.value.slug}/datasets/${src}`)).data
      if (props.uuid) ds.value = d
      const copy = !props.uuid
      form.value = {
        title: copy ? `${d.title} (copie)` : d.title,
        description: d.description || '', keywords: [...d.keywords], language: d.language || null,
        authors: d.authors.map((a) => ({ name: a.name, affiliation: a.affiliation || '', orcid: a.orcid || '' })),
        license: d.license, accessRight: d.accessRight,
        temporalStart: dateStr(d.temporalStart), temporalEnd: dateStr(d.temporalEnd),
        doi: copy ? '' : d.doi || '', ordarUrl: copy ? '' : d.ordarUrl || '',
        properties: { ...d.properties },
        points: d.points.map((p) => fromApi(p, !copy)),
      }
      if (!form.value.points.length) form.value.points.push(newPoint())
    }
    const f = (await api.get(`/api/sites/${site.value.slug}/facets`)).data
    keywordSuggestions.value = f.keywords.map((k) => k.value)
  } catch (e) {
    ui.error(errorMessage(e))
  } finally {
    loading.value = false
  }
})

async function save(submit) {
  saving.value = submit ? 'submit' : 'draft'
  errors.value = {}
  const payload = { ...form.value, points: form.value.points.map(toPayload), submit }
  try {
    const url = `/api/sites/${site.value.slug}/datasets`
    const { data } = props.uuid ? await api.put(`${url}/${props.uuid}`, payload) : await api.post(url, payload)
    if (queue.value.length) {
      for (const kind of ['data', 'picture', 'document']) {
        const files = queue.value.filter((q) => q.kind === kind)
        if (!files.length) continue
        const fd = new FormData()
        files.forEach((q) => fd.append('files[]', q.file))
        fd.append('kind', kind)
        await api.post(`${url}/${data.uuid}/files`, fd)
      }
      queue.value = []
    }
    ui.notify(data.status === 'published' ? 'Jeu publié' : data.status === 'submitted' ? 'Jeu soumis à validation' : 'Brouillon enregistré')
    router.push(`/jeux/${data.uuid}`)
  } catch (e) {
    errors.value = errorDetails(e)
    ui.error(errorMessage(e))
    await nextTick()
    document.querySelector('.v-input--error, .v-expansion-panel-title .text-error, .gs-sec .text-error')?.scrollIntoView({ behavior: 'smooth', block: 'center' })
  } finally {
    saving.value = null
  }
}

async function deleteFile(f) {
  try {
    await api.delete(`/api/sites/${site.value.slug}/datasets/${props.uuid}/files/${f.id}`)
    ds.value.files = ds.value.files.filter((x) => x.id !== f.id)
    ui.notify('Fichier supprimé')
  } catch (e) {
    ui.error(errorMessage(e))
  }
}
</script>

<style scoped>
.gs-edit { max-width: 900px; }
.gs-sec { padding: 24px 0; border-top: 1px solid rgb(var(--v-theme-line)); }
.gs-sec h2 { margin-bottom: 16px; }
.gs-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 16px; }
.gs-span-2 { grid-column: span 2; }
.gs-author { display: grid; grid-template-columns: 1.2fr 1.2fr 1fr auto; gap: 8px; align-items: start; margin-bottom: 8px; }
.gs-bar {
  position: sticky; bottom: 0; z-index: 5; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
  padding: 12px 0; background: rgb(var(--v-theme-background)); border-top: 1px solid rgb(var(--v-theme-line));
}
@media (max-width: 640px) {
  .gs-grid, .gs-author { grid-template-columns: 1fr; }
  .gs-span-2 { grid-column: auto; }
}
</style>
