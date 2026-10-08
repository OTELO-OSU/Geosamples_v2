<template>
  <div class="gs-page">
    <h1>Administration générale</h1>
    <v-tabs v-model="tab" color="primary" class="mt-4 mb-6">
      <v-tab value="sites">Sites</v-tab>
      <v-tab value="users">Comptes</v-tab>
    </v-tabs>

    <template v-if="tab === 'sites'">
      <v-table class="gs-panel">
        <thead><tr><th>Site</th><th>Noms d'hôte</th><th class="text-end">Jeux publiés</th><th></th></tr></thead>
        <tbody>
          <tr v-for="s in sites" :key="s.slug">
            <td><strong>{{ s.title }}</strong> <code class="text-muted">{{ s.slug }}</code><v-chip v-if="!s.published" size="x-small" class="ml-2">non publié</v-chip></td>
            <td class="text-body-2">{{ s.hostnames.join(', ') || '—' }}</td>
            <td class="text-end">{{ s.datasets }}</td>
            <td class="text-end text-no-wrap">
              <v-btn size="small" variant="text" :href="`/s/${s.slug}/`">Ouvrir</v-btn>
              <v-btn size="small" variant="text" :href="`/s/${s.slug}/administration`">Configurer</v-btn>
              <v-btn size="small" variant="text" color="error" icon="mdi-delete-outline" :aria-label="`Supprimer ${s.title}`" @click="delSite(s)" />
            </td>
          </tr>
        </tbody>
      </v-table>
      <div class="gs-panel pa-5 mt-6" style="max-width: 720px">
        <h2>Nouveau site</h2>
        <p class="text-muted text-body-2 mt-1 mb-4">Le site est créé vide : son responsable configure ensuite textes, carte et champs depuis « Administration ».</p>
        <div class="d-flex ga-4 flex-wrap">
          <v-text-field v-model="nsite.title" label="Nom *" style="min-width: 240px" @update:model-value="autoSlug" />
          <v-text-field v-model="nsite.slug" label="Identifiant *" hint="minuscules, chiffres, tirets" persistent-hint style="max-width: 220px" :error-messages="errs.slug" />
        </div>
        <v-text-field v-model="nsite.hostnames" label="Nom d'hôte (facultatif)" placeholder="monprojet.otelo.univ-lorraine.fr" :error-messages="errs.hostnames" />
        <v-text-field v-model="nsite.managerEmail" label="E-mail du responsable (facultatif)" type="email" />
        <v-btn color="primary" :loading="busy" :disabled="!nsite.slug || !nsite.title" @click="createSite">Créer le site</v-btn>
      </div>
    </template>

    <template v-else>
      <div class="d-flex ga-3 mb-4 flex-wrap">
        <v-text-field v-model="q" prepend-inner-icon="mdi-magnify" label="Rechercher un compte" density="compact" hide-details style="max-width: 360px" />
        <v-spacer />
        <v-btn variant="outlined" prepend-icon="mdi-account-plus-outline" @click="userDialog = true">Créer un compte local</v-btn>
      </div>
      <v-table class="gs-panel" density="comfortable">
        <thead><tr><th>Personne</th><th>Sites</th><th>Dernière connexion</th><th>Admin</th><th>Actif</th></tr></thead>
        <tbody>
          <tr v-for="u in users" :key="u.id">
            <td><strong>{{ u.displayName }}</strong><br /><span class="text-muted text-body-2">{{ u.email }} · {{ u.federated ? 'fédéré' : u.local ? 'local' : 'pré-créé' }}</span></td>
            <td class="text-body-2">{{ u.memberships.map((m) => `${m.site} (${m.status === 'pending' ? 'demande' : m.role})`).join(', ') || '—' }}</td>
            <td class="text-body-2">{{ u.lastLoginAt ? fmtDate(u.lastLoginAt, true) : 'jamais' }}</td>
            <td><v-checkbox-btn :model-value="u.admin" :disabled="u.id === auth.user.id" :aria-label="`Administrateur : ${u.email}`" @update:model-value="(v) => patchUser(u, { admin: v })" /></td>
            <td><v-checkbox-btn :model-value="u.active" :disabled="u.id === auth.user.id" :aria-label="`Actif : ${u.email}`" @update:model-value="(v) => patchUser(u, { active: v })" /></td>
          </tr>
        </tbody>
      </v-table>
      <v-dialog v-model="userDialog" max-width="520">
        <v-card title="Compte local">
          <v-card-text>
            <div class="d-flex ga-3"><v-text-field v-model="nuser.firstname" label="Prénom" /><v-text-field v-model="nuser.lastname" label="Nom" /></div>
            <v-text-field v-model="nuser.email" label="E-mail *" :error-messages="errs.email" />
            <v-text-field v-model="nuser.password" label="Mot de passe *" type="password" hint="12 caractères minimum ; à transmettre à la personne" persistent-hint :error-messages="errs.password" />
            <v-checkbox v-model="nuser.admin" label="Administrateur" hide-details />
          </v-card-text>
          <v-card-actions><v-spacer /><v-btn variant="text" @click="userDialog = false">Annuler</v-btn><v-btn color="primary" :loading="busy" @click="createUser">Créer</v-btn></v-card-actions>
        </v-card>
      </v-dialog>
    </template>
  </div>
</template>

<script setup>
import { onMounted, ref, watch } from 'vue'
import api, { errorDetails, errorMessage } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { fmtDate } from '@/utils/format'

const auth = useAuthStore()
const ui = useUiStore()
const tab = ref('sites')
const sites = ref([])
const users = ref([])
const q = ref('')
const busy = ref(false)
const errs = ref({})
const nsite = ref({ title: '', slug: '', hostnames: '', managerEmail: '' })
const nuser = ref({ email: '', firstname: '', lastname: '', password: '', admin: false })
const userDialog = ref(false)

onMounted(loadSites)
watch(tab, (t) => (t === 'users' ? loadUsers() : loadSites()))
let t = null
watch(q, () => { clearTimeout(t); t = setTimeout(loadUsers, 250) })

async function loadSites() { sites.value = (await api.get('/api/sites')).data }
async function loadUsers() { users.value = (await api.get('/api/admin/users', { params: { q: q.value || undefined } })).data }

function autoSlug(v) {
  nsite.value.slug = (v || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40)
}
async function createSite() {
  busy.value = true
  errs.value = {}
  try {
    const p = { ...nsite.value, hostnames: nsite.value.hostnames ? [nsite.value.hostnames] : [] }
    await api.post('/api/admin/sites', p)
    ui.notify('Site créé')
    nsite.value = { title: '', slug: '', hostnames: '', managerEmail: '' }
    loadSites()
  } catch (e) { errs.value = errorDetails(e); ui.error(errorMessage(e)) } finally { busy.value = false }
}
async function delSite(s) {
  if (!confirm(`Supprimer le site « ${s.title} » ?`)) return
  try { await api.delete(`/api/admin/sites/${s.slug}`); ui.notify('Site supprimé'); loadSites() } catch (e) { ui.error(errorMessage(e)) }
}
async function patchUser(u, data) {
  try { Object.assign(u, (await api.patch(`/api/admin/users/${u.id}`, data)).data); ui.notify('Compte mis à jour') } catch (e) { ui.error(errorMessage(e)) }
}
async function createUser() {
  busy.value = true
  errs.value = {}
  try {
    await api.post('/api/admin/users', nuser.value)
    ui.notify('Compte créé')
    userDialog.value = false
    nuser.value = { email: '', firstname: '', lastname: '', password: '', admin: false }
    loadUsers()
  } catch (e) { errs.value = errorDetails(e); ui.error(errorMessage(e)) } finally { busy.value = false }
}
</script>
