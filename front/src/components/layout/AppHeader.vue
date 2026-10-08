<template>
  <v-app-bar :height="60" flat class="gs-header" color="surface">
    <v-app-bar-nav-icon class="d-md-none" aria-label="Menu" @click="drawer = !drawer" />
    <router-link to="/" class="gs-brand">
      <img v-if="site?.logoUrl" :src="site.logoUrl" alt="" class="gs-brand__logo" />
      <span v-else class="gs-brand__mark" :style="{ background: 'rgb(var(--v-theme-primary))' }" aria-hidden="true" />
      <span class="gs-brand__text">
        <span class="gs-brand__title">{{ site?.title || 'GeoSamples' }}</span>
        <span v-if="site?.subtitle" class="gs-brand__sub d-none d-sm-block">{{ site.subtitle }}</span>
      </span>
    </router-link>

    <nav v-if="isSite" class="gs-nav d-none d-md-flex">
      <v-btn variant="text" to="/" exact>Carte</v-btn>
      <v-btn variant="text" to="/a-propos">À propos</v-btn>
    </nav>

    <v-spacer />

    <template v-if="isSite && auth.isLogged">
      <v-menu v-if="can.canContribute">
        <template #activator="{ props }">
          <v-btn color="primary" variant="flat" class="mr-2 d-none d-sm-flex" prepend-icon="mdi-plus" v-bind="props">Déposer</v-btn>
        </template>
        <v-list density="compact">
          <v-list-item to="/deposer" prepend-icon="mdi-form-select" title="Un jeu, via le formulaire" />
          <v-list-item to="/importer" prepend-icon="mdi-table-arrow-up" title="Plusieurs jeux, depuis un tableur" />
        </v-list>
      </v-menu>
      <v-btn variant="text" to="/espace" class="d-none d-md-flex">Mes données</v-btn>
      <v-btn v-if="can.canManage" variant="text" to="/administration" class="d-none d-md-flex">Administration</v-btn>
    </template>

    <v-btn icon variant="text" :aria-label="dark ? 'Thème clair' : 'Thème sombre'" @click="toggleTheme">
      <v-icon>{{ dark ? 'mdi-weather-sunny' : 'mdi-weather-night' }}</v-icon>
    </v-btn>

    <v-menu v-if="auth.isLogged">
      <template #activator="{ props }">
        <v-btn variant="text" v-bind="props" class="ml-1" :aria-label="'Compte ' + auth.user.displayName">
          <v-avatar size="30" color="secondary" class="text-caption">{{ initials }}</v-avatar>
        </v-btn>
      </template>
      <v-list density="compact" min-width="230">
        <v-list-item :title="auth.user.displayName" :subtitle="roleLabel" />
        <v-divider />
        <v-list-item to="/profil" prepend-icon="mdi-account-outline" title="Mon profil" />
        <v-list-item v-if="isSite" to="/espace" prepend-icon="mdi-folder-outline" title="Mes données" />
        <v-list-item v-if="auth.isAdmin" to="/admin" prepend-icon="mdi-shield-crown-outline" title="Administration générale" />
        <v-list-item v-if="isSite && siteStore.base !== '/'" href="/" prepend-icon="mdi-view-grid-outline" title="Tous les sites" />
        <v-list-item prepend-icon="mdi-logout" title="Se déconnecter" @click="logout" />
      </v-list>
    </v-menu>
    <v-btn v-else variant="outlined" class="ml-2" :to="{ name: 'login', query: { next: route.fullPath } }">Se connecter</v-btn>
  </v-app-bar>

  <v-navigation-drawer v-model="drawer" temporary location="left">
    <v-list nav>
      <template v-if="isSite">
        <v-list-item to="/" exact prepend-icon="mdi-map-outline" title="Carte" />
        <v-list-item to="/a-propos" prepend-icon="mdi-information-outline" title="À propos" />
        <template v-if="auth.isLogged">
          <v-list-item v-if="can.canContribute" to="/deposer" prepend-icon="mdi-plus" title="Déposer un jeu" />
          <v-list-item v-if="can.canContribute" to="/importer" prepend-icon="mdi-table-arrow-up" title="Import en lot" />
          <v-list-item to="/espace" prepend-icon="mdi-folder-outline" title="Mes données" />
          <v-list-item v-if="can.canManage" to="/administration" prepend-icon="mdi-cog-outline" title="Administration" />
        </template>
      </template>
      <v-list-item v-else to="/" prepend-icon="mdi-view-grid-outline" title="Sites" />
    </v-list>
  </v-navigation-drawer>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useTheme } from 'vuetify'
import { useAuthStore } from '@/stores/auth'
import { useSiteStore } from '@/stores/site'

const auth = useAuthStore()
const siteStore = useSiteStore()
const route = useRoute()
const router = useRouter()
const theme = useTheme()
const drawer = ref(false)

const site = computed(() => siteStore.site)
const isSite = computed(() => siteStore.mode === 'site')
const can = computed(() => siteStore.can)
const dark = computed(() => theme.global.current.value.dark)
const initials = computed(() => {
  const u = auth.user
  return ((u.firstname?.[0] || '') + (u.lastname?.[0] || '') || u.email[0]).toUpperCase()
})
const roleLabel = computed(() => {
  if (auth.isAdmin) return 'Administrateur'
  const r = can.value.role
  return r === 'responsable' ? 'Responsable du site' : r === 'utilisateur' ? 'Contributeur' : can.value.pending ? 'Demande en attente' : ''
})

function toggleTheme() {
  const next = dark.value ? 'light' : 'dark'
  theme.global.name.value = next
  try { localStorage.setItem('gs-theme', next) } catch { /* ignore */ }
}
async function logout() {
  await auth.logout()
  await siteStore.reload()
  router.push('/')
}
</script>

<style scoped>
.gs-header { border-bottom: 1px solid rgb(var(--v-theme-line)); }
.gs-brand { display: flex; align-items: center; gap: 12px; text-decoration: none; color: inherit; margin-left: 12px; min-width: 0; }
.gs-brand__logo { height: 36px; width: auto; }
.gs-brand__mark { width: 14px; height: 30px; border-radius: 3px; flex: none; }
.gs-brand__text { display: flex; flex-direction: column; min-width: 0; }
.gs-brand__title { font-family: var(--gs-font-display); font-weight: 700; font-size: 1.15rem; line-height: 1.1; letter-spacing: -0.01em; }
.gs-brand__sub { font-size: 0.78rem; color: rgb(var(--v-theme-muted)); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 360px; }
.gs-nav { margin-left: 24px; gap: 2px; }
</style>
