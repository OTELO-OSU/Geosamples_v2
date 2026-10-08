<template>
  <div class="gs-page">
    <header class="gs-portal__head">
      <h1>Sites de valorisation des données OTELo</h1>
      <p class="text-muted mt-2 gs-prose">
        Chaque site présente sur une carte les échantillons et jeux de données d'un projet de recherche,
        avant ou en complément de leur publication dans l'entrepôt ORDaR.
      </p>
      <v-btn v-if="auth.isAdmin" to="/admin" variant="outlined" prepend-icon="mdi-plus" class="mt-4">Créer un site</v-btn>
    </header>
    <v-progress-linear v-if="loading" indeterminate color="primary" class="mt-6" />
    <p v-else-if="!sites.length" class="mt-8 text-muted">Aucun site n'est encore publié.</p>
    <ul v-else class="gs-portal__list">
      <li v-for="s in sites" :key="s.slug">
        <a :href="href(s)" class="gs-site" :style="{ '--c': s.theme.primary }">
          <span class="gs-site__band" />
          <span class="gs-site__body">
            <span class="gs-site__title">
              <img v-if="s.logoUrl" :src="s.logoUrl" alt="" />
              {{ s.title }}
              <v-chip v-if="!s.published" size="x-small" class="ml-2">non publié</v-chip>
            </span>
            <span v-if="s.subtitle" class="gs-site__sub">{{ s.subtitle }}</span>
            <span class="gs-site__count"><strong>{{ s.datasets.toLocaleString('fr-FR') }}</strong> jeu{{ s.datasets > 1 ? 'x' : '' }} de données publié{{ s.datasets > 1 ? 's' : '' }}</span>
          </span>
        </a>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import api from '@/api/client'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const sites = ref([])
const loading = ref(true)
onMounted(async () => {
  try { sites.value = (await api.get('/api/sites')).data } finally { loading.value = false }
})
// Lien par le nom d'hôte du site s'il en a un (hors développement local), sinon /s/<slug>/
function href(s) {
  const host = s.hostnames?.find((h) => !/localhost|127\.0\.0\.1/.test(h))
  const local = /localhost|127\.0\.0\.1/.test(location.hostname)
  return host && !local ? `${location.protocol}//${host}/` : `/s/${s.slug}/`
}
</script>

<style scoped>
.gs-portal__head { max-width: 760px; }
.gs-portal__list { list-style: none; padding: 0; margin: 36px 0 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px; }
.gs-site { display: flex; text-decoration: none; color: inherit; background: rgb(var(--v-theme-surface)); border: 1px solid rgb(var(--v-theme-line)); border-radius: 10px; overflow: hidden; height: 100%; }
.gs-site:hover, .gs-site:focus-visible { border-color: var(--c); }
.gs-site__band { width: 10px; background: var(--c); flex: none; }
.gs-site__body { display: flex; flex-direction: column; gap: 4px; padding: 18px 20px; }
.gs-site__title { font-family: var(--gs-font-display); font-weight: 700; font-size: 1.3rem; display: flex; align-items: center; gap: 10px; }
.gs-site__title img { height: 28px; }
.gs-site__sub { color: rgb(var(--v-theme-muted)); font-size: 0.92rem; }
.gs-site__count { margin-top: 10px; font-size: 0.88rem; }
.gs-site__count strong { font-family: var(--gs-font-display); font-size: 1.1rem; }
</style>
