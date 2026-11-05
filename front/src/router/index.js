import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useSiteStore } from '@/stores/site'

const shared = [
  // ORCID callback (URI registered with ORCID): forwarded unchanged to the backend.
  { path: '/orcid', name: 'orcid-callback', component: () => import('@/views/NotFoundView.vue'), beforeEnter: (to) => { window.location.replace('/api/auth/orcid/callback' + (to.fullPath.includes('?') ? to.fullPath.slice(to.fullPath.indexOf('?')) : '')); return false } },
  { path: '/sign-in', alias: '/connexion', name: 'sign-in', component: () => import('@/views/LoginView.vue'), meta: { title: 'Sign in' } },
  { path: '/profile', alias: '/profil', name: 'profile', component: () => import('@/views/ProfileView.vue'), meta: { auth: true, title: 'My profile' } },
  { path: '/admin', name: 'global-admin', component: () => import('@/views/GlobalAdminView.vue'), meta: { admin: true, title: 'Global administration' } },
]

const siteRoutes = [
  { path: '/', name: 'map', component: () => import('@/views/MapView.vue'), meta: { fullscreen: true } },
  { path: '/datasets/:uuid', alias: '/jeux/:uuid', name: 'dataset', component: () => import('@/views/DatasetView.vue'), props: true },
  { path: '/datasets/:uuid/edit', alias: '/jeux/:uuid/modifier', name: 'edit-dataset', component: () => import('@/views/DatasetEditView.vue'), props: true, meta: { auth: true, title: 'Edit dataset' } },
  { path: '/submit', alias: '/deposer', name: 'submit-dataset', component: () => import('@/views/DatasetEditView.vue'), meta: { auth: true, contribute: true, title: 'Submit a dataset' } },
  { path: '/import', alias: '/importer', name: 'bulk-import', component: () => import('@/views/ImportView.vue'), meta: { auth: true, contribute: true, title: 'Bulk import' } },
  { path: '/workspace', alias: '/espace', name: 'workspace', component: () => import('@/views/WorkspaceView.vue'), meta: { auth: true, title: 'My data' } },
  { path: '/site-administration', alias: '/administration', name: 'site-administration', component: () => import('@/views/SiteAdminView.vue'), meta: { auth: true, manage: true, title: 'Site administration' } },
  { path: '/about', alias: '/a-propos', name: 'about', component: () => import('@/views/AboutView.vue'), meta: { title: 'About' } },
]

const portalRoutes = [
  { path: '/', name: 'portal', component: () => import('@/views/PortalView.vue') },
]

export function buildRouter(base, mode) {
  const router = createRouter({
    history: createWebHistory(base),
    routes: [...(mode === 'site' ? siteRoutes : portalRoutes), ...shared, { path: '/:pathMatch(.*)*', name: 'notfound', component: () => import('@/views/NotFoundView.vue') }],
    scrollBehavior: (to, from, saved) => saved || { top: 0 },
  })

  router.beforeEach((to) => {
    const auth = useAuthStore()
    const site = useSiteStore()
    if ((to.meta.auth || to.meta.admin) && !auth.isLogged) return { name: 'sign-in', query: { next: to.fullPath } }
    if (to.meta.admin && !auth.isAdmin) return { path: '/' }
    if (to.meta.manage && !site.can.canManage) return { path: '/' }
    if (to.meta.contribute && !site.can.canContribute) return { name: 'workspace' }
    return true
  })
  router.afterEach((to) => {
    const site = useSiteStore()
    const base = site.site?.title || 'GeoSamples'
    document.title = to.meta.title ? `${to.meta.title} · ${base}` : base
  })
  return router
}
