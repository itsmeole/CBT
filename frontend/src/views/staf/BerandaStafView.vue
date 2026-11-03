<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { RouterLink } from 'vue-router'
import StafNav from '@/components/StafNav.vue'
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
    if (isOperatorOrSuperadmin.value) {
      const [sRes, gRes, jRes] = await Promise.all([
        apiClient.get('/siswa'),
        apiClient.get('/guru'),
        apiClient.get('/jadwal')
      ])
      stats.value.totalSiswa = sRes.data.data?.length || 0
      stats.value.totalGuru = gRes.data.data?.length || 0
      stats.value.totalJadwal = jRes.data.data?.length || 0
    }
    if (isGuruOrSuperadmin.value) {
      const soRes = await apiClient.get('/bank-soal')
      stats.value.totalSoal = soRes.data.data?.length || 0
    }
  } catch {
    // ignore
  }
}

onMounted(() => {
  fetchStats()
})
</script>

<template>
  <div class="min-h-screen bg-slate-50 text-slate-800">
    <StafNav />

    <main class="mx-auto max-w-7xl p-4 sm:p-6">
      <!-- Welcome Banner -->
      <div class="mb-6 rounded-2xl bg-gradient-to-r from-brand-600 to-brand-800 p-6 text-white shadow">
        <h1 class="text-xl font-bold sm:text-2xl">
          Selamat Datang, {{ auth.user?.nama || auth.user?.username }}!
        </h1>
        <p class="mt-1 text-sm text-brand-100">
          Role Anda: <span class="font-bold uppercase tracking-wide">{{ role }}</span>. Panel ini siap digunakan untuk pelaksanaan CBT.
        </p>
      </div>

      <!-- Quick Stats -->
      <div class="mb-8 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div class="card bg-white p-4">
          <p class="text-xs font-semibold text-slate-400 uppercase">Total Siswa</p>
          <p class="text-2xl font-bold text-slate-800">{{ stats.totalSiswa }}</p>
        </div>
        <div class="card bg-white p-4">
          <p class="text-xs font-semibold text-slate-400 uppercase">Total Guru</p>
          <p class="text-2xl font-bold text-slate-800">{{ stats.totalGuru }}</p>
        </div>
        <div class="card bg-white p-4">
          <p class="text-xs font-semibold text-slate-400 uppercase">Butir Soal</p>
          <p class="text-2xl font-bold text-slate-800">{{ stats.totalSoal }}</p>
        </div>
        <div class="card bg-white p-4">
          <p class="text-xs font-semibold text-slate-400 uppercase">Jadwal Ujian</p>
          <p class="text-2xl font-bold text-slate-800">{{ stats.totalJadwal }}</p>
        </div>
      </div>

      <!-- Main Navigation Modules -->
      <h2 class="text-base font-bold text-slate-800 mb-4">Akses Cepat Modul CBT:</h2>
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <RouterLink
          v-if="isOperatorOrSuperadmin"
          to="/staf/master"
          class="card bg-white p-5 transition-transform hover:-translate-y-1 hover:shadow-md cursor-pointer block border-l-4 border-l-brand-600"
        >
          <div class="text-2xl mb-2">👥</div>
          <h3 class="text-base font-bold text-slate-900">Master Data & Excel</h3>
          <p class="mt-1 text-xs text-slate-500">
            Kelola data siswa, guru, kelas, mapel, dan ruang. Impor massal via Microsoft Excel.
          </p>
        </RouterLink>

        <RouterLink
          v-if="isGuruOrSuperadmin"
          to="/staf/bank-soal"
          class="card bg-white p-5 transition-transform hover:-translate-y-1 hover:shadow-md cursor-pointer block border-l-4 border-l-emerald-600"
        >
          <div class="text-2xl mb-2">📚</div>
          <h3 class="text-base font-bold text-slate-900">Bank Soal & Word</h3>
          <p class="mt-1 text-xs text-slate-500">
            Kelola butir soal 5 tipe, formula KaTeX, dan impor dokumen Word (.docx) sistematis.
          </p>
        </RouterLink>

        <RouterLink
          v-if="isOperatorOrSuperadmin || isGuruOrSuperadmin"
          to="/staf/jadwal"
          class="card bg-white p-5 transition-transform hover:-translate-y-1 hover:shadow-md cursor-pointer block border-l-4 border-l-amber-500"
        >
          <div class="text-2xl mb-2">📅</div>
          <h3 class="text-base font-bold text-slate-900">Jadwal & Paket Ujian</h3>
          <p class="mt-1 text-xs text-slate-500">
            Konfigurasi bobot, susun paket soal ujian, dan terbitkan jadwal beserta token sesi.
          </p>
        </RouterLink>

        <RouterLink
          v-if="isOperatorOrSuperadmin"
          to="/staf/kontrol-sesi"
          class="card bg-white p-5 transition-transform hover:-translate-y-1 hover:shadow-md cursor-pointer block border-l-4 border-l-rose-600"
        >
          <div class="text-2xl mb-2">⚡</div>
          <h3 class="text-base font-bold text-slate-900">Kontrol Sesi Operator</h3>
          <p class="mt-1 text-xs text-slate-500">
            Verifikasi kehadiran siswa, monitoring status, dan mulai ujian serentak.
          </p>
        </RouterLink>
      </div>
    </main>
  </div>
</template>
