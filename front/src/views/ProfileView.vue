<template>
  <div class="gs-page" style="max-width: 640px">
    <h1>Mon profil</h1>
    <p class="text-muted mt-1">{{ auth.user.email }}<template v-if="auth.user.federated"> — connexion via votre établissement</template></p>
    <v-form class="mt-6" @submit.prevent="save">
      <div class="d-flex ga-4"><v-text-field v-model="form.firstname" label="Prénom" /><v-text-field v-model="form.lastname" label="Nom" /></div>
      <v-text-field v-model="form.affiliation" label="Affiliation (laboratoire, établissement)" />
      <v-text-field v-model="form.orcid" label="ORCID" placeholder="0000-0000-0000-0000" :error-messages="errors.orcid" />
      <template v-if="auth.user.local">
        <h2 class="mt-6 mb-3">Mot de passe</h2>
        <v-text-field v-model="form.currentPassword" label="Mot de passe actuel" type="password" autocomplete="current-password" :error-messages="errors.currentPassword" />
        <v-text-field v-model="form.newPassword" label="Nouveau mot de passe" type="password" autocomplete="new-password" hint="12 caractères minimum" persistent-hint :error-messages="errors.newPassword" />
      </template>
      <v-btn type="submit" color="primary" class="mt-4" :loading="busy">Enregistrer</v-btn>
    </v-form>
    <template v-if="auth.user.memberships?.length">
      <h2 class="mt-10 mb-3">Mes sites</h2>
      <v-list class="gs-panel py-0">
        <v-list-item v-for="m in auth.user.memberships" :key="m.site" :title="m.siteTitle" :subtitle="m.status === 'pending' ? 'Demande en attente' : m.role === 'responsable' ? 'Responsable' : 'Utilisateur'" />
      </v-list>
    </template>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import api, { errorDetails, errorMessage } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'

const auth = useAuthStore()
const ui = useUiStore()
const u = auth.user
const form = ref({ firstname: u.firstname, lastname: u.lastname, affiliation: u.affiliation || '', orcid: u.orcid || '', currentPassword: '', newPassword: '' })
const errors = ref({})
const busy = ref(false)

async function save() {
  busy.value = true
  errors.value = {}
  try {
    auth.user = (await api.patch('/api/me', form.value)).data
    form.value.currentPassword = form.value.newPassword = ''
    ui.notify('Profil enregistré')
  } catch (e) {
    errors.value = errorDetails(e)
    ui.error(errorMessage(e))
  } finally {
    busy.value = false
  }
}
</script>
