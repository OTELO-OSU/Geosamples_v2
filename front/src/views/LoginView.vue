<template>
  <div class="gs-page gs-login">
    <h1>Sign in</h1>
    <p class="text-muted mt-2">Published data is freely accessible. Sign in to submit and manage datasets.</p>

    <section v-if="fed.loginUrl || fed.orcidLoginUrl" class="gs-panel pa-6 mt-6">
      <h2>Federated sign-in</h2>
      <p class="text-muted mt-1 mb-4">Sign in through your institution or with your ORCID iD.</p>
      <div class="d-flex flex-wrap ga-3">
        <v-btn v-if="fed.loginUrl" color="primary" size="large" prepend-icon="mdi-school-outline" :href="federationHref">Choose my institution</v-btn>
        <v-btn v-if="fed.orcidLoginUrl" color="success" size="large" prepend-icon="mdi-alpha-o-circle" :href="orcidHref">Sign in with ORCID</v-btn>
      </div>
    </section>

    <section class="gs-panel pa-6 mt-4">
      <h2>{{ fed.loginUrl || fed.orcidLoginUrl ? 'With a local account' : 'Account' }}</h2>
      <p v-if="fed.loginUrl || fed.orcidLoginUrl" class="text-muted mt-1 mb-4">For people outside the federation, by invitation from an administrator.</p>
      <v-form class="mt-3" @submit.prevent="submit">
        <v-text-field v-model="email" label="E-mail" type="email" autocomplete="username" />
        <v-text-field v-model="password" label="Password" type="password" autocomplete="current-password" />
        <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">{{ error }}</v-alert>
        <v-btn type="submit" :color="fed.loginUrl || fed.orcidLoginUrl ? undefined : 'primary'" :variant="fed.loginUrl || fed.orcidLoginUrl ? 'outlined' : 'flat'" :loading="busy" :disabled="!email || !password">Sign in</v-btn>
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
const returnUrl = computed(() => (siteStore.base.replace(/\/$/, '') + next.value) || '/')
const federationHref = computed(() => `${fed.value.loginUrl}?return=${encodeURIComponent(returnUrl.value)}`)
const orcidHref = computed(() => `${fed.value.orcidLoginUrl}?return=${encodeURIComponent(returnUrl.value)}`)

async function submit() {
  busy.value = true
  error.value = ''
  try {
    await auth.login(email.value, password.value)
    await siteStore.reload()
    ui.notify(`Welcome ${auth.user.firstname || ''}`.trim())
    router.replace(next.value)
  } catch (e) {
    error.value = errorMessage(e, 'Unable to sign in')
  } finally {
    busy.value = false
  }
}
</script>

<style scoped>
.gs-login { max-width: 560px; }
</style>
