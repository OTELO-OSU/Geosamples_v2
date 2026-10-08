<template>
  <div class="gs-page gs-page--narrow">
    <h1>{{ site.title }}</h1>
    <p v-if="site.subtitle" class="text-muted mt-1 text-h6 font-weight-regular">{{ site.subtitle }}</p>
    <div class="gs-prose mt-6" v-html="markdown(site.about || site.description || '')" />
    <p v-if="site.settings.contactEmail" class="mt-8">Contact : <a :href="`mailto:${site.settings.contactEmail}`">{{ site.settings.contactEmail }}</a></p>
    <p class="text-muted text-body-2 mt-8">
      Les jeux de données présentés ici sont en cours de valorisation ; une fois publiés dans l'entrepôt
      <a :href="site.settings.ordarUrl || 'https://ordar.otelo.univ-lorraine.fr'" target="_blank" rel="noopener">ORDaR</a>, ils reçoivent un DOI.
    </p>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useSiteStore } from '@/stores/site'
import { markdown } from '@/utils/format'
const site = computed(() => useSiteStore().site)
</script>
