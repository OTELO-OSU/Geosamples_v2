import { defineStore } from 'pinia'
import api, { setAccessToken, refreshSession, onSessionChange } from '@/api/client'

export const useAuthStore = defineStore('auth', {
  state: () => ({ user: null, ready: false }),
  getters: {
    isLogged: (s) => !!s.user,
    isAdmin: (s) => !!s.user?.admin,
  },
  actions: {
    init() {
      onSessionChange(
        (data) => { this.user = data.user },
        () => { this.user = null },
      )
    },
    async restore() {
      try {
        await refreshSession()
      } catch {
        this.user = null
      } finally {
        this.ready = true
      }
    },
    async login(email, password) {
      const { data } = await api.post('/api/auth/login', { email, password })
      setAccessToken(data.token)
      this.user = data.user
    },
    async logout() {
      try { await api.post('/api/auth/logout') } catch { /* ignore */ }
      setAccessToken(null)
      this.user = null
    },
    async reloadMe() {
      const { data } = await api.get('/api/me')
      this.user = data
    },
  },
})
