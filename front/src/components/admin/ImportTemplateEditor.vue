<template>
  <div>
    <p class="text-muted mb-4 gs-prose">
      Choisissez les colonnes proposées dans le template d'import et leur libellé. Les colonnes obligatoires et les coordonnées sont toujours présentes.
      Les fichiers déjà remplis restent importables : les colonnes sont reconnues par leur clé ou leur libellé.
    </p>
    <v-table density="compact" class="gs-panel">
      <thead><tr><th style="width: 70px">Inclure</th><th>Clé</th><th>Libellé dans le template</th><th>Exemple</th></tr></thead>
      <tbody>
        <tr v-for="c in columns" :key="c.key">
          <td><v-checkbox-btn v-model="state(c).include" :disabled="locked(c)" :aria-label="`Inclure ${c.key}`" /></td>
          <td><code>{{ c.key }}</code><span v-if="c.level === 'point'" class="text-muted text-caption"> · point</span></td>
          <td><v-text-field v-model="state(c).label" :placeholder="c.defaultLabel" density="compact" hide-details variant="plain" /></td>
          <td><v-text-field v-model="state(c).example" density="compact" hide-details variant="plain" placeholder="—" /></td>
        </tr>
      </tbody>
    </v-table>
    <v-textarea v-model="tpl.instructions" class="mt-6" label="Consignes affichées sur la page d'import et dans la feuille d'aide" rows="4" hint="Markdown accepté" persistent-hint />
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import api from '@/api/client'

const props = defineProps({ slug: String })
const tpl = defineModel({ type: Object, required: true })
const columns = ref([])

onMounted(async () => {
  tpl.value.columns = tpl.value.columns && !Array.isArray(tpl.value.columns) ? tpl.value.columns : {}
  const { data } = await api.get(`/api/sites/${props.slug}/import/columns`)
  columns.value = data.columns.map((c) => ({ ...c, defaultLabel: tpl.value.columns[c.key]?.label ? c.key : c.label }))
})

const locked = (c) => c.required || ['longitude', 'latitude'].includes(c.key)
function state(c) {
  if (!tpl.value.columns[c.key]) tpl.value.columns[c.key] = { include: c.include, label: '', example: c.example || '' }
  return tpl.value.columns[c.key]
}
</script>
