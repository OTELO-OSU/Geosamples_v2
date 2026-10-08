<template>
  <div>
    <p class="text-muted mb-4 gs-prose">
      Les champs communs (titre, description, mots-clés, auteurs, licence, coordonnées, altitude, dates) existent
      sur tous les sites. Ajoutez ici les champs propres à ce projet : ils apparaissent dans le formulaire,
      le template d'import, la fiche et, si vous le souhaitez, dans les filtres de la carte.
    </p>
    <v-expansion-panels v-model="open" variant="accordion">
      <v-expansion-panel v-for="(f, i) in modelValue" :key="f._k || f.key || i" :value="i">
        <v-expansion-panel-title>
          <div class="gs-ftitle">
            <strong>{{ f.label || 'Nouveau champ' }}</strong>
            <span class="text-muted">{{ f.key }} · {{ typeLabel(f.type) }}{{ f.scope === 'point' ? ' · par point' : '' }}{{ f.filter ? ' · filtrable' : '' }}{{ f.required ? ' · obligatoire' : '' }}</span>
          </div>
          <v-icon v-if="hasErr(i)" color="error" size="18" class="ml-2">mdi-alert-circle</v-icon>
        </v-expansion-panel-title>
        <v-expansion-panel-text>
          <div class="gs-grid">
            <v-text-field v-model="f.label" label="Libellé *" @update:model-value="autoKey(f)" />
            <v-text-field v-model="f.key" label="Clé (colonne d'import) *" hint="minuscules, chiffres et _ ; ne plus modifier une fois des données saisies" persistent-hint :error-messages="errors[`fields[${i}].key`]" />
            <v-select v-model="f.type" :items="TYPES" label="Type" :error-messages="errors[`fields[${i}].type`]" />
            <v-select v-model="f.scope" :items="SCOPES" label="Niveau" hint="« Par point » pour une valeur mesurée à chaque point / date" persistent-hint />
            <v-combobox
              v-if="['select', 'multiselect'].includes(f.type)" v-model="f.options" label="Valeurs autorisées *" multiple chips closable-chips
              class="gs-span-2" hint="Entrée après chaque valeur" persistent-hint :error-messages="errors[`fields[${i}].options`]"
            />
            <v-text-field v-if="['number', 'integer'].includes(f.type)" v-model="f.unit" label="Unité" placeholder="ppm, °C, m…" />
            <v-text-field v-model="f.group" label="Rubrique du formulaire" placeholder="ex. Géologie, Mesures" />
            <v-text-field v-model="f.help" label="Aide à la saisie" class="gs-span-2" />
            <v-select v-model="f.filter" :items="filterItems(f)" label="Filtre sur la carte" clearable />
            <div class="d-flex flex-wrap ga-4 align-center">
              <v-switch v-model="f.required" label="Obligatoire" color="primary" hide-details inset />
              <v-switch v-model="f.popup" label="Mis en avant" color="primary" hide-details inset />
            </div>
          </div>
          <div class="d-flex mt-2">
            <v-btn variant="text" size="small" prepend-icon="mdi-arrow-up" :disabled="i === 0" @click="move(i, -1)">Monter</v-btn>
            <v-btn variant="text" size="small" prepend-icon="mdi-arrow-down" :disabled="i === modelValue.length - 1" @click="move(i, 1)">Descendre</v-btn>
            <v-spacer />
            <v-btn variant="text" size="small" color="error" prepend-icon="mdi-delete-outline" @click="modelValue.splice(i, 1)">Supprimer le champ</v-btn>
          </div>
        </v-expansion-panel-text>
      </v-expansion-panel>
    </v-expansion-panels>
    <v-btn class="mt-4" variant="tonal" prepend-icon="mdi-plus" @click="add">Ajouter un champ</v-btn>
    <v-alert type="info" variant="tonal" density="compact" class="mt-4">
      Supprimer un champ ne supprime pas les valeurs déjà saisies : elles cessent seulement d'être affichées.
    </v-alert>
  </div>
</template>

<script setup>
import { ref } from 'vue'

const props = defineProps({ modelValue: Array, errors: { type: Object, default: () => ({}) } })
const open = ref(null)
const TYPES = [
  { value: 'text', title: 'Texte court' }, { value: 'textarea', title: 'Texte long' }, { value: 'number', title: 'Nombre décimal' },
  { value: 'integer', title: 'Nombre entier' }, { value: 'date', title: 'Date' }, { value: 'select', title: 'Liste (un choix)' },
  { value: 'multiselect', title: 'Liste (plusieurs choix)' }, { value: 'boolean', title: 'Oui / non' }, { value: 'url', title: 'Lien web' },
]
const SCOPES = [{ value: 'dataset', title: 'Jeu de données' }, { value: 'point', title: 'Par point' }]
const typeLabel = (t) => TYPES.find((x) => x.value === t)?.title || t
const hasErr = (i) => Object.keys(props.errors).some((k) => k.startsWith(`fields[${i}]`))

function filterItems(f) {
  if (['select', 'multiselect', 'boolean'].includes(f.type)) return [{ value: 'select', title: 'Choix parmi les valeurs' }]
  if (['number', 'integer'].includes(f.type)) return [{ value: 'range', title: 'Intervalle min. / max.' }]
  return [{ value: 'text', title: 'Recherche de texte' }, { value: 'select', title: 'Choix parmi les valeurs' }]
}
function slug(s) {
  return s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').replace(/^(\d)/, 'f_$1').slice(0, 60)
}
function autoKey(f) { if (f._new) f.key = slug(f.label || '') }
function add() {
  props.modelValue.push({ _k: `k${Date.now()}`, _new: true, key: '', label: '', type: 'text', scope: 'dataset', options: [], required: false, filter: null, popup: false, unit: '', group: '', help: '' })
  open.value = props.modelValue.length - 1
}
function move(i, d) {
  const [x] = props.modelValue.splice(i, 1)
  props.modelValue.splice(i + d, 0, x)
  open.value = i + d
}
</script>

<style scoped>
.gs-ftitle { display: flex; flex-direction: column; gap: 2px; }
.gs-ftitle .text-muted { font-size: 0.8rem; }
.gs-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 16px; }
.gs-span-2 { grid-column: span 2; }
@media (max-width: 640px) { .gs-grid { grid-template-columns: 1fr; } .gs-span-2 { grid-column: auto; } }
</style>
