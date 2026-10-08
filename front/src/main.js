/**
 * Démarrage : détection du site (préfixe /s/<slug>/ ou nom d'hôte), restauration de
 * session via le cookie de refresh, application du thème du site, puis montage.
 */
import { createApp } from 'vue'
import { createPinia } from 'pinia'
import '@fontsource/archivo/500.css'
import '@fontsource/archivo/600.css'
import '@fontsource/archivo/700.css'
import '@fontsource/ibm-plex-sans/400.css'
import '@fontsource/ibm-plex-sans/500.css'
import '@fontsource/ibm-plex-sans/600.css'
import '@mdi/font/css/materialdesignicons.css'
import 'leaflet/dist/leaflet.css'
import './styles/main.scss'
import App from './App.vue'
import vuetify, { applySiteTheme } from './plugins/vuetify'
import api, { setSiteHeader } from './api/client'
import { buildRouter } from './router'
import { useAuthStore } from './stores/auth'
import { useSiteStore } from './stores/site'
import { useUiStore } from './stores/ui'

const app = createApp(App)
const pinia = createPinia()
app.use(pinia)
app.use(vuetify)

const auth = useAuthStore()
const siteStore = useSiteStore()
auth.init()

const m = /^\/s\/([a-z0-9][a-z0-9-]*)(\/|$)/.exec(window.location.pathname)
if (m) setSiteHeader(m[1])

async function boot() {
  await auth.restore()
  let ctx = null
  try {
    ctx = (await api.get('/api/context')).data
  } catch { /* backend indisponible : on affiche le portail vide */ }
  siteStore.federation = ctx?.federation || siteStore.federation
  if (ctx?.site) {
    siteStore.site = ctx.site
    siteStore.mode = 'site'
    siteStore.base = m ? `/s/${m[1]}/` : '/'
    applySiteTheme(vuetify, ctx.site.theme)
  } else {
    setSiteHeader(null)
    siteStore.mode = 'portal'
    siteStore.base = '/'
    if (m) history.replaceState(null, '', '/')
  }
  const router = buildRouter(siteStore.base, siteStore.mode)
  app.use(router)

  // Retour de connexion fédérée (?auth=ok|error)
  const q = new URLSearchParams(window.location.search)
  if (q.has('auth')) {
    const ui = useUiStore()
    if (q.get('auth') === 'error') ui.error(q.get('message') || 'Connexion impossible')
    else if (auth.isLogged) ui.notify(`Bienvenue ${auth.user.firstname || auth.user.email}`)
    q.delete('auth'); q.delete('message')
    const rest = q.toString()
    history.replaceState(null, '', window.location.pathname + (rest ? `?${rest}` : ''))
  }
  app.mount('#app')
}
boot()
