<script setup lang="ts">
import TabelMemuat from '@/components/TabelMemuat.vue'
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
const searchKeyword = ref('')

// Presensi Model
const presensiState = ref<Record<number, boolean>>({})

// Guard: allow guru, operator, and superadmin
if (auth.role !== 'superadmin' && auth.role !== 'operator' && auth.role !== 'guru') {
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

const filteredPeserta = computed(() => {
  if (!sesiData.value?.peserta_ujian) return []
  const q = searchKeyword.value.toLowerCase().trim()
  if (!q) return sesiData.value.peserta_ujian
  return sesiData.value.peserta_ujian.filter((p: any) => {
    const nama = (p.siswa?.nama || '').toLowerCase()
    const nis = (p.siswa?.nis || '').toLowerCase()
    const kelas = (p.siswa?.kelas?.nama || '').toLowerCase()
    return nama.includes(q) || nis.includes(q) || kelas.includes(q)
  })
})

const statusCounts = computed(() => {
  const counts = { belum_login: 0, menunggu: 0, mengerjakan: 0, terkunci: 0, selesai: 0 }
  if (!sesiData.value?.peserta_ujian) return counts
  sesiData.value.peserta_ujian.forEach((p: any) => {
    if (p.status in counts) {
      counts[p.status as keyof typeof counts]++
    }
  })
  return counts
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

function copyToken(token: string) {
  if (!token) return
  navigator.clipboard.writeText(token)
  feedbackMessage.value = `Token "${token}" berhasil disalin ke clipboard.`
}

// Action Confirmation Modal State
interface ActionModalConfig {
  title: string
  message: string
  confirmText: string
  theme: 'amber' | 'red' | 'blue' | 'emerald'
  handler: () => Promise<void>
}

const showActionModal = ref(false)
const actionModalConfig = ref<ActionModalConfig | null>(null)
const actionModalLoading = ref(false)

function openActionModal(config: ActionModalConfig) {
  actionModalConfig.value = config
  showActionModal.value = true
}

async function executeActionModal() {
  if (!actionModalConfig.value) return
  actionModalLoading.value = true
  errorMessage.value = ''
  try {
    await actionModalConfig.value.handler()
    showActionModal.value = false
    actionModalConfig.value = null
  } catch (err: any) {
    errorMessage.value = err.message || 'Gagal memproses aksi.'
  } finally {
    actionModalLoading.value = false
  }
}

function promptMulaiPesertaIndividu(pesertaId: number) {
  openActionModal({
    title: 'Mulai Ujian Susulan',
    message: 'Mulai ujian untuk peserta susulan ini sekarang? Timer ujian peserta akan langsung berjalan.',
    confirmText: 'Ya, Mulai Ujian',
    theme: 'blue',
    handler: async () => {
      await apiClient.post(`/peserta/${pesertaId}/mulai`)
      feedbackMessage.value = 'Ujian untuk peserta susulan berhasil dimulai.'
      await fetchSesiDetail()
    }
  })
}

function promptResetLoginPeserta(p: any) {
  const userId = p.siswa?.user?.id
  const namaSiswa = p.siswa?.nama || 'Siswa'
  if (!userId) {
    errorMessage.value = 'Data akun pengguna siswa tidak ditemukan.'
    return
  }
  openActionModal({
    title: 'Reset Sesi Login Siswa',
    message: `Reset sesi login untuk siswa "${namaSiswa}"? Siswa dapat segera login kembali di perangkatnya.`,
    confirmText: 'Ya, Reset Sesi',
    theme: 'amber',
    handler: async () => {
      const res = await apiClient.post(`/auth/reset-login/${userId}`)
      feedbackMessage.value = res.data.message || `Sesi login "${namaSiswa}" berhasil direset.`
      await fetchSesiDetail()
    }
  })
}

function promptBukaKunciPeserta(p: any) {
  const namaSiswa = p.siswa?.nama || 'Siswa'
  openActionModal({
    title: 'Buka Kunci Ujian',
    message: `Buka kunci ujian untuk siswa "${namaSiswa}" agar dapat melanjutkan pengerjaan ujian?`,
    confirmText: 'Ya, Buka Kunci',
    theme: 'red',
    handler: async () => {
      const res = await apiClient.post(`/peserta/${p.id}/unlock`, {
        alasan: 'Buka kunci ujian oleh guru/pengawas'
      })
      feedbackMessage.value = res.data.message || `Kunci ujian siswa "${namaSiswa}" berhasil dibuka.`
      await fetchSesiDetail()
    }
  })
}

function promptResetSemuaLoginSesi() {
  if (!sesiData.value?.peserta_ujian?.length) return
  openActionModal({
    title: 'Reset Sesi Semua Login Siswa',
    message: 'Apakah Anda yakin ingin mereset sesi login semua siswa pada ruang ini? Gunakan tindakan ini bila terjadi kendala jaringan massal di ruangan.',
    confirmText: 'Ya, Reset Semua Sesi',
    theme: 'amber',
    handler: async () => {
      let successCount = 0
      for (const p of sesiData.value.peserta_ujian) {
        if (p.siswa?.user?.id && p.status !== 'selesai') {
          try {
            await apiClient.post(`/auth/reset-login/${p.siswa.user.id}`)
            successCount++
          } catch {
            // ignore individual error
          }
        }
      }
      feedbackMessage.value = `Berhasil mereset sesi login ${successCount} siswa.`
      await fetchSesiDetail()
    }
  })
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
            <span>Kontrol Sesi Ujian</span>
          </h1>
          <p class="text-sm text-slate-500">
            Monitoring peserta realtime, reset sesi login siswa, buka kunci, dan mulai ujian serentak.
          </p>
        </div>

        <div class="flex items-center gap-2">
          <button @click="fetchSesiDetail" aria-label="Segarkan" title="Segarkan" class="btn-secondary !min-h-0 !py-2 text-xs flex items-center gap-1.5 cursor-pointer disabled:opacity-60" :disabled="loading">
            <span v-if="loading" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-slate-600 border-t-transparent"></span>
            <svg v-else xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
            </svg>
            <span class="hidden sm:inline">{{ loading ? 'Menyegarkan...' : 'Segarkan' }}</span>
          </button>
          <button
            @click="promptResetSemuaLoginSesi"
            :disabled="!selectedSesiId || actionLoading"
            class="btn-secondary !min-h-0 !py-2 text-xs border-amber-300 text-amber-700 bg-amber-50/50 hover:bg-amber-100 flex items-center gap-1.5 cursor-pointer disabled:opacity-50"
            title="Reset login semua siswa aktif di ruangan ini"
          >
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
            </svg>
            <span class="sm:hidden">Reset login</span><span class="hidden sm:inline">Reset Semua Login</span>
          </button>
          <button
            @click="showMulaiModal = true"
            :disabled="!selectedSesiId || actionLoading"
            class="btn-primary !min-h-0 !py-2 text-xs bg-emerald-600 hover:bg-emerald-700 flex items-center gap-1.5 cursor-pointer disabled:opacity-50 shadow-xs"
          >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4">
              <path fill-rule="evenodd" d="M4.5 5.653c0-1.427 1.529-2.33 2.779-1.643l11.54 6.347c1.295.712 1.295 2.573 0 3.286L7.28 19.99c-1.25.687-2.779-.217-2.779-1.643V5.653Z" clip-rule="evenodd" />
            </svg>
            <span class="sm:hidden">Mulai ujian</span><span class="hidden sm:inline">Mulai Ujian Sesi Ini</span>
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
      <div class="card mb-6 bg-white p-4 sm:p-5 rounded-2xl">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
          <div class="flex flex-col sm:flex-row sm:items-center gap-2.5 sm:gap-3 flex-1 min-w-0">
            <label for="select-sesi-ruang" class="text-xs font-bold text-slate-600 whitespace-nowrap flex items-center gap-1.5 shrink-0">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4 text-brand-600">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
              </svg>
              <span>Pilih Sesi Ruang:</span>
            </label>
            <div class="flex items-center gap-2.5 w-full sm:w-auto flex-1 max-w-xl">
              <select
                id="select-sesi-ruang"
                v-model="selectedSesiId"
                @change="fetchSesiDetail"
                :disabled="loading || allSessions.length === 0"
                class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm font-semibold text-slate-800 shadow-2xs focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 disabled:bg-slate-50 disabled:text-slate-400 cursor-pointer"
              >
                <option v-if="allSessions.length === 0" :value="null" disabled>
                  -- Tidak ada sesi ruang aktif --
                </option>
                <option v-for="s in allSessions" :key="s.id" :value="s.id">
                  {{ s.label }}
                </option>
              </select>
              <span v-if="loading" class="h-4 w-4 animate-spin rounded-full border-2 border-brand-600 border-t-transparent shrink-0"></span>
            </div>
          </div>

          <!-- Token Info -->
          <div v-if="sesiData?.token_ujian" class="flex items-center gap-2.5 bg-slate-50 border border-slate-200/80 px-3.5 py-2 rounded-xl shrink-0 self-start md:self-auto">
            <span class="text-xs font-semibold text-slate-500 whitespace-nowrap">Token Ujian:</span>
            <span class="font-mono text-base font-extrabold tracking-widest text-brand-700 bg-white px-2.5 py-0.5 rounded-lg border border-brand-200 shadow-2xs">
              {{ sesiData.token_ujian.kode }}
            </span>
            <button
              type="button"
              @click="copyToken(sesiData.token_ujian.kode)"
              class="text-slate-400 hover:text-brand-600 transition-colors p-1 rounded-md hover:bg-white cursor-pointer"
              title="Salin Kode Token"
              aria-label="Salin Kode Token"
            >
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 0 0-3.375-3.375h-1.5a1.125 1.125 0 0 1-1.125-1.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H9.75" />
              </svg>
            </button>
          </div>
        </div>
      </div>

      <!-- Empty State if No Sessions Exist -->
      <div v-if="allSessions.length === 0 && !loading" class="card bg-white p-8 rounded-2xl border border-dashed border-slate-300 text-center space-y-3">
        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-500">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-6 w-6">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
          </svg>
        </div>
        <h3 class="text-sm font-bold text-slate-800">Belum Ada Sesi Ruang Ujian Aktif</h3>
        <p class="text-xs text-slate-500 max-w-md mx-auto leading-relaxed">
          Tidak ditemukan jadwal ujian dengan sesi ruang yang aktif. Silakan terbitkan jadwal ujian dan buat sesi ruang pada menu Jadwal & Paket Ujian.
        </p>
        <div class="pt-2">
          <router-link to="/jadwal" class="btn-secondary !min-h-0 !py-2 text-xs font-semibold inline-flex items-center gap-1.5">
            <span>Buka Jadwal & Paket Ujian</span>
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-3.5 w-3.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
            </svg>
          </router-link>
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

        <!-- Status Summary Badges -->
        <div class="mt-4 flex flex-wrap items-center gap-2">
          <span class="rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
            Belum Login: {{ statusCounts.belum_login }}
          </span>
          <span class="rounded-lg bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800">
            Ruang Tunggu: {{ statusCounts.menunggu }}
          </span>
          <span class="rounded-lg bg-blue-100 px-2.5 py-1 text-xs font-bold text-blue-800">
            Mengerjakan: {{ statusCounts.mengerjakan }}
          </span>
          <span class="rounded-lg bg-red-100 px-2.5 py-1 text-xs font-bold text-red-800">
            Terkunci: {{ statusCounts.terkunci }}
          </span>
          <span class="rounded-lg bg-green-100 px-2.5 py-1 text-xs font-bold text-green-800">
            Selesai: {{ statusCounts.selesai }}
          </span>
        </div>

        <!-- Attendance & Participants Table -->
        <div class="mt-5">
          <div class="mb-3 flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
            <div class="flex items-center gap-3">
              <h3 class="text-sm font-bold text-slate-800">Daftar Peserta & Presensi</h3>
              <input
                v-model="searchKeyword"
                type="text"
                placeholder="Cari nama atau NIS siswa..."
                class="input !min-h-0 !py-1 text-xs w-56"
              />
            </div>
            <button
              @click="saveKehadiran"
              :disabled="actionLoading"
              class="btn-secondary !min-h-0 !py-2 text-xs flex items-center gap-1.5 cursor-pointer disabled:opacity-50"
            >
              <span v-if="actionLoading" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-slate-600 border-t-transparent"></span>
              <svg v-else xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-3.5 w-3.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17.593 3.322c1.1.128 1.907 1.077 1.907 2.185V21L12 17.25 4.5 21V5.507c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0 1 11.186 0Z" />
              </svg>
              <span>{{ actionLoading ? 'Menyimpan Presensi...' : 'Simpan Status Presensi' }}</span>
            </button>
          </div>

          <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-600">
              <thead class="bg-slate-50 text-xs font-semibold text-slate-700">
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
                  v-for="p in filteredPeserta"
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
                    <div class="flex items-center justify-end gap-1.5 flex-wrap">
                      <button
                        v-if="['belum_login', 'menunggu'].includes(p.status)"
                        @click="promptMulaiPesertaIndividu(p.id)"
                        class="btn-secondary !min-h-0 !py-1 !px-2.5 text-xs text-brand-700 bg-brand-50 hover:bg-brand-100 border-brand-200 cursor-pointer"
                        title="Mulai ujian untuk siswa ini"
                      >
                        Mulai
                      </button>
                      <button
                        v-if="p.status === 'terkunci'"
                        @click="promptBukaKunciPeserta(p)"
                        class="btn-secondary !min-h-0 !py-1 !px-2.5 text-xs text-red-700 bg-red-50 hover:bg-red-100 border-red-200 cursor-pointer"
                        title="Buka kunci ujian siswa"
                      >
                        Buka Kunci
                      </button>
                      <button
                        v-if="p.siswa?.user?.id && p.status !== 'selesai'"
                        @click="promptResetLoginPeserta(p)"
                        class="btn-secondary !min-h-0 !py-1 !px-2.5 text-xs text-amber-700 bg-amber-50 hover:bg-amber-100 border-amber-200 flex items-center gap-1 cursor-pointer"
                        title="Reset sesi login agar siswa dapat login ulang"
                      >
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-3.5 w-3.5">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                        </svg>
                        <span>Reset Login</span>
                      </button>
                    </div>
                  </td>
                </tr>
                <TabelMemuat v-if="loading && filteredPeserta.length === 0" :kolom="6" />
                <tr v-if="!loading && filteredPeserta.length === 0">
                  <td colspan="6" class="p-6 text-center text-slate-400">
                    {{ searchKeyword ? 'Tidak ada peserta yang cocok dengan pencarian.' : 'Belum ada siswa yang diplot ke sesi ruang ini.' }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Confirmation Modal: Mulai Ujian Serentak -->
      <div v-if="showMulaiModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-xs p-4 animate-in fade-in duration-150">
        <div class="card w-full max-w-md bg-white p-6 shadow-xl rounded-2xl border border-slate-100 space-y-4 animate-in zoom-in-95 duration-150">
          <div class="flex items-start gap-3">
            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600 border border-emerald-100">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="h-5 w-5">
                <path fill-rule="evenodd" d="M4.5 5.653c0-1.427 1.529-2.33 2.779-1.643l11.54 6.347c1.295.712 1.295 2.573 0 3.286L7.28 19.99c-1.25.687-2.779-.217-2.779-1.643V5.653Z" clip-rule="evenodd" />
              </svg>
            </div>
            <div class="space-y-1">
              <h3 class="text-base font-bold text-slate-900">Konfirmasi Mulai Ujian Serentak</h3>
              <p class="text-xs text-slate-500 leading-relaxed">
                Apakah Anda yakin ingin memulai ujian untuk seluruh siswa yang berada di ruang tunggu pada sesi ini?
              </p>
            </div>
          </div>

          <div class="rounded-xl bg-amber-50 border border-amber-200/80 p-3 text-xs text-amber-800 flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4 shrink-0 text-amber-600">
              <path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" />
            </svg>
            <span>Durasi timer pengerjaan akan langsung berjalan serentak bagi semua siswa yang statusnya sudah terverifikasi.</span>
          </div>

          <div class="flex justify-end gap-2.5 pt-2">
            <button @click="showMulaiModal = false" :disabled="actionLoading" class="btn-secondary !min-h-0 !py-2 text-xs cursor-pointer">Batal</button>
            <button
              @click="mulaiSesiUjian"
              :disabled="actionLoading"
              class="btn-primary !min-h-0 !py-2 text-xs bg-emerald-600 hover:bg-emerald-700 flex items-center justify-center gap-1.5 cursor-pointer disabled:opacity-50"
            >
              <span v-if="actionLoading" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white border-t-transparent"></span>
              <span>{{ actionLoading ? 'Memulai Ujian...' : 'Ya, Mulai Sekarang' }}</span>
            </button>
          </div>
        </div>
      </div>

      <!-- Action Confirmation Modal (Reset Login, Buka Kunci, Mulai Susulan) -->
      <div v-if="showActionModal && actionModalConfig" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-xs p-4 animate-in fade-in duration-150">
        <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl border border-slate-100 text-center space-y-4 animate-in zoom-in-95 duration-150">
          <!-- Icon Badge based on theme -->
          <div
            class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl border shadow-2xs"
            :class="{
              'bg-amber-50 text-amber-600 border-amber-100': actionModalConfig.theme === 'amber',
              'bg-red-50 text-red-600 border-red-100': actionModalConfig.theme === 'red',
              'bg-blue-50 text-blue-600 border-blue-100': actionModalConfig.theme === 'blue',
              'bg-emerald-50 text-emerald-600 border-emerald-100': actionModalConfig.theme === 'emerald'
            }"
          >
            <svg v-if="actionModalConfig.theme === 'amber'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-6 w-6">
              <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
            </svg>
            <svg v-else-if="actionModalConfig.theme === 'red'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-6 w-6">
              <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H3.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
            </svg>
            <svg v-else xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-6 w-6">
              <path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" />
            </svg>
          </div>

          <!-- Text -->
          <div class="space-y-1.5">
            <h3 class="text-base font-bold text-slate-900">
              {{ actionModalConfig.title }}
            </h3>
            <p class="text-xs text-slate-500 leading-relaxed">
              {{ actionModalConfig.message }}
            </p>
          </div>

          <!-- Action Buttons -->
          <div class="flex items-center gap-2.5 pt-1">
            <button
              type="button"
              @click="showActionModal = false"
              :disabled="actionModalLoading"
              class="flex-1 rounded-xl border border-slate-200 bg-white py-2.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 active:scale-[0.98] transition-all disabled:opacity-50 cursor-pointer"
            >
              Batal
            </button>
            <button
              type="button"
              @click="executeActionModal"
              :disabled="actionModalLoading"
              class="flex-1 rounded-xl py-2.5 text-xs font-semibold text-white shadow-xs active:scale-[0.98] transition-all flex items-center justify-center gap-1.5 disabled:opacity-50 cursor-pointer"
              :class="{
                'bg-amber-600 hover:bg-amber-700': actionModalConfig.theme === 'amber',
                'bg-red-600 hover:bg-red-700': actionModalConfig.theme === 'red',
                'bg-blue-600 hover:bg-blue-700': actionModalConfig.theme === 'blue',
                'bg-emerald-600 hover:bg-emerald-700': actionModalConfig.theme === 'emerald'
              }"
            >
              <span v-if="actionModalLoading" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white border-t-transparent"></span>
              <span>{{ actionModalLoading ? 'Memproses...' : actionModalConfig.confirmText }}</span>
            </button>
          </div>
        </div>
      </div>
    </div>
  </StafLayout>
</template>
