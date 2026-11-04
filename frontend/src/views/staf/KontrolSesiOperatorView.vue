<script setup lang="ts">
import { ref, onMounted, computed, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import StafLayout from '@/components/StafLayout.vue'
import { apiClient } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

const loading = ref(false)
const actionLoading = ref(false)
const feedbackMessage = ref('')
const errorMessage = ref('')

const jadwalList = ref<any[]>([])
const selectedSesiId = ref<number | null>(null)
const sesiData = ref<any>(null)
const showMulaiModal = ref(false)

// Presensi Model
const presensiState = ref<Record<number, boolean>>({})

// Guard: strictly block guru
if (auth.role !== 'superadmin' && auth.role !== 'operator') {
  router.replace({ name: 'forbidden' })
}

async function fetchJadwalList() {
  loading.value = true
  try {
    const res = await apiClient.get('/jadwal')
    jadwalList.value = res.data.data || []

    // Find first available sesi
    if (!selectedSesiId.value) {
      for (const j of jadwalList.value) {
        if (j.sesi_ruang && j.sesi_ruang.length > 0) {
          selectedSesiId.value = j.sesi_ruang[0].id
          break
        }
      }
    }

    if (selectedSesiId.value) {
      await fetchSesiDetail()
    }
  } catch (err: any) {
    errorMessage.value = err.message || 'Gagal memuat daftar jadwal.'
  } finally {
    loading.value = false
  }
}

async function fetchSesiDetail() {
  if (!selectedSesiId.value) return
  loading.value = true
  try {
    const res = await apiClient.get(`/sesi-ruang/${selectedSesiId.value}/peserta`)
    sesiData.value = res.data.data

    // Populate presensiState
    const map: Record<number, boolean> = {}
    if (sesiData.value?.peserta_ujian) {
      sesiData.value.peserta_ujian.forEach((p: any) => {
        map[p.id] = !!p.hadir
      })
    }
    presensiState.value = map
  } catch (err: any) {
    errorMessage.value = err.message || 'Gagal memuat peserta sesi ruang.'
  } finally {
    loading.value = false
  }
}

const allSessions = computed(() => {
  const list: { id: number; label: string }[] = []
  jadwalList.value.forEach(j => {
    (j.sesi_ruang || []).forEach((s: any) => {
      list.push({
        id: s.id,
        label: `${j.nama} - ${s.ruang?.nama || 'Ruang'} (Sesi ${s.sesi_ke})`
      })
    })
  })
  return list
})

async function saveKehadiran() {
  if (!selectedSesiId.value) return
  actionLoading.value = true
  errorMessage.value = ''
  feedbackMessage.value = ''

  const kehadiranPayload = Object.entries(presensiState.value).map(([pesertaId, hadir]) => ({
    peserta_id: Number(pesertaId),
    hadir: Boolean(hadir)
  }))

  try {
    await apiClient.post(`/sesi-ruang/${selectedSesiId.value}/kehadiran`, {
      kehadiran: kehadiranPayload
    })
    feedbackMessage.value = 'Presensi kehadiran peserta berhasil disimpan.'
    await fetchSesiDetail()
  } catch (err: any) {
    errorMessage.value = err.message || 'Gagal menyimpan presensi.'
  } finally {
    actionLoading.value = false
  }
}

async function mulaiSesiUjian() {
  if (!selectedSesiId.value) return
  actionLoading.value = true
  errorMessage.value = ''
  feedbackMessage.value = ''
  try {
    const res = await apiClient.post(`/sesi-ruang/${selectedSesiId.value}/mulai`)
    feedbackMessage.value = res.data.message || 'Ujian berhasil dimulai.'
    showMulaiModal.value = false
    await fetchSesiDetail()
  } catch (err: any) {
    errorMessage.value = err.message || 'Gagal memulai sesi ujian.'
  } finally {
    actionLoading.value = false
  }
}

async function mulaiPesertaIndividu(pesertaId: number) {
  if (!confirm('Mulai ujian untuk peserta susulan ini sekarang?')) return
  actionLoading.value = true
  errorMessage.value = ''
  try {
    await apiClient.post(`/peserta/${pesertaId}/mulai`)
    feedbackMessage.value = 'Ujian untuk peserta berhasil dimulai.'
    await fetchSesiDetail()
  } catch (err: any) {
    errorMessage.value = err.message || 'Gagal memulai ujian peserta.'
  } finally {
    actionLoading.value = false
  }
}

function getStatusBadge(status: string) {
  switch (status) {
    case 'belum_login':
      return { text: 'Belum Login', class: 'bg-slate-100 text-slate-700' }
    case 'menunggu':
      return { text: 'Ruang Tunggu', class: 'bg-amber-100 text-amber-800' }
    case 'mengerjakan':
      return { text: 'Mengerjakan', class: 'bg-blue-100 text-blue-800 font-bold' }
    case 'terkunci':
      return { text: 'Terkunci', class: 'bg-red-100 text-red-800 font-bold' }
    case 'selesai':
      return { text: 'Selesai', class: 'bg-green-100 text-green-800 font-bold' }
    default:
      return { text: status, class: 'bg-slate-100 text-slate-600' }
  }
}

let autoRefreshTimer: any = null

onMounted(() => {
  fetchJadwalList()
  // Polling monitoring every 10 seconds
  autoRefreshTimer = setInterval(() => {
    if (selectedSesiId.value && !actionLoading.value) {
      fetchSesiDetail()
    }
  }, 10000)
})

onUnmounted(() => {
  if (autoRefreshTimer) clearInterval(autoRefreshTimer)
})
</script>

<template>
  <StafLayout>
    <div class="space-y-6">
      <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
        <div>
          <h1 class="text-2xl font-bold text-slate-900 tracking-tight flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-6 w-6 text-brand-600">
              <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75" />
            </svg>
            <span>Panel Kontrol Sesi & Presensi Operator</span>
          </h1>
          <p class="text-sm text-slate-500">
            Verifikasi kehadiran siswa, monitoring status realtime, dan mulai ujian serentak.
          </p>
        </div>

        <div class="flex items-center gap-2">
          <button @click="fetchSesiDetail" class="btn-secondary !min-h-0 !py-2 text-xs flex items-center gap-1.5" :disabled="loading">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
            </svg>
            <span>Segarkan Data</span>
          </button>
          <button
            @click="showMulaiModal = true"
            :disabled="!selectedSesiId || actionLoading"
            class="btn-primary !min-h-0 !py-2 text-xs bg-emerald-600 hover:bg-emerald-700 flex items-center gap-1.5"
          >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4">
              <path fill-rule="evenodd" d="M4.5 5.653c0-1.427 1.529-2.33 2.779-1.643l11.54 6.347c1.295.712 1.295 2.573 0 3.286L7.28 19.99c-1.25.687-2.779-.217-2.779-1.643V5.653Z" clip-rule="evenodd" />
            </svg>
            <span>Mulai Ujian Sesi Ini</span>
          </button>
        </div>
      </div>

      <!-- Feedback Banner -->
      <div v-if="feedbackMessage" class="rounded-xl bg-green-50 p-3.5 text-sm font-medium text-status-ok border border-green-200 flex items-center gap-2">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5 shrink-0 text-emerald-600">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
        </svg>
        <span>{{ feedbackMessage }}</span>
      </div>
      <div v-if="errorMessage" class="rounded-xl bg-red-50 p-3.5 text-sm font-medium text-status-danger border border-red-200 flex items-center gap-2">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5 shrink-0 text-red-600">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
        </svg>
        <span>{{ errorMessage }}</span>
      </div>

      <!-- Sesi Selector Bar -->
      <div class="card mb-6 flex flex-wrap items-center justify-between gap-4 bg-white p-4">
        <div class="flex items-center gap-3">
          <label class="text-xs font-bold text-slate-700">Pilih Sesi Ruang:</label>
          <select
            v-model="selectedSesiId"
            @change="fetchSesiDetail"
            class="input !min-h-0 !py-1 text-sm font-semibold max-w-md"
          >
            <option v-for="s in allSessions" :key="s.id" :value="s.id">
              {{ s.label }}
            </option>
          </select>
        </div>

        <!-- Token Info -->
        <div v-if="sesiData?.token_ujian" class="flex items-center gap-2">
          <span class="text-xs text-slate-500">Token Masuk Siswa:</span>
          <span class="font-mono text-lg font-extrabold tracking-widest text-brand-700 bg-brand-50 px-3 py-1 rounded border border-brand-200">
            {{ sesiData.token_ujian.kode }}
          </span>
        </div>
      </div>

      <!-- Main Sesi Info Card -->
      <div v-if="sesiData" class="card bg-white p-5 mb-6">
        <div class="grid grid-cols-2 gap-4 border-b pb-4 sm:grid-cols-4">
          <div>
            <p class="text-xs text-slate-400">Jadwal Ujian</p>
            <p class="text-sm font-bold text-slate-800">{{ sesiData.jadwal_ujian?.nama }}</p>
          </div>
          <div>
            <p class="text-xs text-slate-400">Mata Pelajaran</p>
            <p class="text-sm font-bold text-slate-800">{{ sesiData.jadwal_ujian?.mapel?.nama }}</p>
          </div>
          <div>
            <p class="text-xs text-slate-400">Ruang & Sesi</p>
            <p class="text-sm font-bold text-slate-800">{{ sesiData.ruang?.nama }} (Sesi {{ sesiData.sesi_ke }})</p>
          </div>
          <div>
            <p class="text-xs text-slate-400">Total Peserta</p>
            <p class="text-sm font-bold text-slate-800">{{ sesiData.peserta_ujian?.length || 0 }} Siswa</p>
          </div>
        </div>

        <!-- Attendance & Participants Table -->
        <div class="mt-4">
          <div class="mb-3 flex items-center justify-between">
            <h3 class="text-sm font-bold text-slate-800">Daftar Presensi Peserta:</h3>
            <button
              @click="saveKehadiran"
              :disabled="actionLoading"
              class="btn-secondary !min-h-0 !py-1.5 text-xs flex items-center gap-1.5"
            >
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-3.5 w-3.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17.593 3.322c1.1.128 1.907 1.077 1.907 2.185V21L12 17.25 4.5 21V5.507c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0 1 11.186 0Z" />
              </svg>
              <span>{{ actionLoading ? 'Menyimpan...' : 'Simpan Status Presensi' }}</span>
            </button>
          </div>

          <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-600">
              <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-700">
                <tr>
                  <th class="p-3 w-12 text-center">Hadir</th>
                  <th class="p-3">NIS</th>
                  <th class="p-3">Nama Siswa</th>
                  <th class="p-3">Kelas</th>
                  <th class="p-3">Status Ujian</th>
                  <th class="p-3 text-right">Aksi</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr
                  v-for="p in sesiData.peserta_ujian"
                  :key="p.id"
                  class="hover:bg-slate-50"
                  :class="{'bg-red-50/50': p.status === 'terkunci'}"
                >
                  <td class="p-3 text-center">
                    <input
                      type="checkbox"
                      v-model="presensiState[p.id]"
                      class="h-4 w-4 rounded text-brand-600 cursor-pointer"
                    />
                  </td>
                  <td class="p-3 font-mono font-medium text-slate-900">{{ p.siswa?.nis }}</td>
                  <td class="p-3 font-medium text-slate-900">{{ p.siswa?.nama }}</td>
                  <td class="p-3">{{ p.siswa?.kelas?.nama || '-' }}</td>
                  <td class="p-3">
                    <span
                      class="rounded px-2 py-0.5 text-xs font-semibold"
                      :class="getStatusBadge(p.status).class"
                    >
                      {{ getStatusBadge(p.status).text }}
                    </span>
                  </td>
                  <td class="p-3 text-right">
                    <button
                      v-if="['belum_login', 'menunggu'].includes(p.status)"
                      @click="mulaiPesertaIndividu(p.id)"
                      class="text-xs font-semibold text-brand-600 hover:underline"
                    >
                      Mulai Sekarang
                    </button>
                  </td>
                </tr>
                <tr v-if="!sesiData.peserta_ujian || sesiData.peserta_ujian.length === 0">
                  <td colspan="6" class="p-6 text-center text-slate-400">
                    Belum ada siswa yang diplot ke sesi ruang ini.
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    <!-- Confirmation Modal: Mulai Ujian -->
    <div v-if="showMulaiModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div class="card w-full max-w-md bg-white">
        <h3 class="text-base font-bold text-slate-900 mb-2">Konfirmasi Mulai Ujian Serentak</h3>
        <p class="text-sm text-slate-600 mb-4">
          Apakah Anda yakin ingin memulai ujian untuk seluruh siswa yang berada di ruang tunggu pada sesi ini?
        </p>
        <div class="rounded-xl bg-amber-50 border border-amber-200 p-3.5 text-xs text-amber-800 mb-4 flex items-center gap-2">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4 shrink-0 text-amber-600">
            <path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" />
          </svg>
          <span>Durasi timer akan langsung berjalan serentak bagi semua siswa yang statusnya sudah terverifikasi.</span>
        </div>
        <div class="flex justify-end gap-2">
          <button @click="showMulaiModal = false" class="btn-secondary !min-h-0 !py-1.5 text-xs">Batal</button>
          <button
            @click="mulaiSesiUjian"
            :disabled="actionLoading"
            class="btn-primary !min-h-0 !py-1.5 text-xs bg-emerald-600 hover:bg-emerald-700"
          >
            {{ actionLoading ? 'Memulai...' : 'Ya, Mulai Sekarang' }}
          </button>
        </div>
      </div>
    </div>
    </div>
  </StafLayout>
</template>

