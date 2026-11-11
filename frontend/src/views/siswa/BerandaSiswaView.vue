<script setup lang="ts">
import { jam } from '@/lib/waktu'
import { ref, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { apiClient } from '@/api/client'
import ConfirmLogoutModal from '@/components/ConfirmLogoutModal.vue'

const auth = useAuthStore()
const router = useRouter()

const showLogoutConfirm = ref(false)
const isLoggingOut = ref(false)

interface SesiItem {
  peserta_id?: number | null
  sesi_ruang_id: number
  status: 'belum_login' | 'menunggu' | 'mengerjakan' | 'terkunci' | 'selesai'
  mapel_id?: number
  nama_mapel: string
  kode_mapel?: string
  nama_ujian: string
  tanggal?: string
  jam_mulai?: string
  jam_akhir?: string
  durasi_menit?: number
  ruang: string
  sesi_ke: number
}

const sesiList = ref<SesiItem[]>([])
const loadingSesi = ref(true)
const selectedSesi = ref<SesiItem | null>(null)

const token = ref('')
const loadingJoin = ref(false)
const errorMessage = ref('')

async function fetchSesiTersedia() {
  loadingSesi.value = true
  try {
    const res = await apiClient.get('/ujian/sesi')
    sesiList.value = res.data.data || []
  } catch {
    sesiList.value = []
  } finally {
    loadingSesi.value = false
  }
}

/** Daftar sesi bergantung pada jam sesi: segarkan berkala (tanpa kedip) agar sesi muncul saat waktunya tiba. */
async function segarkanSenyap() {
  if (document.visibilityState !== 'visible' || selectedSesi.value) return
  try {
    const res = await apiClient.get('/ujian/sesi')
    sesiList.value = res.data.data || []
  } catch {
    /* pertahankan daftar yang ada */
  }
}

let timerSegarkan: ReturnType<typeof setInterval> | null = null

onMounted(() => {
  fetchSesiTersedia()
  timerSegarkan = setInterval(segarkanSenyap, 30_000)
})

onUnmounted(() => {
  if (timerSegarkan) clearInterval(timerSegarkan)
})

function selectSesi(sesi: SesiItem) {
  if (sesi.status === 'mengerjakan') {
    router.push({ name: 'siswa-kerjakan' })
    return
  }
  if (sesi.status === 'menunggu') {
    router.push({ name: 'siswa-tunggu' })
    return
  }
  if (sesi.status === 'selesai') {
    return
  }

  selectedSesi.value = sesi
  token.value = ''
  errorMessage.value = ''
}

function deselectSesi() {
  selectedSesi.value = null
  token.value = ''
  errorMessage.value = ''
}

function onTokenInput(e: Event) {
  const target = e.target as HTMLInputElement
  token.value = target.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 6)
}

async function joinUjian() {
  if (token.value.length < 6) {
    errorMessage.value = 'Token ujian harus terdiri dari 6 karakter.'
    return
  }
  loadingJoin.value = true
  errorMessage.value = ''
  try {
    const payload: { token: string; sesi_ruang_id?: number } = {
      token: token.value
    }
    if (selectedSesi.value?.sesi_ruang_id) {
      payload.sesi_ruang_id = selectedSesi.value.sesi_ruang_id
    }
    const res = await apiClient.post('/ujian/join', payload)
    if (res.data.data?.status === 'mengerjakan') {
      router.push({ name: 'siswa-kerjakan' })
    } else {
      router.push({ name: 'siswa-tunggu' })
    }
  } catch (err: any) {
    errorMessage.value = err.message || 'Token tidak valid atau sesi belum dibuka.'
  } finally {
    loadingJoin.value = false
  }
}

function requestLogout() {
  showLogoutConfirm.value = true
}

async function handleConfirmedLogout() {
  isLoggingOut.value = true
  try {
    await auth.logout()
    showLogoutConfirm.value = false
    router.replace({ name: 'login' })
  } finally {
    isLoggingOut.value = false
  }
}
</script>

<template>
  <div class="min-h-[100dvh] bg-slate-50 font-sans text-slate-800 flex flex-col justify-between">
    <!-- Top Header Bar -->
    <header class="sticky top-0 z-20 border-b border-slate-200 bg-white/95 backdrop-blur px-4 py-3 sm:px-6 shadow-xs">
      <div class="mx-auto flex max-w-2xl items-center justify-between">
        <div class="flex items-center gap-3">
          <img src="/logo.webp" alt="ICBT" class="h-10 w-10 shrink-0 object-contain rounded-xl" />
          <div>
            <h1 class="text-sm font-extrabold text-slate-900 leading-tight">ICBT Siswa</h1>
            <p class="text-xs text-slate-500 font-medium">
              {{ auth.user?.nama }} <span class="text-slate-400">({{ auth.user?.username || 'Peserta' }})</span>
            </p>
          </div>
        </div>

        <button
          type="button"
          @click="requestLogout"
          class="flex items-center gap-1.5 rounded-xl border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-red-50 hover:text-red-600 hover:border-red-200 active:scale-95 transition-all"
        >
          <!-- Logout SVG -->
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12.75" />
          </svg>
          <span class="hidden sm:inline">Keluar</span>
        </button>
      </div>
    </header>

    <!-- Main Content Container -->
    <main class="mx-auto w-full max-w-2xl flex-1 p-4 sm:p-6 flex flex-col justify-center">
      <!-- ======================================================== -->
      <!-- TAHAP 1: PILIH MATA PELAJARAN                            -->
      <!-- ======================================================== -->
      <section v-if="!selectedSesi" class="space-y-5 animate-fade-in">
        <div>
          <span class="inline-block text-xs font-semibold text-brand-700 mb-1">
            Langkah 1 dari 2
          </span>
          <h2 class="text-xl sm:text-2xl font-extrabold text-slate-900 tracking-tight">
            Pilih Mata Pelajaran Ujian
          </h2>
          <p class="text-xs sm:text-sm text-slate-500 mt-1">
            Silakan pilih mata pelajaran ujian yang dijadwalkan untuk Anda ikuti hari ini.
          </p>
        </div>

        <!-- Loading State Skeleton -->
        <div v-if="loadingSesi" class="space-y-3">
          <div v-for="i in 3" :key="i" class="h-24 w-full rounded-2xl bg-white border border-slate-200/80 p-4 animate-pulse">
            <div class="h-4 w-1/3 bg-slate-200 rounded mb-2"></div>
            <div class="h-3 w-1/2 bg-slate-100 rounded"></div>
          </div>
        </div>

        <!-- Exam Session Cards Grid -->
        <div v-else-if="sesiList.length > 0" class="space-y-3">
          <div
            v-for="sesi in sesiList"
            :key="sesi.sesi_ruang_id"
            @click="selectSesi(sesi)"
            class="card bg-white p-4 sm:p-5 rounded-xl border-l-4 transition-all duration-200 text-left group"
            :class="[
              sesi.status === 'selesai'
                ? 'opacity-60 border-l-slate-300 cursor-not-allowed bg-slate-50/50'
                : sesi.status === 'mengerjakan'
                  ? 'border-l-brand-600 ring-brand-600/30 cursor-pointer hover:shadow-md active:scale-[0.99]'
                  : sesi.status === 'menunggu'
                    ? 'border-l-amber-500 cursor-pointer hover:shadow-md active:scale-[0.99]'
                    : 'border-l-transparent cursor-pointer hover:border-l-brand-500 hover:shadow-md active:scale-[0.99]'
            ]"
          >
            <div class="flex items-start justify-between gap-3">
              <div class="flex items-start gap-3.5">
                <!-- Subject Icon -->
                <div
                  class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl transition-colors"
                  :class="[
                    sesi.status === 'selesai'
                      ? 'bg-slate-100 text-slate-400'
                      : 'bg-brand-50 text-brand-600 group-hover:bg-brand-600 group-hover:text-white'
                  ]"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-6 w-6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
                  </svg>
                </div>

                <div>
                  <div class="flex items-center gap-2">
                    <h3 class="text-base font-bold text-slate-900 group-hover:text-brand-600 transition-colors">
                      {{ sesi.nama_mapel }}
                    </h3>
                    <span v-if="sesi.kode_mapel" class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-600 font-mono">
                      {{ sesi.kode_mapel }}
                    </span>
                  </div>
                  <p class="text-xs text-slate-500 mt-0.5">{{ sesi.nama_ujian }}</p>

                  <!-- Session Details Meta -->
                  <div class="mt-2.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500 font-medium">
                    <span class="flex items-center gap-1">
                      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-3.5 w-3.5 text-slate-400">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                      </svg>
                      {{ sesi.durasi_menit || 90 }} Menit
                    </span>
                    <span class="flex items-center gap-1">
                      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-3.5 w-3.5 text-slate-400">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" />
                      </svg>
                      {{ sesi.ruang }}
                    </span>
                    <span v-if="sesi.jam_mulai && sesi.jam_akhir" class="text-slate-400 font-mono">
                      {{ jam(sesi.jam_mulai) }} - {{ jam(sesi.jam_akhir) }}
                    </span>
                  </div>
                </div>
              </div>

              <!-- Status Badge / Action Chevron -->
              <div class="shrink-0 flex items-center gap-2">
                <span
                  v-if="sesi.status === 'selesai'"
                  class="rounded-md bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700 flex items-center gap-1"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-3.5 w-3.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                  </svg>
                  Selesai
                </span>
                <span
                  v-else-if="sesi.status === 'mengerjakan'"
                  class="rounded-md bg-brand-600 px-2.5 py-1 text-xs font-bold text-white flex items-center gap-1"
                >
                  Lanjutkan
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-3.5 w-3.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                  </svg>
                </span>
                <span
                  v-else-if="sesi.status === 'menunggu'"
                  class="rounded-md bg-amber-100 px-2.5 py-1 text-xs font-bold text-amber-700 flex items-center gap-1"
                >
                  Ruang Tunggu
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-3.5 w-3.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                  </svg>
                </span>
                <span
                  v-else
                  class="rounded-xl p-2 text-slate-400 group-hover:text-brand-600 group-hover:bg-brand-50 transition-colors"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-5 w-5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                  </svg>
                </span>
              </div>
            </div>
          </div>
        </div>

        <!-- Empty State -->
        <div v-else class="card bg-white p-8 rounded-2xl text-center space-y-3">
          <div class="flex h-14 w-14 mx-auto items-center justify-center rounded-2xl bg-slate-100 text-slate-400">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-7 w-7">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
            </svg>
          </div>
          <h3 class="text-base font-bold text-slate-800">Tidak Ada Jadwal Ujian Aktif</h3>
          <p class="text-xs text-slate-500 max-w-sm mx-auto leading-relaxed">
            Belum ada jadwal ujian yang ditugaskan untuk akun Anda saat ini. Silakan hubungi pengawas ruang atau operator sekolah.
          </p>
          <div class="pt-2">
            <button
              @click="fetchSesiTersedia"
              class="btn-secondary !min-h-0 !py-2 text-xs inline-flex items-center gap-1.5"
            >
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
              </svg>
              <span>Periksa Ulang Jadwal</span>
            </button>
          </div>
        </div>
      </section>

      <!-- ======================================================== -->
      <!-- TAHAP 2: MASUKKAN TOKEN UJIAN                            -->
      <!-- ======================================================== -->
      <section v-else class="space-y-5 animate-fade-in">
        <!-- Back Button & Step Badge -->
        <div class="flex items-center justify-between">
          <button
            type="button"
            @click="deselectSesi"
            class="flex items-center gap-1.5 text-xs font-semibold text-slate-500 hover:text-slate-800 transition-colors"
          >
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
            </svg>
            <span>Pilih Mata Pelajaran Lain</span>
          </button>
          <span class="inline-block text-xs font-semibold text-brand-700">
            Langkah 2 dari 2
          </span>
        </div>

        <!-- Selected Subject Card Summary -->
        <div class="rounded-2xl border border-brand-200 bg-brand-50/60 p-4 text-left flex items-center justify-between">
          <div class="flex items-center gap-3">
            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-600 text-white shadow-xs">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-6 w-6">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
              </svg>
            </div>
            <div>
              <p class="text-xs font-semibold text-brand-700">Mata Pelajaran Terpilih</p>
              <h3 class="text-base font-extrabold text-slate-900 leading-tight">
                {{ selectedSesi.nama_mapel }}
              </h3>
              <p class="text-xs text-slate-500 mt-0.5">{{ selectedSesi.nama_ujian }} • {{ selectedSesi.ruang }}</p>
            </div>
          </div>
        </div>

        <!-- Token Entry Card -->
        <div class="card bg-white p-6 sm:p-8 rounded-2xl space-y-6">
          <div class="text-center">
            <div class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-blue-50 text-brand-600 shadow-xs">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-7 w-7">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z" />
              </svg>
            </div>
            <h2 class="text-xl font-extrabold text-slate-900 tracking-tight">Masukkan Token Ujian</h2>
            <p class="text-xs sm:text-sm text-slate-500 mt-1">
              Minta 6 karakter token kepada pengawas ruang Anda
            </p>
          </div>

          <form @submit.prevent="joinUjian" class="space-y-5">
            <div>
              <input
                :value="token"
                @input="onTokenInput"
                type="text"
                inputmode="text"
                maxlength="6"
                placeholder="CONTOH: 7K9X2B"
                autocomplete="off"
                autofocus
                required
                class="w-full rounded-2xl border-2 border-slate-200 bg-white px-4 py-3.5 text-center font-mono text-3xl font-black tracking-widest text-slate-900 transition-all focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100 placeholder:text-slate-300 placeholder:text-xl placeholder:font-sans placeholder:font-normal placeholder:tracking-normal !min-h-[60px]"
              />
            </div>

            <div
              v-if="errorMessage"
              role="alert"
              class="rounded-xl bg-red-50 border border-red-200 px-3.5 py-2.5 text-xs font-semibold text-status-danger flex items-center justify-center gap-2"
            >
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4 shrink-0 text-red-600">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
              </svg>
              <span>{{ errorMessage }}</span>
            </div>

            <button
              type="submit"
              :disabled="token.length < 6 || loadingJoin"
              class="btn-primary w-full text-base font-bold !min-h-[52px] rounded-xl disabled:opacity-50 cursor-pointer flex items-center justify-center gap-2"
            >
              <span>{{ loadingJoin ? 'Memeriksa Token...' : 'Masuk Ruang Ujian' }}</span>
              <svg v-if="!loadingJoin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4">
                <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
              </svg>
            </button>
          </form>
        </div>
      </section>
    </main>

    <!-- Footer -->
    <footer class="py-4 text-center text-xs text-slate-400">
      <p>© {{ new Date().getFullYear() }} ICBT</p>
    </footer>

    <!-- Confirm Logout Dialog -->
    <ConfirmLogoutModal
      v-model="showLogoutConfirm"
      :loading="isLoggingOut"
      message="Apakah Anda yakin ingin keluar dari akun ujian siswa ini?"
      @confirm="handleConfirmedLogout"
    />
  </div>
</template>

<style scoped>
@keyframes fadeIn {
  from {
    opacity: 0;
    transform: translateY(6px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.animate-fade-in {
  animation: fadeIn 0.25s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
</style>
