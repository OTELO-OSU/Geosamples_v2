import { defineStore } from 'pinia'
import api from '@/api/client'

/**
 * Current site context. `mode` is 'site' (a data management site) or 'portal'
 * (site list, global administration). `base` is the routing prefix:
 * '/' when the site is served on its own hostname, '/s/<slug>/' otherwise.
 */
export const useSiteStore = defineStore('site', {
  state: () => ({ site: null, mode: 'portal', base: '/', federation: { mode: 'none', loginUrl: null, orcidLoginUrl: null } }),
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
