<template>
  <div>
    <div v-if="pending.length" class="mb-8">
      <h3 class="mb-2">Pending requests</h3>
      <v-list class="gs-panel py-0">
        <v-list-item v-for="m in pending" :key="m.id" :title="m.user.displayName" :subtitle="`${m.user.email}${m.message ? ' — « ' + m.message + ' »' : ''}`">
          <template #append>
            <v-btn size="small" color="primary" class="mr-2" @click="patch(m, { status: 'active' })">Accept</v-btn>
            <v-btn size="small" variant="text" color="error" @click="remove(m)">Reject</v-btn>
          </template>
        </v-list-item>
      </v-list>
    </div>

    <h3 class="mb-1">Members</h3>
    <p class="text-muted text-body-2 mb-3">Users submit and edit their own datasets; managers review submissions, configure the site, and manage members.</p>
    <v-table density="comfortable" class="gs-panel">
      <thead><tr><th>Person</th><th>Sign-in</th><th style="width: 220px">Role</th><th></th></tr></thead>
      <tbody>
        <tr v-for="m in active" :key="m.id">
          <td><strong>{{ m.user.displayName }}</strong><br /><span class="text-muted text-body-2">{{ m.user.email }}</span></td>
          <td class="text-body-2">{{ m.user.federated ? 'RENATER federation' : m.user.local ? 'Local account' : 'Never signed in' }}</td>
          <td><v-select :model-value="m.role" :items="ROLES" density="compact" hide-details variant="plain" :aria-label="`Role for ${m.user.displayName}`" @update:model-value="(r) => patch(m, { role: r })" /></td>
          <td class="text-end"><v-btn icon="mdi-account-remove-outline" variant="text" size="small" :aria-label="`Remove ${m.user.displayName}`" @click="remove(m)" /></td>
        </tr>
      </tbody>
    </v-table>

    <div class="gs-panel pa-4 mt-6">
      <h3>Add a person</h3>
      <p class="text-muted text-body-2 mt-1 mb-3">
        Enter their institutional email address: if they have never signed in, access will be ready on their first sign-in through the federation.
      </p>
      <div class="d-flex flex-wrap ga-3 align-start">
        <v-text-field v-model="email" label="Email" type="email" density="compact" style="min-width: 260px" :error-messages="err.email" />
        <v-select v-model="role" :items="ROLES" label="Role" density="compact" style="max-width: 200px" />
        <v-btn color="primary" :loading="busy" :disabled="!email" @click="add">Add</v-btn>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import api, { errorDetails, errorMessage } from '@/api/client'
import { useUiStore } from '@/stores/ui'

const props = defineProps({ slug: String })
const ui = useUiStore()
const members = ref([])
const email = ref('')
const role = ref('utilisateur')
const busy = ref(false)
const err = ref({})
const ROLES = [{ value: 'utilisateur', title: 'User' }, { value: 'responsable', title: 'Manager' }]

const pending = computed(() => members.value.filter((m) => m.status === 'pending'))
const active = computed(() => members.value.filter((m) => m.status === 'active'))

onMounted(load)
async function load() { members.value = (await api.get(`/api/sites/${props.slug}/members`)).data }

async function patch(m, data) {
  try { await api.patch(`/api/sites/${props.slug}/members/${m.id}`, data); ui.notify('Member updated'); load() } catch (e) { ui.error(errorMessage(e)); load() }
}
async function remove(m) {
  try { await api.delete(`/api/sites/${props.slug}/members/${m.id}`); ui.notify('Member removed'); load() } catch (e) { ui.error(errorMessage(e)) }
}
async function add() {
  busy.value = true
  err.value = {}
  try {
    await api.post(`/api/sites/${props.slug}/members`, { email: email.value, role: role.value })
    ui.notify('Access added')
    email.value = ''
    load()
  } catch (e) { err.value = errorDetails(e); ui.error(errorMessage(e)) } finally { busy.value = false }
}
</script>
