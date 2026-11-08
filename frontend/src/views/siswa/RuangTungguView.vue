<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { apiClient } from '@/api/client'
import { useUjianStore } from '@/stores/ujian'
import { useAuthStore } from '@/stores/auth'
import ConfirmLogoutModal from '@/components/ConfirmLogoutModal.vue'

const router = useRouter()
const ujianStore = useUjianStore()
const auth = useAuthStore()

const loading = ref(true)
const examState = ref<any>(null)
const errorMessage = ref('')
const showLogoutConfirm = ref(false)
const isLoggingOut = ref(false)
let pollInterval: any = null

function requestLogout() {
  showLogoutConfirm.value = true
}

async function handleConfirmedLogout() {
  isLoggingOut.value = true
  try {
    if (pollInterval) clearInterval(pollInterval)
    await auth.logout()
    showLogoutConfirm.value = false
    router.replace({ name: 'login' })
  } finally {
    isLoggingOut.value = false
  }
}

async function checkState() {
  try {
    const res = await apiClient.get('/ujian/state')
    const state = res.data.data ?? {}
    examState.value = state
    const status = state.status

    if (state.waktu_selesai_ms) {
      ujianStore.terapkanStateServer({
        status,
        waktuSelesaiMs: state.waktu_selesai_ms
      })
    }

    if (status === 'mengerjakan') {
      if (pollInterval) clearInterval(pollInterval)
      router.replace({ name: 'siswa-kerjakan' })
    } else if (status === 'selesai') {
      if (pollInterval) clearInterval(pollInterval)
      router.replace({ name: 'siswa-selesai' })
    }
  } catch (err: any) {
    errorMessage.value = err.message || 'Gagal memeriksa status ujian.'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  checkState()
  // Poll every 3 seconds for start signal (FR-FE-10)
  pollInterval = setInterval(checkState, 3000)
})

onUnmounted(() => {
  if (pollInterval) clearInterval(pollInterval)
})
</script>

<template>
  <main class="mx-auto flex min-h-dvh max-w-md flex-col justify-between p-4 sm:p-6 text-center">
    <!-- Header -->
    <header class="border-b border-slate-200 pb-3 flex items-center justify-between">
      <div class="text-left">
        <h1 class="text-lg font-bold text-slate-800">Ruang Tunggu Ujian</h1>
        <p class="text-xs text-slate-500">Anda telah bergabung ke dalam sesi</p>
      </div>
      <button
        type="button"
        @click="requestLogout"
        class="flex items-center gap-1.5 rounded-xl border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-red-50 hover:text-red-600 hover:border-red-200 active:scale-95 transition-all shadow-2xs"
        title="Keluar dari akun siswa"
      >
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12.75" />
        </svg>
        <span class="hidden sm:inline">Keluar</span>
      </button>
    </header>

    <!-- Content -->
    <div class="my-auto space-y-6 py-6">
      <!-- Pulsing Waiting Animation -->
      <div class="flex flex-col items-center justify-center">
        <div class="relative flex h-20 w-20 items-center justify-center">
          <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-brand-400 opacity-40"></span>
          <span class="relative inline-flex h-16 w-16 items-center justify-center rounded-2xl bg-brand-600 text-white shadow-lg">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-8 w-8 animate-spin" style="animation-duration: 3s;">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
            </svg>
          </span>
        </div>
        <h2 class="mt-4 text-base font-bold text-slate-800">Menunggu Pengawas Memulai Ujian</h2>
        <p class="mt-1 text-xs text-slate-500 max-w-xs">
          Tetap berada di halaman ini. Soal akan otomatis tampil di layar ketika pengawas menekan tombol mulai.
        </p>
      </div>

      <!-- Exam Info Card -->
      <div v-if="examState" class="card bg-white p-5 text-left space-y-2.5 rounded-2xl">
        <div class="flex justify-between border-b border-slate-100 pb-2 text-xs">
          <span class="text-slate-400 font-medium">Mata Pelajaran:</span>
          <span class="font-bold text-slate-800">{{ examState.mapel || 'Ujian Sekolah' }}</span>
        </div>
        <div class="flex justify-between border-b border-slate-100 pb-2 text-xs">
          <span class="text-slate-400 font-medium">Durasi:</span>
          <span class="font-bold text-slate-800">{{ examState.durasi_menit || 90 }} Menit</span>
        </div>
        <div class="flex justify-between text-xs">
          <span class="text-slate-400 font-medium">Status Anda:</span>
          <span class="font-bold uppercase text-amber-600">{{ examState.status || 'Menunggu' }}</span>
        </div>
      </div>

      <div class="rounded-xl bg-amber-50 border border-amber-200 p-3.5 text-left text-xs text-amber-800 space-y-1.5">
        <p class="font-bold flex items-center gap-1.5">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4 text-amber-600">
            <path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" />
          </svg>
          <span>Petunjuk Pengerjaan:</span>
        </p>
        <p>• Waktu pengerjaan akan dihitung otomatis oleh sistem setelah dimulai.</p>
        <p>• Dilarang menekan tombol kembali, membuka aplikasi lain, atau mematikan layar HP.</p>
      </div>
    </div>

    <!-- Footer -->
    <footer class="py-2 text-xs text-slate-400">
      <router-link to="/ujian" class="text-slate-500 hover:underline">
        ← Kembali ke Input Token
      </router-link>
    </footer>

    <!-- Confirm Logout Dialog -->
    <ConfirmLogoutModal
      v-model="showLogoutConfirm"
      :loading="isLoggingOut"
      message="Apakah Anda yakin ingin keluar dari akun dan meninggalkan ruang tunggu ujian?"
      @confirm="handleConfirmedLogout"
    />
  </main>
</template>

