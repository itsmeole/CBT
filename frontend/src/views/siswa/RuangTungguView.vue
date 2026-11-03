<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { apiClient } from '@/api/client'
import { useUjianStore } from '@/stores/ujian'

const router = useRouter()
const ujianStore = useUjianStore()

const loading = ref(true)
const examState = ref<any>(null)
const errorMessage = ref('')
let pollInterval: any = null

async function checkState() {
  try {
    const res = await apiClient.get('/ujian/state')
    examState.value = res.data
    const status = res.data.status

    if (res.data.waktu_selesai_ms) {
      ujianStore.terapkanStateServer({
        status,
        waktuSelesaiMs: res.data.waktu_selesai_ms
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
    <header class="border-b border-slate-200 pb-3">
      <h1 class="text-lg font-bold text-slate-800">Ruang Tunggu Ujian</h1>
      <p class="text-xs text-slate-500">Anda telah bergabung ke dalam sesi</p>
    </header>

    <!-- Content -->
    <div class="my-auto space-y-6 py-6">
      <!-- Pulsing Waiting Animation -->
      <div class="flex flex-col items-center justify-center">
        <div class="relative flex h-20 w-20 items-center justify-center">
          <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-brand-400 opacity-75"></span>
          <span class="relative inline-flex h-16 w-16 items-center justify-center rounded-full bg-brand-600 text-3xl text-white shadow-lg">
            ⏳
          </span>
        </div>
        <h2 class="mt-4 text-base font-bold text-slate-800">Menunggu Pengawas Memulai Ujian</h2>
        <p class="mt-1 text-xs text-slate-500 max-w-xs">
          Tetap berada di halaman ini. Soal akan otomatis tampil di layar ketika pengawas menekan tombol mulai.
        </p>
      </div>

      <!-- Exam Info Card -->
      <div v-if="examState" class="card bg-white p-4 text-left space-y-2 border border-slate-200">
        <div class="flex justify-between border-b pb-2 text-xs">
          <span class="text-slate-400 font-medium">Mata Pelajaran:</span>
          <span class="font-bold text-slate-800">{{ examState.mapel || 'Ujian Sekolah' }}</span>
        </div>
        <div class="flex justify-between border-b pb-2 text-xs">
          <span class="text-slate-400 font-medium">Durasi:</span>
          <span class="font-bold text-slate-800">{{ examState.durasi_menit || 90 }} Menit</span>
        </div>
        <div class="flex justify-between text-xs">
          <span class="text-slate-400 font-medium">Status Anda:</span>
          <span class="font-bold uppercase text-amber-600">{{ examState.status || 'Menunggu' }}</span>
        </div>
      </div>

      <div class="rounded-lg bg-amber-50 border border-amber-200 p-3 text-left text-xs text-amber-800 space-y-1">
        <p class="font-bold">⚠️ Petunjuk Pengerjaan:</p>
        <p>• Waktu pengerjaan akan dihitung otomatis oleh server setelah dimulai.</p>
        <p>• Dilarang menekan tombol kembali, membuka aplikasi lain, atau mematikan layar HP.</p>
      </div>
    </div>

    <!-- Footer -->
    <footer class="py-2 text-xs text-slate-400">
      <router-link to="/ujian" class="text-slate-500 hover:underline">
        ← Kembali ke Input Token
      </router-link>
    </footer>
  </main>
</template>

