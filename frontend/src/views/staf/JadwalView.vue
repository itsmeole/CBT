<script setup lang="ts">
import { jam, tanggalPendek } from '@/lib/waktu'
import { ref, onMounted, computed, watch } from 'vue'
import { useRoute } from 'vue-router'
import { statusPilihan, toggleSemua } from '@/lib/pilihSemua'
import { TAB_JADWAL, tabValid } from '@/lib/navigasi'
import StafLayout from '@/components/StafLayout.vue'
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue'
import { apiClient } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import PenempatanRuangModal from '@/components/PenempatanRuangModal.vue'
import PengawasSesiModal from '@/components/PengawasSesiModal.vue'

type TabType = 'bobot' | 'paket' | 'jadwal'

const route = useRoute()
const auth = useAuthStore()
// Tab dipilih lewat sub-menu sidebar (query `tab`).
const activeTab = ref<TabType>(tabValid(TAB_JADWAL, route.query.tab) as TabType)
const judulTab = computed(() => TAB_JADWAL.find((t) => t.tab === activeTab.value)?.nama ?? 'Jadwal & Paket Ujian')
watch(
  () => route.query.tab,
  (tab) => {
    activeTab.value = tabValid(TAB_JADWAL, tab) as TabType
  }
)
const loading = ref(false)
const feedbackMessage = ref('')
const errorMessage = ref('')

// Action Loading & Confirmation States
const isSavingBobot = ref(false)
const isSavingPaket = ref(false)
const isSavingJadwal = ref(false)
const generatingTokenId = ref<number | null>(null)
const showDeletePaketModal = ref(false)
const paketIdToDelete = ref<number | null>(null)
const paketNameToDelete = ref('')
const isDeletingPaket = ref(false)

// Data
const bobotList = ref<any[]>([])
const paketList = ref<any[]>([])
const jadwalList = ref<any[]>([])
const mapelList = ref<any[]>([])
const bankSoalList = ref<any[]>([])

// Bobot Edit State
const editBobot = ref<Record<string, number>>({})

// Create Paket Modal State
const showCreatePaketModal = ref(false)
const formPaket = ref({
  mapel_id: null as number | null,
  nama: '',
  deskripsi: '',
  soal_ids: [] as number[]
})

// Create Jadwal Modal State
const showCreateJadwalModal = ref(false)
const formJadwal = ref({
  mapel_id: null as number | null,
  paket_soal_id: null as number | null,
  nama: '',
  tanggal: new Date().toISOString().split('T')[0],
  durasi_menit: 90
})

async function fetchAll() {
  loading.value = true
  errorMessage.value = ''
  try {
    const [bobotRes, paketRes, jadwalRes, mapelRes] = await Promise.all([
      apiClient.get('/bobot-tipe').catch(err => {
        console.warn('Gagal memuat bobot-tipe:', err)
        return { data: { data: [] } }
      }),
      apiClient.get('/paket-soal').catch(err => {
        console.warn('Gagal memuat paket-soal:', err)
        return { data: { data: [] } }
      }),
      apiClient.get('/jadwal').catch(err => {
        console.warn('Gagal memuat jadwal:', err)
        return { data: { data: [] } }
      }),
      apiClient.get('/mapel').catch(err => {
        console.warn('Gagal memuat mapel:', err)
        return { data: { data: [] } }
      })
    ])
    bobotList.value = bobotRes.data.data || []
    paketList.value = paketRes.data.data || []
    jadwalList.value = jadwalRes.data.data || []
    mapelList.value = mapelRes.data.data || []

    // Populate editBobot
    const bMap: Record<string, number> = {}
    bobotList.value.forEach(b => {
      bMap[b.tipe] = Number(b.bobot)
    })
    editBobot.value = bMap
  } catch (err: any) {
    errorMessage.value = err.message || 'Gagal memuat data jadwal & paket.'
  } finally {
    loading.value = false
  }
}

// Total Bobot Calculation
const idButir = computed(() => bankSoalList.value.map((s: any) => s.id as number))
const statusButir = computed(() => statusPilihan(idButir.value, formPaket.value.soal_ids))
function pilihSemuaButir() {
  formPaket.value.soal_ids = toggleSemua(idButir.value, formPaket.value.soal_ids)
}

const totalBobot = computed(() => {
  return Object.values(editBobot.value).reduce((sum, val) => sum + (Number(val) || 0), 0)
})

async function saveBobot() {
  loading.value = true
  isSavingBobot.value = true
  errorMessage.value = ''
  feedbackMessage.value = ''
  try {
    await apiClient.put('/bobot-tipe', { bobot: editBobot.value })
    feedbackMessage.value = 'Bobot tipe soal berhasil disimpan.'
    await fetchAll()
  } catch (err: any) {
    errorMessage.value = err.message || 'Gagal memperbarui bobot tipe soal.'
  } finally {
    loading.value = false
    isSavingBobot.value = false
  }
}

// Paket Soal Actions
async function openCreatePaket() {
  formPaket.value = {
    mapel_id: mapelList.value[0]?.id || null,
    nama: '',
    deskripsi: '',
    soal_ids: []
  }
  await onPaketMapelChange()
  showCreatePaketModal.value = true
}

async function onPaketMapelChange() {
  // Butir yang tercentang milik mapel sebelumnya tidak boleh terbawa.
  formPaket.value.soal_ids = []
  if (!formPaket.value.mapel_id) return
  try {
    const res = await apiClient.get('/bank-soal', { params: { mapel_id: formPaket.value.mapel_id } })
    bankSoalList.value = res.data.data || []
  } catch {
    bankSoalList.value = []
  }
}

async function savePaketSoal() {
  if (formPaket.value.soal_ids.length === 0) {
    alert('Pilih minimal 1 butir soal untuk dimasukkan ke dalam paket.')
    return
  }
  loading.value = true
  isSavingPaket.value = true
  errorMessage.value = ''
  try {
    await apiClient.post('/paket-soal', formPaket.value)
    feedbackMessage.value = 'Paket soal berhasil dibuat.'
    showCreatePaketModal.value = false
    await fetchAll()
  } catch (err: any) {
    errorMessage.value = err.message || 'Gagal membuat paket soal.'
  } finally {
    loading.value = false
    isSavingPaket.value = false
  }
}

function promptDeletePaket(id: number, nama: string) {
  paketIdToDelete.value = id
  paketNameToDelete.value = nama
  showDeletePaketModal.value = true
}

async function confirmDeletePaket() {
  if (!paketIdToDelete.value) return
  isDeletingPaket.value = true
  errorMessage.value = ''
  feedbackMessage.value = ''
  try {
    await apiClient.delete(`/paket-soal/${paketIdToDelete.value}`)
    feedbackMessage.value = 'Paket soal berhasil dihapus.'
    showDeletePaketModal.value = false
    paketIdToDelete.value = null
    paketNameToDelete.value = ''
    await fetchAll()
  } catch (err: any) {
    errorMessage.value = err.message || 'Gagal menghapus paket soal.'
  } finally {
    isDeletingPaket.value = false
  }
}

// Jadwal Ujian Actions
function openCreateJadwal() {
  formJadwal.value = {
    mapel_id: mapelList.value[0]?.id || null,
    paket_soal_id: paketList.value[0]?.id || null,
    nama: '',
    tanggal: new Date().toISOString().split('T')[0],
    durasi_menit: 90
  }
  showCreateJadwalModal.value = true
}

const availablePaketForJadwal = computed(() => {
  if (!formJadwal.value.mapel_id) return paketList.value
  return paketList.value.filter(p => p.mapel_id === formJadwal.value.mapel_id)
})

async function saveJadwal() {
  loading.value = true
  isSavingJadwal.value = true
  errorMessage.value = ''
  try {
    await apiClient.post('/jadwal', formJadwal.value)
    feedbackMessage.value = 'Jadwal ujian berhasil diterbitkan dengan snapshot bobot.'
    showCreateJadwalModal.value = false
    await fetchAll()
  } catch (err: any) {
    errorMessage.value = err.message || 'Gagal membuat jadwal ujian.'
  } finally {
    loading.value = false
    isSavingJadwal.value = false
  }
}

// Sesi Ruang: penempatan peserta & pengawas
interface JadwalRef { id: number; nama: string; tanggal: string; tahun_ajaran_id: number }
const jadwalPenempatan = ref<JadwalRef | null>(null)
const sesiPengawas = ref<{ sesi: { id: number; ruang: string; sesi_ke: number; jam_mulai: string; jam_akhir: string; pengawas_ids: number[] }; judul: string } | null>(null)

function bukaPenempatan(jadwal: any) {
  jadwalPenempatan.value = { id: jadwal.id, nama: jadwal.nama, tanggal: String(jadwal.tanggal).slice(0, 10), tahun_ajaran_id: jadwal.tahun_ajaran_id }
}

function bukaPengawas(jadwal: any, sesi: any) {
  sesiPengawas.value = {
    judul: `${jadwal.nama} - ${jadwal.mapel?.nama ?? ''}`,
    sesi: {
      id: sesi.id,
      ruang: sesi.ruang?.nama ?? 'Ruang',
      sesi_ke: sesi.sesi_ke,
      jam_mulai: String(sesi.jam_mulai).slice(0, 5),
      jam_akhir: String(sesi.jam_akhir).slice(0, 5),
      pengawas_ids: (sesi.pengawas_sesi ?? []).map((p: any) => p.guru_id)
    }
  }
}

async function setelahTersimpan(pesan: string) {
  jadwalPenempatan.value = null
  sesiPengawas.value = null
  feedbackMessage.value = pesan
  await fetchAll()
}

const bolehAtur = computed(() => auth.role === 'operator' || auth.role === 'superadmin')

async function generateToken(sesiId: number) {
  generatingTokenId.value = sesiId
  try {
    const res = await apiClient.post(`/sesi-ruang/${sesiId}/token/generate`)
    feedbackMessage.value = `Token baru berhasil digenerate: ${res.data.data.kode}`
    await fetchAll()
  } catch (err: any) {
    errorMessage.value = err.message || 'Gagal mengenerate token.'
  } finally {
    generatingTokenId.value = null
  }
}

onMounted(() => {
  fetchAll()
})
</script>

<template>
  <StafLayout>
    <div class="space-y-6">
      <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
        <div>
          <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Jadwal Ujian, Paket Soal, & Bobot</h1>
          <p class="text-sm text-slate-500">
            Atur bobot tipe soal, susun paket soal guru, dan terbitkan jadwal ujian dengan token sesi.
          </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <button v-if="activeTab === 'paket'" @click="openCreatePaket" class="btn-primary !min-h-0 !py-2 text-xs flex items-center gap-1.5">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
            </svg>
            <span class="sm:hidden">Paket baru</span><span class="hidden sm:inline">Buat Paket Soal Baru</span>
          </button>
          <button v-if="activeTab === 'jadwal'" @click="openCreateJadwal" class="btn-primary !min-h-0 !py-2 text-xs flex items-center gap-1.5">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
            </svg>
            <span class="sm:hidden">Terbitkan</span><span class="hidden sm:inline">Terbitkan Jadwal Ujian</span>
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

      <!-- Content Area -->
      <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="mb-4 text-base font-bold text-slate-900">{{ judulTab }}</h2>
        <!-- 1. Jadwal Ujian List -->
        <div v-if="activeTab === 'jadwal'" class="space-y-6">
          <div v-for="jadwal in jadwalList" :key="jadwal.id" class="card p-5">
            <div class="flex flex-col justify-between gap-2 border-b border-slate-100 pb-3 sm:flex-row sm:items-center">
              <div>
                <h3 class="text-base font-bold text-slate-900">{{ jadwal.nama }}</h3>
                <p class="text-xs text-slate-500">
                  Mata Pelajaran: <span class="font-semibold text-slate-700">{{ jadwal.mapel?.nama }}</span> |
                  Paket Soal: <span class="font-semibold text-slate-700">{{ jadwal.paket_soal?.nama }}</span> |
                  Durasi: <span class="font-semibold text-slate-700">{{ jadwal.durasi_menit }} Menit</span>
                </p>
              </div>
              <div class="flex items-center gap-2">
                <span class="rounded bg-brand-50 px-2 py-1 text-xs font-bold text-brand-700">
                  Tanggal: {{ tanggalPendek(jadwal.tanggal) }}
                </span>
                <button v-if="bolehAtur" @click="bukaPenempatan(jadwal)" class="btn-primary !min-h-0 !py-1 text-xs">
                  <span class="sm:hidden">Penempatan</span><span class="hidden sm:inline">Atur Penempatan Peserta</span>
                </button>
              </div>
            </div>

            <!-- Sesi Ruang List inside Jadwal -->
            <div class="mt-4">
              <h4 class="text-xs font-bold text-slate-500 mb-2">Sesi Ruang & Token Ujian:</h4>
              <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                <div
                  v-for="sesi in jadwal.sesi_ruang"
                  :key="sesi.id"
                  class="rounded-lg border border-slate-200 bg-slate-50 p-3"
                >
                  <div class="flex items-center justify-between">
                    <div>
                      <p class="text-xs font-bold text-slate-800">
                        Sesi {{ sesi.sesi_ke }} - {{ sesi.ruang?.nama }}
                      </p>
                      <p class="text-[11px] text-slate-500">
                        Waktu: {{ jam(sesi.jam_mulai) }} - {{ jam(sesi.jam_akhir) }}
                      </p>
                    </div>

                    <!-- Token Display -->
                    <div class="text-right">
                      <p class="text-[10px] text-slate-400 font-bold">Token Ujian</p>
                      <span class="font-mono text-base font-extrabold tracking-widest text-brand-700 bg-brand-50 px-2 py-0.5 rounded border border-brand-200">
                        {{ sesi.token_ujian?.kode || 'BELUM ADA' }}
                      </span>
                    </div>
                  </div>

                  <div class="mt-2 text-[11px] text-slate-600">
                    <span class="font-semibold text-slate-700">Pengawas:</span>
                    <span v-if="(sesi.pengawas_sesi ?? []).length > 0">{{ sesi.pengawas_sesi.map((p: any) => p.guru?.user?.name).filter(Boolean).join(', ') }}</span>
                    <span v-else class="italic text-amber-700">belum ditetapkan</span>
                    <button v-if="bolehAtur" class="ml-2 font-semibold text-brand-600 hover:underline" @click="bukaPengawas(jadwal, sesi)">Atur pengawas</button>
                  </div>

                  <div class="mt-2 flex items-center justify-between border-t border-slate-200 pt-2">
                    <span class="text-[11px] text-slate-500">
                      Peserta: <strong>{{ sesi.peserta_ujian_count ?? 0 }}</strong> / {{ sesi.ruang?.kapasitas || 0 }} kursi
                    </span>
                    <button
                      @click="generateToken(sesi.id)"
                      :disabled="generatingTokenId === sesi.id"
                      class="text-xs font-semibold text-brand-600 hover:underline flex items-center gap-1 cursor-pointer disabled:opacity-50"
                    >
                      <span v-if="generatingTokenId === sesi.id" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-brand-600 border-t-transparent"></span>
                      <svg v-else xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-3.5 w-3.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                      </svg>
                      <span>{{ generatingTokenId === sesi.id ? 'Merilis...' : 'Token baru' }}</span>
                    </button>
                  </div>
                </div>
              </div>

              <p v-if="!jadwal.sesi_ruang || jadwal.sesi_ruang.length === 0" class="text-xs italic text-slate-400">
                Belum ada penempatan peserta untuk jadwal ini. Klik "Atur Penempatan Peserta".
              </p>
            </div>
          </div>

          <div v-if="jadwalList.length === 0" class="text-center py-12 text-slate-400 text-sm">
            Belum ada jadwal ujian yang dibuat. Klik "+ Terbitkan Jadwal Ujian".
          </div>
        </div>

        <!-- 2. Paket Soal List -->
        <div v-if="activeTab === 'paket'" class="space-y-4">
          <div v-for="paket in paketList" :key="paket.id" class="card p-4">
            <div class="flex items-start justify-between">
              <div>
                <h3 class="text-sm font-bold text-slate-800">{{ paket.nama }}</h3>
                <p class="text-xs text-slate-500">
                  Mata Pelajaran: <span class="font-semibold text-slate-700">{{ paket.mapel?.nama }}</span> |
                  Dibuat oleh: <span class="font-semibold text-slate-700">{{ paket.guru?.user?.nama || 'Guru' }}</span>
                </p>
                <p v-if="paket.deskripsi" class="text-xs text-slate-600 mt-1">{{ paket.deskripsi }}</p>
              </div>
              <div class="flex items-center gap-2">
                <span class="rounded bg-brand-100 px-2 py-0.5 text-xs font-bold text-brand-800">
                  {{ paket.items?.length || 0 }} Butir Soal
                </span>
                <button
                  type="button"
                  @click="promptDeletePaket(paket.id, paket.nama)"
                  class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 hover:border-red-300 hover:bg-red-50 hover:text-red-600 focus:outline-none focus:ring-2 focus:ring-red-200 transition-all shadow-2xs active:scale-95 cursor-pointer"
                  title="Hapus Paket Soal"
                  aria-label="Hapus Paket Soal"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                  </svg>
                </button>
              </div>
            </div>
          </div>

          <div v-if="paketList.length === 0" class="text-center py-12 text-slate-400 text-sm">
            Belum ada paket soal. Klik "+ Buat Paket Soal Baru".
          </div>
        </div>

        <!-- 3. Konfigurasi Bobot Tipe Soal -->
        <div v-if="activeTab === 'bobot'" class="max-w-xl">
          <p class="text-xs text-slate-600 mb-4">
            Atur bobot standar per tipe soal. Ketika jadwal ujian dibuat, bobot ini akan di-snapshot ke jadwal ujian tersebut.
          </p>

          <form @submit.prevent="saveBobot" class="space-y-3">
            <div
              v-for="tipe in ['pg', 'pg_kompleks', 'benar_salah', 'menjodohkan', 'essay']"
              :key="tipe"
              class="flex items-center justify-between border-b pb-2"
            >
              <div>
                <p class="text-sm font-bold capitalize text-slate-800">{{ tipe.replace('_', ' ') }}</p>
                <p class="text-xs text-slate-400">Bobot kontribusi nilai</p>
              </div>
              <input
                v-model.number="editBobot[tipe]"
                type="number"
                step="0.1"
                min="0"
                class="input !min-h-0 !py-1 text-sm w-24 text-right"
                required
              />
            </div>

            <div class="flex items-center justify-between pt-2">
              <span class="text-sm font-bold text-slate-700">Total Akumulasi Bobot:</span>
              <span class="text-base font-extrabold text-brand-700">{{ totalBobot }}</span>
            </div>

            <div class="pt-4">
              <button
                type="submit"
                class="btn-primary !min-h-0 !py-2 text-xs flex items-center justify-center gap-1.5 cursor-pointer disabled:opacity-50"
                :disabled="isSavingBobot"
              >
                <span v-if="isSavingBobot" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white border-t-transparent"></span>
                <span>{{ isSavingBobot ? 'Menyimpan Bobot...' : 'Simpan Perubahan Bobot' }}</span>
              </button>
            </div>
          </form>
        </div>
      </div>

    <!-- Create Paket Soal Modal -->
    <div v-if="showCreatePaketModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div class="card w-full max-w-2xl bg-white max-h-[90vh] overflow-y-auto">
        <div class="mb-4 flex items-center justify-between border-b pb-3">
          <h2 class="text-base font-bold text-slate-800">Buat Paket Soal Baru</h2>
          <button @click="showCreatePaketModal = false" class="rounded-lg p-1 text-slate-400 hover:text-slate-600 hover:bg-slate-100">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <form @submit.prevent="savePaketSoal" class="space-y-4">
          <div>
            <label class="block text-xs font-medium text-slate-700">Mata Pelajaran</label>
            <select v-model="formPaket.mapel_id" @change="onPaketMapelChange" required class="input !min-h-0 !py-1.5 text-sm">
              <option v-for="m in mapelList" :key="m.id" :value="m.id">{{ m.kode }} - {{ m.nama }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-700">Nama Paket</label>
            <input v-model="formPaket.nama" placeholder="Contoh: Paket Ujian Tengah Semester Ganjil" required class="input !min-h-0 !py-1.5 text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-700">Deskripsi (Opsional)</label>
            <textarea v-model="formPaket.deskripsi" rows="2" class="input text-sm"></textarea>
          </div>

          <!-- Question Selector Checkboxes -->
          <div>
            <div class="mb-2 flex items-center justify-between gap-2">
              <span class="text-xs font-bold text-slate-700">
                Pilih Butir Soal ({{ formPaket.soal_ids.length }} dari {{ bankSoalList.length }} terpilih):
              </span>
              <label v-if="bankSoalList.length > 0" class="flex cursor-pointer items-center gap-1.5 rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                <input
                  type="checkbox"
                  class="rounded text-brand-600"
                  :checked="statusButir === 'semua'"
                  :indeterminate="statusButir === 'sebagian'"
                  aria-label="Pilih semua butir soal"
                  @change="pilihSemuaButir"
                />
                <span>{{ statusButir === 'semua' ? 'Kosongkan semua' : 'Pilih semua' }}</span>
              </label>
            </div>
            <div class="max-h-56 overflow-y-auto border rounded-lg p-2 space-y-2">
              <label
                v-for="soal in bankSoalList"
                :key="soal.id"
                class="flex items-start gap-2 p-2 rounded hover:bg-slate-50 cursor-pointer border text-xs"
              >
                <input type="checkbox" :value="soal.id" v-model="formPaket.soal_ids" class="mt-0.5 rounded text-brand-600" />
                <div class="flex-1">
                  <span class="rounded bg-slate-200 px-1 py-0.5 text-[10px] font-bold uppercase mr-1">{{ soal.tipe }}</span>
                  <span class="text-slate-800">{{ soal.konten.replace(/<[^>]*>/g, '').slice(0, 80) }}...</span>
                </div>
              </label>
              <p v-if="bankSoalList.length === 0" class="text-xs text-slate-400 italic text-center py-4">
                Belum ada butir soal untuk mata pelajaran ini di Bank Soal.
              </p>
            </div>
          </div>

          <div class="flex justify-end gap-2 border-t pt-3">
            <button type="button" @click="showCreatePaketModal = false" :disabled="isSavingPaket" class="btn-secondary !min-h-0 !py-2 text-xs cursor-pointer">Batal</button>
            <button
              type="submit"
              class="btn-primary !min-h-0 !py-2 text-xs flex items-center justify-center gap-1.5 cursor-pointer disabled:opacity-50"
              :disabled="isSavingPaket"
            >
              <span v-if="isSavingPaket" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white border-t-transparent"></span>
              <span>{{ isSavingPaket ? 'Menyimpan...' : 'Simpan Paket Soal' }}</span>
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Create Jadwal Modal -->
    <div v-if="showCreateJadwalModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div class="card w-full max-w-md bg-white">
        <div class="mb-4 flex items-center justify-between border-b pb-3">
          <h2 class="text-base font-bold text-slate-800">Terbitkan Jadwal Ujian</h2>
          <button @click="showCreateJadwalModal = false" class="rounded-lg p-1 text-slate-400 hover:text-slate-600 hover:bg-slate-100">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <form @submit.prevent="saveJadwal" class="space-y-3">
          <div>
            <label class="block text-xs font-medium text-slate-700">Mata Pelajaran</label>
            <select v-model="formJadwal.mapel_id" required class="input !min-h-0 !py-1.5 text-sm">
              <option v-for="m in mapelList" :key="m.id" :value="m.id">{{ m.kode }} - {{ m.nama }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-700">Paket Soal</label>
            <select v-model="formJadwal.paket_soal_id" required class="input !min-h-0 !py-1.5 text-sm">
              <option v-for="p in availablePaketForJadwal" :key="p.id" :value="p.id">{{ p.nama }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-700">Nama Ujian</label>
            <input v-model="formJadwal.nama" placeholder="Contoh: Ujian Akhir Semester" required class="input !min-h-0 !py-1.5 text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-700">Tanggal Pelaksanaan</label>
            <input v-model="formJadwal.tanggal" type="date" required class="input !min-h-0 !py-1.5 text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-700">Durasi Pengerjaan (Menit)</label>
            <input v-model="formJadwal.durasi_menit" type="number" min="1" required class="input !min-h-0 !py-1.5 text-sm" />
          </div>

          <div class="flex justify-end gap-2 border-t pt-3">
            <button type="button" @click="showCreateJadwalModal = false" :disabled="isSavingJadwal" class="btn-secondary !min-h-0 !py-2 text-xs cursor-pointer">Batal</button>
            <button
              type="submit"
              class="btn-primary !min-h-0 !py-2 text-xs flex items-center justify-center gap-1.5 cursor-pointer disabled:opacity-50"
              :disabled="isSavingJadwal"
            >
              <span v-if="isSavingJadwal" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white border-t-transparent"></span>
              <span>{{ isSavingJadwal ? 'Menerbitkan...' : 'Terbitkan Jadwal' }}</span>
            </button>
          </div>
        </form>
      </div>
    </div>

    </div>

    <PenempatanRuangModal v-if="jadwalPenempatan" :jadwal="jadwalPenempatan" @close="jadwalPenempatan = null" @saved="setelahTersimpan('Penempatan peserta ke ruang berhasil disimpan.')" />
    <PengawasSesiModal v-if="sesiPengawas" :sesi="sesiPengawas.sesi" :judul="sesiPengawas.judul" @close="sesiPengawas = null" @saved="setelahTersimpan('Pengawas sesi berhasil disimpan.')" />

    <!-- Confirm Delete Modal: Paket Soal -->
    <ConfirmDeleteModal
      v-model="showDeletePaketModal"
      :loading="isDeletingPaket"
      title="Hapus Paket Soal"
      :message="`Apakah Anda yakin ingin menghapus paket soal &quot;${paketNameToDelete}&quot;? Tindakan ini bersifat permanen dan tidak dapat dibatalkan.`"
      confirm-text="Ya, Hapus Paket"
      cancel-text="Batal"
      @confirm="confirmDeletePaket"
    />
  </StafLayout>
</template>

