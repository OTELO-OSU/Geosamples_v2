import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useSiteStore } from '@/stores/site'

const shared = [
  { path: '/connexion', name: 'login', component: () => import('@/views/LoginView.vue'), meta: { title: 'Connexion' } },
  { path: '/profil', name: 'profile', component: () => import('@/views/ProfileView.vue'), meta: { auth: true, title: 'Mon profil' } },
  { path: '/admin', name: 'global-admin', component: () => import('@/views/GlobalAdminView.vue'), meta: { admin: true, title: 'Administration générale' } },
]

const siteRoutes = [
  { path: '/', name: 'map', component: () => import('@/views/MapView.vue'), meta: { fullscreen: true } },
  { path: '/jeux/:uuid', name: 'dataset', component: () => import('@/views/DatasetView.vue'), props: true },
  { path: '/jeux/:uuid/modifier', name: 'dataset-edit', component: () => import('@/views/DatasetEditView.vue'), props: true, meta: { auth: true, title: 'Modifier un jeu' } },
  { path: '/deposer', name: 'dataset-new', component: () => import('@/views/DatasetEditView.vue'), meta: { auth: true, contribute: true, title: 'Déposer un jeu' } },
  { path: '/importer', name: 'import', component: () => import('@/views/ImportView.vue'), meta: { auth: true, contribute: true, title: 'Import en lot' } },
  { path: '/espace', name: 'workspace', component: () => import('@/views/WorkspaceView.vue'), meta: { auth: true, title: 'Mes données' } },
  { path: '/administration', name: 'site-admin', component: () => import('@/views/SiteAdminView.vue'), meta: { auth: true, manage: true, title: 'Administration du site' } },
  { path: '/a-propos', name: 'about', component: () => import('@/views/AboutView.vue'), meta: { title: 'À propos' } },
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
    if ((to.meta.auth || to.meta.admin) && !auth.isLogged) return { name: 'login', query: { next: to.fullPath } }
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
