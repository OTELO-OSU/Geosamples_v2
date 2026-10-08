<template>
  <div>
    <v-list v-if="existing.length || queue.length" density="compact" class="bg-transparent mb-2">
      <v-list-item v-for="f in existing" :key="'e' + f.id" :title="f.name" :subtitle="`${kindLabel(f.kind)} · ${fmtBytes(f.size)}`" :prepend-icon="f.kind === 'picture' ? 'mdi-image-outline' : 'mdi-file-outline'">
        <template #append><v-btn icon="mdi-delete-outline" variant="text" size="small" :aria-label="`Supprimer ${f.name}`" @click="$emit('delete', f)" /></template>
      </v-list-item>
      <v-list-item v-for="(f, i) in queue" :key="'q' + i" :title="f.file.name" :subtitle="`${kindLabel(f.kind)} · ${fmtBytes(f.file.size)} · sera envoyé à l'enregistrement`" prepend-icon="mdi-upload-outline">
        <template #append>
          <v-select v-model="f.kind" :items="kinds" density="compact" hide-details style="width: 150px" variant="plain" aria-label="Type de fichier" />
          <v-btn icon="mdi-close" variant="text" size="small" aria-label="Retirer" @click="queue.splice(i, 1)" />
        </template>
      </v-list-item>
    </v-list>
    <label class="gs-drop" :class="{ 'gs-drop--over': over }" @dragover.prevent="over = true" @dragleave="over = false" @drop.prevent="drop">
      <input type="file" multiple class="gs-drop__input" @change="(e) => addFiles(e.target.files)" />
      <v-icon size="28" color="primary">mdi-tray-arrow-up</v-icon>
      <span><strong>Ajouter des fichiers</strong> — données brutes, photos, documents (glisser-déposer possible)</span>
    </label>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { fmtBytes } from '@/utils/format'

defineProps({ existing: { type: Array, default: () => [] } })
defineEmits(['delete'])
const queue = defineModel('queue', { type: Array, default: () => [] })
const over = ref(false)
const kinds = [{ value: 'data', title: 'Données' }, { value: 'picture', title: 'Photo' }, { value: 'document', title: 'Document' }]
const kindLabel = (k) => kinds.find((x) => x.value === k)?.title || k

function addFiles(list) {
  for (const file of list) queue.value.push({ file, kind: /^image\//.test(file.type) ? 'picture' : 'data' })
}
function drop(e) { over.value = false; addFiles(e.dataTransfer.files) }
</script>

<style scoped>
.gs-drop { display: flex; align-items: center; gap: 12px; padding: 18px; border: 2px dashed rgb(var(--v-theme-line)); border-radius: 10px; cursor: pointer; position: relative; }
.gs-drop--over, .gs-drop:hover { border-color: rgb(var(--v-theme-primary)); }
.gs-drop__input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
.gs-drop:focus-within { outline: 2px solid rgb(var(--v-theme-primary)); }
</style>
