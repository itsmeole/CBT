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
    // Siswa Routes
    {
      path: '/ujian',
      name: 'siswa-beranda',
      component: () => import('@/views/siswa/BerandaSiswaView.vue'),
      meta: { roles: ['siswa'] }
    },
    {
      path: '/ujian/tunggu',
      name: 'siswa-tunggu',
      component: () => import('@/views/siswa/RuangTungguView.vue'),
      meta: { roles: ['siswa'] }
    },
    {
      path: '/ujian/kerjakan',
      name: 'siswa-kerjakan',
      component: () => import('@/views/siswa/PengerjaanUjianView.vue'),
      meta: { roles: ['siswa'] }
    },
    {
      path: '/ujian/selesai',
      name: 'siswa-selesai',
      component: () => import('@/views/siswa/UjianSelesaiView.vue'),
      meta: { roles: ['siswa'] }
    },
    // Staf Routes
    {
      path: '/staf',
      name: 'staf-beranda',
      component: () => import('@/views/staf/BerandaStafView.vue'),
      meta: { roles: ['superadmin', 'operator', 'guru'] }
    },
    {
      path: '/staf/master',
      name: 'staf-master',
      component: () => import('@/views/staf/MasterDataView.vue'),
      meta: { roles: ['superadmin', 'operator'] }
    },
    {
      path: '/staf/bank-soal',
      name: 'staf-bank-soal',
      component: () => import('@/views/staf/BankSoalView.vue'),
      meta: { roles: ['superadmin', 'operator', 'guru'] }
    },
    {
      path: '/staf/jadwal',
      name: 'staf-jadwal',
      component: () => import('@/views/staf/JadwalView.vue'),
      meta: { roles: ['superadmin', 'operator', 'guru'] }
    },
    {
      path: '/staf/kontrol-sesi',
      name: 'staf-kontrol-sesi',
      component: () => import('@/views/staf/KontrolSesiOperatorView.vue'),
      meta: { roles: ['superadmin', 'operator'] }
    },
    {
      path: '/staf/monitoring',
      name: 'staf-monitoring',
      component: () => import('@/views/staf/MonitoringPengawasView.vue'),
      meta: { roles: ['superadmin', 'operator', 'guru'] }
    },
    {
      path: '/staf/koreksi-essay',
      name: 'staf-koreksi-essay',
      component: () => import('@/views/staf/KoreksiEssayView.vue'),
      meta: { roles: ['superadmin', 'operator', 'guru'] }
    },
    {
      path: '/staf/nilai',
      name: 'staf-nilai',
      component: () => import('@/views/staf/RekapNilaiView.vue'),
      meta: { roles: ['superadmin', 'operator', 'guru'] }
    },
    {
      path: '/staf/cetak',
      name: 'staf-cetak',
      component: () => import('@/views/staf/CetakDokumenView.vue'),
      meta: { roles: ['superadmin', 'operator', 'guru'] }
    },
    {
      path: '/staf/analisis-butir',
      name: 'staf-analisis-butir',
      component: () => import('@/views/staf/AnalisisButirView.vue'),
      meta: { roles: ['superadmin', 'guru'] }
    },
    {
      path: '/staf/audit-log',
      name: 'staf-audit-log',
      component: () => import('@/views/staf/AuditLogView.vue'),
      meta: { roles: ['superadmin'] }
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
