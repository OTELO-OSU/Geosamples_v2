<template>
  <div class="gs-page">
    <v-btn variant="text" prepend-icon="mdi-arrow-left" class="mb-4 ml-n3" @click="back">Retour à la carte</v-btn>
    <v-progress-linear v-if="!ds && !error" indeterminate color="primary" />
    <v-alert v-else-if="error" type="warning" variant="tonal">{{ error }}</v-alert>
    <DatasetDetail v-else :ds="ds" :site="site">
      <template #actions><DatasetActions :ds="ds" :site="site" @changed="(d) => (ds = d)" @deleted="router.push('/espace')" /></template>
    </DatasetDetail>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import api, { errorMessage } from '@/api/client'
import { useSiteStore } from '@/stores/site'
import DatasetDetail from '@/components/dataset/DatasetDetail.vue'
import DatasetActions from '@/components/dataset/DatasetActions.vue'

const props = defineProps({ uuid: String })
const router = useRouter()
const site = computed(() => useSiteStore().site)
const ds = ref(null)
const error = ref('')

watch(() => props.uuid, async (u) => {
  ds.value = null
  error.value = ''
  try {
    ds.value = (await api.get(`/api/sites/${site.value.slug}/datasets/${u}`)).data
    document.title = `${ds.value.title} · ${site.value.title}`
  } catch (e) {
    error.value = e.response?.status === 404 ? 'Ce jeu de données n\'existe pas ou n\'est pas encore publié.' : errorMessage(e)
  }
}, { immediate: true })

function back() {
  if (window.history.state?.back) router.back()
  else router.push({ path: '/', query: { jeu: props.uuid } })
}
</script>
