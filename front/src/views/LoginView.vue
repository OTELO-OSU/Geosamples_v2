<template>
  <div class="gs-page gs-login">
    <h1>Connexion</h1>
    <p class="text-muted mt-2">La consultation des données publiées est libre. La connexion sert à déposer et gérer des jeux de données.</p>

    <section v-if="fed.loginUrl" class="gs-panel pa-6 mt-6">
      <h2>Avec votre établissement</h2>
      <p class="text-muted mt-1 mb-4">Fédération d'identité Éducation-Recherche (RENATER) : universités, CNRS, organismes de recherche.</p>
      <v-btn color="primary" size="large" prepend-icon="mdi-school-outline" :href="federationHref">Choisir mon établissement</v-btn>
    </section>

    <section class="gs-panel pa-6 mt-4">
      <h2>{{ fed.loginUrl ? 'Avec un compte local' : 'Compte' }}</h2>
      <p v-if="fed.loginUrl" class="text-muted mt-1 mb-4">Pour les personnes hors fédération, sur invitation d'un administrateur.</p>
      <v-form class="mt-3" @submit.prevent="submit">
        <v-text-field v-model="email" label="E-mail" type="email" autocomplete="username" />
        <v-text-field v-model="password" label="Mot de passe" type="password" autocomplete="current-password" />
        <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">{{ error }}</v-alert>
        <v-btn type="submit" :color="fed.loginUrl ? undefined : 'primary'" :variant="fed.loginUrl ? 'outlined' : 'flat'" :loading="busy" :disabled="!email || !password">Se connecter</v-btn>
      </v-form>
    </section>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { errorMessage } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useSiteStore } from '@/stores/site'
import { useUiStore } from '@/stores/ui'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const siteStore = useSiteStore()
const ui = useUiStore()
const email = ref('')
const password = ref('')
const busy = ref(false)
const error = ref('')
const fed = computed(() => siteStore.federation)
const next = computed(() => (typeof route.query.next === 'string' && route.query.next.startsWith('/') ? route.query.next : '/'))
const federationHref = computed(() => {
  const ret = (siteStore.base.replace(/\/$/, '') + next.value) || '/'
  return `${fed.value.loginUrl}?return=${encodeURIComponent(ret)}`
})

async function submit() {
  busy.value = true
  error.value = ''
  try {
    await auth.login(email.value, password.value)
    await siteStore.reload()
    ui.notify(`Bienvenue ${auth.user.firstname || ''}`.trim())
    router.replace(next.value)
  } catch (e) {
    error.value = errorMessage(e, 'Connexion impossible')
  } finally {
    busy.value = false
  }
}
</script>

<style scoped>
.gs-login { max-width: 560px; }
</style>
