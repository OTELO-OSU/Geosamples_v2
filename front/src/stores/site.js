import { defineStore } from 'pinia'
import api from '@/api/client'

/**
 * Contexte du site courant. `mode` vaut 'site' (un site de valorisation) ou 'portal'
 * (liste des sites, administration globale). `base` est le préfixe de routage :
 * '/' quand le site est servi par son propre nom d'hôte, '/s/<slug>/' sinon.
 */
export const useSiteStore = defineStore('site', {
  state: () => ({ site: null, mode: 'portal', base: '/', federation: { mode: 'none', loginUrl: null } }),
  getters: {
    slug: (s) => s.site?.slug,
    fields: (s) => s.site?.fields || [],
    fieldMap: (s) => Object.fromEntries((s.site?.fields || []).map((f) => [f.key, f])),
    can: (s) => s.site?.me || {},
  },
  actions: {
    async reload() {
      if (!this.site) return
      const { data } = await api.get(`/api/sites/${this.site.slug}`)
      this.site = data
    },
  },
})
