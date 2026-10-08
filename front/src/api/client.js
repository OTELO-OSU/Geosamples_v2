/**
 * Client HTTP de l'API.
 *
 * Comme dans ORDaR v2 : le JWT d'accès est gardé en mémoire uniquement ; la session
 * est restaurée via le cookie HttpOnly de refresh (rotation à chaque usage). Les
 * rafraîchissements concurrents sont mutualisés (une seule requête /refresh en vol),
 * sinon la rotation invaliderait les requêtes parallèles.
 */
import axios from 'axios'

const state = { token: null, site: null, onSession: null, onLost: null }
let refreshing = null

export function setAccessToken(t) { state.token = t }
export function setSiteHeader(slug) { state.site = slug }
export function onSessionChange(fnSession, fnLost) { state.onSession = fnSession; state.onLost = fnLost }

const api = axios.create({ baseURL: '/', withCredentials: true })

api.interceptors.request.use((config) => {
  if (state.token && !config.headers.Authorization) config.headers.Authorization = `Bearer ${state.token}`
  if (state.site) config.headers['X-Site'] = state.site
  return config
})

export function refreshSession() {
  if (!refreshing) {
    refreshing = axios
      .post('/api/auth/refresh', null, { withCredentials: true })
      .then((r) => {
        state.token = r.data.token
        state.onSession?.(r.data)
        return r.data
      })
      .finally(() => { setTimeout(() => { refreshing = null }, 0) })
  }
  return refreshing
}

api.interceptors.response.use(
  (r) => r,
  async (error) => {
    const cfg = error.config || {}
    const status = error.response?.status
    if (status === 401 && cfg.headers?.Authorization && !cfg._retry) {
      cfg._retry = true
      try {
        const s = await refreshSession()
        cfg.headers.Authorization = `Bearer ${s.token}`
        return api(cfg)
      } catch {
        state.token = null
        state.onLost?.()
        delete cfg.headers.Authorization
        return api(cfg)
      }
    }
    return Promise.reject(error)
  },
)

/** Message lisible depuis une erreur d'API (avec détails de validation éventuels). */
export function errorMessage(e, fallback = 'Une erreur est survenue') {
  const d = e?.response?.data
  if (d?.error) return d.error
  if (d?.message) return d.message
  if (e?.message === 'Network Error') return 'Serveur injoignable'
  return fallback
}

export function errorDetails(e) {
  return e?.response?.data?.details || {}
}

export default api
