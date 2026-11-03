import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { berandaUntukRole, resolveGuard, type RouteAccessMeta } from './guard'

declare module 'vue-router' {
  // eslint-disable-next-line @typescript-eslint/no-empty-object-type
  interface RouteMeta extends RouteAccessMeta {}
}

/**
 * Semua halaman di-load lazy agar bundle awal siswa terpisah dari halaman staf (NFR-FE-01).
 * Halaman fitur ditambahkan bertahap sesuai tahapan.md.
 */
export const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      name: 'root',
      redirect: () => {
        const auth = useAuthStore()
        return { name: auth.role ? berandaUntukRole(auth.role) : 'login' }
      }
    },
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/LoginView.vue'),
      meta: { public: true }
    },
    {
      path: '/ujian',
      name: 'siswa-beranda',
      component: () => import('@/views/siswa/BerandaSiswaView.vue'),
      meta: { roles: ['siswa'] }
    },
    {
      path: '/staf',
      name: 'staf-beranda',
      component: () => import('@/views/staf/BerandaStafView.vue'),
      meta: { roles: ['superadmin', 'operator', 'guru'] }
    },
    {
      path: '/403',
      name: 'forbidden',
      component: () => import('@/views/ForbiddenView.vue'),
      meta: { public: true }
    },
    {
      path: '/:pathMatch(.*)*',
      name: 'not-found',
      component: () => import('@/views/NotFoundView.vue'),
      meta: { public: true }
    }
  ]
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  if (!auth.initialized) await auth.fetchMe()
  return resolveGuard(to.meta, auth.role, to.name)
})

