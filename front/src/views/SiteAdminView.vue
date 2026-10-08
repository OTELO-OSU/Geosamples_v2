<template>
  <div class="gs-page">
    <h1>Administration du site</h1>
    <p class="text-muted mt-1">{{ site.title }}<template v-if="site.hostnames?.length"> — {{ site.hostnames[0] }}</template></p>

    <div class="gs-admin mt-6">
      <v-tabs v-model="tab" direction="vertical" color="primary" class="gs-admin__nav">
        <v-tab value="presentation" prepend-icon="mdi-text-box-outline">Présentation</v-tab>
        <v-tab value="map" prepend-icon="mdi-map-outline">Carte</v-tab>
        <v-tab value="fields" prepend-icon="mdi-form-textbox">Champs</v-tab>
        <v-tab value="import" prepend-icon="mdi-table-cog">Template d'import</v-tab>
        <v-tab value="members" prepend-icon="mdi-account-group-outline">Membres</v-tab>
        <v-tab value="settings" prepend-icon="mdi-tune">Règles</v-tab>
      </v-tabs>

      <div class="gs-admin__body">
        <template v-if="tab === 'presentation'">
          <v-text-field v-model="cfg.title" label="Nom du site *" :error-messages="errors.title" />
          <v-text-field v-model="cfg.subtitle" label="Sous-titre" />
          <v-textarea v-model="cfg.description" label="Présentation courte (affichée à côté de la carte)" rows="4" hint="Markdown accepté" persistent-hint />
          <v-textarea v-model="cfg.about" label="Page « À propos »" rows="10" class="mt-4" hint="Markdown accepté : contexte du projet, partenaires, financements, contacts…" persistent-hint />
          <h3 class="mt-6 mb-2">Couleurs</h3>
          <div class="d-flex flex-wrap ga-6">
            <label v-for="k in ['primary', 'secondary', 'accent']" :key="k" class="gs-color">
              <input v-model="cfg.theme[k]" type="color" />
              <span>{{ { primary: 'Principale', secondary: 'Secondaire', accent: 'Accent' }[k] }}<br /><code>{{ cfg.theme[k] }}</code></span>
            </label>
          </div>
          <h3 class="mt-6 mb-2">Logo</h3>
          <div class="d-flex align-center ga-4">
            <img v-if="site.logoUrl" :src="site.logoUrl" alt="Logo actuel" style="height: 48px" />
            <v-file-input label="PNG, JPEG ou WebP, 2 Mo max." accept="image/png,image/jpeg,image/webp" density="compact" hide-details prepend-icon="" prepend-inner-icon="mdi-image-outline" style="max-width: 360px" @update:model-value="uploadLogo" />
          </div>
        </template>

        <template v-if="tab === 'map'">
          <p class="text-muted mb-3">Déplacez la carte pour définir la vue affichée à l'ouverture du site.</p>
          <div ref="viewEl" class="gs-viewmap" />
          <p class="text-body-2 mt-2">Centre {{ cfg.map.center.map((v) => v.toFixed(4)).join(', ') }} · zoom {{ cfg.map.zoom }}</p>
          <h3 class="mt-6 mb-2">Fonds de carte proposés</h3>
          <div class="d-flex flex-wrap ga-x-6">
            <v-checkbox v-for="(b, k) in BASEMAPS" :key="k" v-model="cfg.map.basemaps" :value="k" :label="b.label" hide-details density="compact" />
          </div>
          <div class="gs-grid mt-4">
            <v-select v-model="cfg.map.defaultBasemap" :items="basemapItems" label="Fond par défaut" />
            <v-select v-model="cfg.map.colorBy" :items="colorItems" label="Couleur des points selon" />
            <v-text-field v-model.number="cfg.map.clusterMaxZoom" type="number" min="5" max="20" label="Zoom à partir duquel tous les points sont affichés" hint="En dessous, les points sont regroupés par le serveur quand ils sont nombreux" persistent-hint />
          </div>
        </template>

        <FieldsEditor v-if="tab === 'fields'" v-model="cfg.fields" :errors="errors" />
        <ImportTemplateEditor v-if="tab === 'import'" v-model="cfg.importTemplate" :slug="site.slug" />
        <MembersManager v-if="tab === 'members'" :slug="site.slug" />

        <template v-if="tab === 'settings'">
          <v-switch v-model="cfg.settings.moderation" color="primary" inset label="Les dépôts des utilisateurs sont validés par un responsable avant publication" />
          <v-switch v-model="cfg.settings.allowJoinRequests" color="primary" inset label="Les personnes connectées peuvent demander à contribuer" />
          <v-switch v-model="cfg.settings.requireKeywords" color="primary" inset label="Au moins un mot-clé est obligatoire" />
          <div class="gs-grid mt-2">
            <v-select v-model="cfg.settings.defaultAccessRight" :items="[{ value: 'open', title: 'Ouvert à tous' }, { value: 'restricted', title: 'Réservé aux membres' }]" label="Accès aux fichiers par défaut" />
            <v-select v-model="cfg.settings.defaultLicense" :items="LICENSES" label="Licence par défaut" />
            <v-text-field v-model="cfg.settings.verticalDatum" label="Référentiel altimétrique par défaut" placeholder="NGF-IGN69, EGM2008…" />
            <v-text-field v-model="cfg.settings.contactEmail" label="E-mail de contact" :error-messages="errors['settings.contactEmail']" />
            <v-text-field v-model="cfg.settings.ordarUrl" label="Adresse de l'entrepôt ORDaR" class="gs-span-2" />
          </div>
          <template v-if="auth.isAdmin">
            <h3 class="mt-6 mb-2">Réservé aux administrateurs</h3>
            <v-combobox v-model="cfg.hostnames" label="Noms d'hôte servant ce site" multiple chips closable-chips hint="ex. nometal.otelo.univ-lorraine.fr — à déclarer aussi dans le reverse proxy" persistent-hint :error-messages="errors.hostnames" />
            <v-switch v-model="cfg.published" color="primary" inset label="Site visible publiquement" class="mt-2" />
          </template>
        </template>

        <div v-if="tab !== 'members'" class="gs-bar">
          <v-spacer />
          <v-btn color="primary" :loading="saving" @click="save">Enregistrer</v-btn>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import L from 'leaflet'
import api, { errorDetails, errorMessage } from '@/api/client'
import { applySiteTheme } from '@/plugins/vuetify'
import { useTheme } from 'vuetify'
import { useSiteStore } from '@/stores/site'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { BASEMAPS } from '@/utils/basemaps'
import { LICENSES } from '@/utils/format'
import FieldsEditor from '@/components/admin/FieldsEditor.vue'
import MembersManager from '@/components/admin/MembersManager.vue'
import ImportTemplateEditor from '@/components/admin/ImportTemplateEditor.vue'

const siteStore = useSiteStore()
const auth = useAuthStore()
const ui = useUiStore()
const vtheme = useTheme()
const site = computed(() => siteStore.site)
const tab = ref('presentation')
const saving = ref(false)
const errors = ref({})
const viewEl = ref(null)
let viewMap = null

const clone = (s) => JSON.parse(JSON.stringify({
  title: s.title, subtitle: s.subtitle || '', description: s.description || '', about: s.about || '',
  theme: s.theme, map: s.map, fields: s.fields, importTemplate: { columns: {}, instructions: '', ...s.importTemplate },
  settings: s.settings, hostnames: s.hostnames, published: s.published,
}))
const cfg = ref(clone(site.value))

const basemapItems = computed(() => cfg.value.map.basemaps.map((k) => ({ value: k, title: BASEMAPS[k]?.label || k })))
const colorItems = computed(() => [
  { value: 'none', title: 'Couleur unique' }, { value: 'elevation', title: 'Altitude' }, { value: 'year', title: 'Année' },
  ...cfg.value.fields.filter((f) => ['select', 'number', 'integer', 'boolean'].includes(f.type)).map((f) => ({ value: f.key, title: f.label })),
])

watch(tab, async (t) => {
  viewMap?.remove()
  viewMap = null
  if (t !== 'map') return
  await nextTick()
  viewMap = L.map(viewEl.value)
  const b = BASEMAPS[cfg.value.map.defaultBasemap] || BASEMAPS.osm
  L.tileLayer(b.url, { attribution: b.attribution, maxZoom: b.maxZoom, subdomains: 'abc' }).addTo(viewMap)
  viewMap.setView(cfg.value.map.center, cfg.value.map.zoom)
  viewMap.on('moveend', () => {
    const c = viewMap.getCenter()
    cfg.value.map.center = [+c.lat.toFixed(5), +c.lng.toFixed(5)]
    cfg.value.map.zoom = viewMap.getZoom()
  })
})
onBeforeUnmount(() => viewMap?.remove())

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const { data } = await api.put(`/api/sites/${site.value.slug}`, cfg.value)
    siteStore.site = data
    cfg.value = clone(data)
    applySiteTheme(vtheme, data.theme)
    ui.notify('Configuration enregistrée')
  } catch (e) {
    errors.value = errorDetails(e)
    ui.error(errorMessage(e))
  } finally {
    saving.value = false
  }
}

async function uploadLogo(f) {
  const file = Array.isArray(f) ? f[0] : f
  if (!file) return
  const fd = new FormData()
  fd.append('file', file)
  try {
    siteStore.site = (await api.post(`/api/sites/${site.value.slug}/logo`, fd)).data
    ui.notify('Logo mis à jour')
  } catch (e) {
    ui.error(errorMessage(e))
  }
}
</script>

<style scoped>
.gs-admin { display: grid; grid-template-columns: 220px 1fr; gap: 32px; }
.gs-admin__nav { position: sticky; top: 80px; align-self: start; }
.gs-admin__body { min-width: 0; }
.gs-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 16px; }
.gs-span-2 { grid-column: span 2; }
.gs-viewmap { height: 360px; border-radius: 8px; border: 1px solid rgb(var(--v-theme-line)); z-index: 0; }
.gs-color { display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 0.9rem; }
.gs-color input { width: 44px; height: 44px; border: 1px solid rgb(var(--v-theme-line)); border-radius: 8px; padding: 2px; background: none; cursor: pointer; }
.gs-bar { position: sticky; bottom: 0; display: flex; padding: 12px 0; margin-top: 24px; background: rgb(var(--v-theme-background)); border-top: 1px solid rgb(var(--v-theme-line)); }
@media (max-width: 860px) {
  .gs-admin { grid-template-columns: 1fr; }
  .gs-admin__nav { position: static; }
  .gs-grid { grid-template-columns: 1fr; } .gs-span-2 { grid-column: auto; }
}
</style>
