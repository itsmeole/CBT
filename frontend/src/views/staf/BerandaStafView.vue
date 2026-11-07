<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { RouterLink } from 'vue-router'
import StafLayout from '@/components/StafLayout.vue'
import { useAuthStore } from '@/stores/auth'
import { apiClient } from '@/api/client'

const auth = useAuthStore()

const role = computed(() => auth.user?.role)
const isOperatorOrSuperadmin = computed(() => role.value === 'superadmin' || role.value === 'operator')
const isGuruOrSuperadmin = computed(() => role.value === 'superadmin' || role.value === 'guru')

const stats = ref({
  totalSiswa: 0,
  totalGuru: 0,
  totalSoal: 0,
  totalJadwal: 0
})

async function fetchStats() {
  try {
    const [sRes, gRes, jRes, soRes] = await Promise.all([
      apiClient.get('/siswa').catch(() => ({ data: { data: [] } })),
      apiClient.get('/guru').catch(() => ({ data: { data: [] } })),
      apiClient.get('/jadwal').catch(() => ({ data: { data: [] } })),
      apiClient.get('/bank-soal').catch(() => ({ data: { data: [] } }))
    ])
    stats.value.totalSiswa = sRes.data.data?.length || 0
    stats.value.totalGuru = gRes.data.data?.length || 0
    stats.value.totalJadwal = jRes.data.data?.length || 0
    stats.value.totalSoal = soRes.data.data?.length || 0
  } catch {
    // ignore
  }
}

onMounted(() => {
  fetchStats()
})
</script>

<template>
  <StafLayout>
    <div class="space-y-6">
      <!-- Welcome Banner -->
      <div
        class="rounded-3xl bg-brand-700 bg-cover bg-center bg-no-repeat p-6 sm:p-8 text-white shadow-md relative overflow-hidden"
        style="background-image: url('/banner.svg'); background-size: cover; background-position: center;"
      >
        <div class="relative z-10 max-w-3xl">
          <span class="inline-block rounded-lg bg-white/20 backdrop-blur-xs px-2.5 py-1 text-xs font-bold uppercase tracking-wider text-white mb-2">
            Panel Staf • {{ role }}
          </span>
          <h1 class="text-2xl sm:text-3xl font-extrabold tracking-tight">
            Selamat Datang, {{ auth.user?.nama || auth.user?.username }}
          </h1>
          <p class="mt-2 text-sm sm:text-base text-blue-100 max-w-3xl leading-relaxed font-medium">
            Integrated Computer Based Testing (ICBT) • Evaluate Knowledge, Inspire Growth.
          </p>
        </div>
      </div>

      <!-- Quick Stats -->
      <div>
        <h2 class="text-sm font-bold text-slate-500 uppercase tracking-wider mb-3">Statistik Data</h2>
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
          <div class="card bg-white p-5 border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
              <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Total Siswa</p>
              <p class="text-2xl sm:text-3xl font-extrabold text-slate-900 font-mono mt-1">{{ stats.totalSiswa }}</p>
            </div>
            <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-blue-50 text-brand-600">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-6 w-6">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Z" />
              </svg>
            </div>
          </div>

          <div class="card bg-white p-5 border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
              <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Total Guru</p>
              <p class="text-2xl sm:text-3xl font-extrabold text-slate-900 font-mono mt-1">{{ stats.totalGuru }}</p>
            </div>
            <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-6 w-6">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342" />
              </svg>
            </div>
          </div>

          <div class="card bg-white p-5 border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
              <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Butir Soal</p>
              <p class="text-2xl sm:text-3xl font-extrabold text-slate-900 font-mono mt-1">{{ stats.totalSoal }}</p>
            </div>
            <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-6 w-6">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
              </svg>
            </div>
          </div>

          <div class="card bg-white p-5 border border-slate-200/80 shadow-xs flex items-center justify-between">
            <div>
              <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Jadwal Ujian</p>
              <p class="text-2xl sm:text-3xl font-extrabold text-slate-900 font-mono mt-1">{{ stats.totalJadwal }}</p>
            </div>
            <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-amber-50 text-amber-600">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-6 w-6">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
              </svg>
            </div>
          </div>
        </div>
      </div>

      <!-- Main Navigation Modules -->
      <div id="modul-utama">
        <h2 class="text-sm font-bold text-slate-500 uppercase tracking-wider mb-3">Akses Modul Utama</h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <RouterLink
            v-if="isOperatorOrSuperadmin"
            to="/staf/master"
            class="card bg-white p-6 border border-slate-200 hover:border-brand-500 hover:shadow-md transition-all group block rounded-2xl"
          >
            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-brand-50 text-brand-600 mb-4 group-hover:bg-brand-600 group-hover:text-white transition-colors">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-6 w-6">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Z" />
              </svg>
            </div>
            <h3 class="text-base font-bold text-slate-900 group-hover:text-brand-600 transition-colors">Master Data</h3>
            <p class="mt-1 text-xs text-slate-500 leading-relaxed">
              Kelola data siswa, guru, kelas, mata pelajaran, dan ruang ujian.
            </p>
          </RouterLink>

          <RouterLink
            v-if="isGuruOrSuperadmin"
            to="/staf/bank-soal"
            class="card bg-white p-6 border border-slate-200 hover:border-emerald-500 hover:shadow-md transition-all group block rounded-2xl"
          >
            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 mb-4 group-hover:bg-emerald-600 group-hover:text-white transition-colors">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-6 w-6">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
              </svg>
            </div>
            <h3 class="text-base font-bold text-slate-900 group-hover:text-emerald-600 transition-colors">Bank Soal</h3>
            <p class="mt-1 text-xs text-slate-500 leading-relaxed">
              Kelola butir soal 5 tipe, formula KaTeX, dan dokumen berkas soal.
            </p>
          </RouterLink>

          <RouterLink
            v-if="isOperatorOrSuperadmin || isGuruOrSuperadmin"
            to="/staf/jadwal"
            class="card bg-white p-6 border border-slate-200 hover:border-amber-500 hover:shadow-md transition-all group block rounded-2xl"
          >
            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-amber-50 text-amber-600 mb-4 group-hover:bg-amber-600 group-hover:text-white transition-colors">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-6 w-6">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
              </svg>
            </div>
            <h3 class="text-base font-bold text-slate-900 group-hover:text-amber-600 transition-colors">Jadwal & Paket Ujian</h3>
            <p class="mt-1 text-xs text-slate-500 leading-relaxed">
              Konfigurasi bobot, susun paket soal ujian, dan terbitkan jadwal beserta token sesi.
            </p>
          </RouterLink>

          <RouterLink
            v-if="isOperatorOrSuperadmin"
            to="/staf/kontrol-sesi"
            class="card bg-white p-6 border border-slate-200 hover:border-rose-500 hover:shadow-md transition-all group block rounded-2xl"
          >
            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-rose-50 text-rose-600 mb-4 group-hover:bg-rose-600 group-hover:text-white transition-colors">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-6 w-6">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75" />
              </svg>
            </div>
            <h3 class="text-base font-bold text-slate-900 group-hover:text-rose-600 transition-colors">Kontrol Sesi Ujian</h3>
            <p class="mt-1 text-xs text-slate-500 leading-relaxed">
              Verifikasi kehadiran siswa, monitoring status, reset sesi login, dan mulai ujian serentak.
            </p>
          </RouterLink>
        </div>
      </div>
    </div>
  </StafLayout>
</template>
