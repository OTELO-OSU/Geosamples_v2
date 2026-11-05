<template>
  <template v-if="ds.can.edit">
    <v-btn color="primary" prepend-icon="mdi-pencil-outline" :to="`/jeux/${ds.uuid}/modifier`" :size="size">Modifier</v-btn>
    <v-btn v-if="['draft', 'rejected'].includes(ds.status)" variant="tonal" color="primary" prepend-icon="mdi-send-outline" :size="size" :loading="busy" @click="status('submit')">
      {{ site.settings.moderation && !ds.can.review ? 'Submit for review' : 'Publish' }}
    </v-btn>
  </template>
  <template v-if="ds.can.review">
    <v-btn v-if="ds.status === 'submitted'" color="success" prepend-icon="mdi-check" :size="size" :loading="busy" @click="status('publish')">Valider et publier</v-btn>
    <v-btn v-if="ds.status === 'submitted'" variant="tonal" color="error" prepend-icon="mdi-close" :size="size" @click="rejectDialog = true">Refuser</v-btn>
    <v-btn v-if="ds.status === 'published'" variant="text" prepend-icon="mdi-eye-off-outline" :size="size" :loading="busy" @click="status('unpublish')">Unpublish</v-btn>
  </template>
  <v-menu>
    <template #activator="{ props }">
      <v-btn variant="text" v-bind="props" icon="mdi-dots-horizontal" :size="size" aria-label="Autres actions" />
    </template>
    <v-list density="compact">
      <v-list-item v-if="site.me.canContribute" prepend-icon="mdi-content-copy" title="Dupliquer dans le formulaire" :to="{ path: '/deposer', query: { from: ds.uuid } }" />
      <v-list-item prepend-icon="mdi-microsoft-excel" title="Exporter en tableur" @click="exportTable(false)" />
      <v-list-item v-if="site.me.canContribute" prepend-icon="mdi-table-plus" title="Import template based on this dataset" @click="exportTable(true)" />
      <v-list-item prepend-icon="mdi-link-variant" title="Copier le lien" @click="copyLink" />
      <v-list-item v-if="ds.can.delete" prepend-icon="mdi-delete-outline" base-color="error" title="Supprimer" @click="deleteDialog = true" />
    </v-list>
  </v-menu>

  <v-dialog v-model="rejectDialog" max-width="520">
    <v-card title="Reject this dataset">
      <v-card-text>
        <p class="mb-3">The submitter will receive your comment and can correct the dataset.</p>
        <v-textarea v-model="comment" label="What needs to be corrected" rows="4" autofocus />
      </v-card-text>
      <v-card-actions><v-spacer /><v-btn variant="text" @click="rejectDialog = false">Annuler</v-btn><v-btn color="error" :disabled="!comment.trim()" :loading="busy" @click="status('reject')">Refuser</v-btn></v-card-actions>
    </v-card>
  </v-dialog>
  <v-dialog v-model="deleteDialog" max-width="480">
    <v-card title="Delete this dataset?">
      <v-card-text>“{{ ds.title }}”, its points, and its files will be permanently deleted.</v-card-text>
      <v-card-actions><v-spacer /><v-btn variant="text" @click="deleteDialog = false">Annuler</v-btn><v-btn color="error" :loading="busy" @click="remove">Supprimer</v-btn></v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup>
import { ref } from 'vue'
import api, { errorMessage } from '@/api/client'
import { useUiStore } from '@/stores/ui'
import { useSiteStore } from '@/stores/site'
import { downloadTable } from '@/utils/spreadsheet'

const props = defineProps({ ds: Object, site: Object, size: { type: String, default: 'default' } })
const emit = defineEmits(['changed', 'deleted'])
const ui = useUiStore()
const siteStore = useSiteStore()
const busy = ref(false)
const rejectDialog = ref(false)
const deleteDialog = ref(false)
const comment = ref('')

const MESSAGES = { submit: 'Dataset submitted', publish: 'Dataset published', reject: 'Dataset rejected', unpublish: 'Dataset unpublished' }

async function status(action) {
  busy.value = true
  try {
    const { data } = await api.post(`/api/sites/${props.site.slug}/datasets/${props.ds.uuid}/status`, { action, comment: comment.value })
    ui.notify(action === 'submit' && data.status === 'published' ? 'Dataset published' : MESSAGES[action])
    rejectDialog.value = false
    emit('changed', data)
  } catch (e) {
    ui.error(errorMessage(e))
  } finally {
    busy.value = false
  }
}

async function remove() {
  busy.value = true
  try {
    await api.delete(`/api/sites/${props.site.slug}/datasets/${props.ds.uuid}`)
    ui.notify('Dataset deleted')
    emit('deleted')
  } catch (e) {
    ui.error(errorMessage(e))
  } finally {
    busy.value = false
    deleteDialog.value = false
  }
}

async function exportTable(asTemplate) {
  try {
    const { data } = await api.get(`/api/sites/${props.site.slug}/export`, { params: { ids: props.ds.uuid, template: asTemplate ? 1 : 0 } })
    await downloadTable({ columns: data.columns, rows: data.rows, filename: `${props.site.slug}-${asTemplate ? 'modele' : 'export'}-${props.ds.uuid.slice(-8)}`, title: props.site.title, instructions: props.site.importTemplate?.instructions })
  } catch (e) {
    ui.error(errorMessage(e))
  }
}

function copyLink() {
  navigator.clipboard?.writeText(`${location.origin}${siteStore.base.replace(/\/$/, '')}/jeux/${props.ds.uuid}`)
  ui.notify('Link copied')
}
</script>
