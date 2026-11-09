<script setup lang="ts">
import { tanggalPendek } from '@/lib/waktu'
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import StafLayout from '@/components/StafLayout.vue'
import { apiClient } from '@/api/client'
import { getEcho } from '@/lib/echo'
import { useAuthStore } from '@/stores/auth'
import { useMonitoringStore } from '@/stores/monitoring'
import {
  ALASAN_CEPAT,
  LABEL_STATUS,
  alasanValid,
  petakanPeserta,
  ringkasanStatus,
  type PesertaMentah,
  type PesertaMonitoring
} from '@/lib/monitoringLogic'
import type { PesertaStatus } from '@/stores/ujian'

interface SesiOpsi {
  id: number
  label: string
}
interface PesanPeserta {
  user_id: number | null
}

const auth = useAuthStore()
const store = useMonitoringStore()

const sesiOpsi = ref<SesiOpsi[]>([])
const sesiId = ref<number | null>(null)
const infoSesi = ref<{ jadwal: string; mapel: string; ruang: string } | null>(null)
const userIdPeserta = ref<Record<number, number | null>>({})
const ambang = ref<number | null>(null)
const errorMessage = ref('')
const feedback = ref('')
const loading = ref(false)
const suaraAktif = ref(false)
const filterStatus = ref<PesertaStatus | 'semua'>('semua')

// Modal buka kunci (FR-FE-92)
const target = ref<PesertaMonitoring | null>(null)
const alasan = ref('')
const aksiLoading = ref(false)

const ringkasan = computed(() => ringkasanStatus(store.peserta))
const tampil = computed(() =>
  filterStatus.value === 'semua' ? store.peserta : store.peserta.filter((p) => p.status === filterStatus.value)
)
const dapatAturAmbang = computed(() => auth.role === 'operator' || auth.role === 'superadmin')

async function muatDaftarSesi() {
  try {
    // Server hanya mengembalikan sesi yang boleh diawasi (guru: sesi yang ditugaskan).
    const res = await apiClient.get('/pengawasan/sesi')
    const opsi: SesiOpsi[] = (res.data.data ?? []).map(
      (s: { id: number; jadwal: string; mapel: string; ruang: string; tanggal: string | null; sesi_ke: number }) => ({
        id: s.id,
        label: `${tanggalPendek(s.tanggal)} ${s.jadwal} - ${s.mapel ?? ''} (${s.ruang ?? 'Ruang'}, sesi ${s.sesi_ke})`.trim()
      })
    )
    sesiOpsi.value = opsi
    if (!sesiId.value && opsi.length > 0) sesiId.value = opsi[0].id
  } catch (err) {
    errorMessage.value = err instanceof Error ? err.message : 'Gagal memuat daftar sesi.'
  }
}

async function muatAmbang() {
  if (!dapatAturAmbang.value) return
  try {
    const res = await apiClient.get('/lock-threshold')
    ambang.value = res.data.data?.ambang_kunci ?? null
  } catch {
    ambang.value = null
  }
}

async function muatMonitoring(tampilkanLoading = true) {
  if (!sesiId.value) return
  if (tampilkanLoading) loading.value = true
  try {
    const res = await apiClient.get(`/sesi-ruang/${sesiId.value}/monitoring`)
    const data = res.data.data
    infoSesi.value = data.sesi
    const mentah: (PesertaMentah & PesanPeserta)[] = data.peserta ?? []
    userIdPeserta.value = Object.fromEntries(mentah.map((p) => [p.id, p.user_id]))
    store.setPeserta(petakanPeserta(mentah, ambang.value))
    errorMessage.value = ''
  } catch (err) {
    errorMessage.value = err instanceof Error ? err.message : 'Gagal memuat data monitoring.'
  } finally {
    loading.value = false
  }
}

// ---------- Alert audio (FR-FE-91) ----------
let audioCtx: AudioContext | null = null
function bunyiAlert() {
  if (!suaraAktif.value) return
  try {
    audioCtx ??= new AudioContext()
    const osc = audioCtx.createOscillator()
    const gain = audioCtx.createGain()
    osc.frequency.value = 880
    gain.gain.value = 0.15
    osc.connect(gain)
    gain.connect(audioCtx.destination)
    osc.start()
    osc.stop(audioCtx.currentTime + 0.35)
  } catch {
    /* audio tidak tersedia; alert visual tetap tampil */
  }
}

function aktifkanSuara() {
  suaraAktif.value = !suaraAktif.value
  if (suaraAktif.value) bunyiAlert()
}

// ---------- Realtime + fallback polling (FR-FE-95) ----------
let channelName: string | null = null
let pollTimer: ReturnType<typeof setInterval> | null = null
let detik = 0

function tangani(hasilAlert: boolean) {
  if (hasilAlert) bunyiAlert()
}

function berhenti() {
  if (channelName) {
    try {
      getEcho().leave(channelName)
    } catch {
      /* ignore */
    }
    channelName = null
  }
}

function berlangganan(id: number) {
  berhenti()
  store.setKoneksi('menyambung')
  try {
    const echo = getEcho()
    channelName = `sesi.${id}`
    const channel = echo.private(channelName)
    channel
      .listen('.PesertaStatusChanged', (e: { peserta_id: number; status: PesertaStatus }) =>
        tangani(store.terapkan({ jenis: 'status', pesertaId: e.peserta_id, status: e.status }))
      )
      .listen('.PesertaLocked', (e: { peserta_id: number; jumlah_kunci: number }) =>
        tangani(store.terapkan({ jenis: 'kunci', pesertaId: e.peserta_id, jumlahKunci: e.jumlah_kunci }))
      )
      .listen('.PesertaUnlocked', (e: { peserta_id: number }) =>
        tangani(store.terapkan({ jenis: 'status', pesertaId: e.peserta_id, status: 'mengerjakan' }))
      )
      .listen('.PesertaSubmitted', (e: { peserta_id: number }) =>
        tangani(store.terapkan({ jenis: 'status', pesertaId: e.peserta_id, status: 'selesai' }))
      )
      .listen('.PesertaProgress', (e: { peserta_id: number; terjawab: number; sisa_detik: number }) =>
        tangani(store.terapkan({ jenis: 'progress', pesertaId: e.peserta_id, terjawab: e.terjawab, sisaDetik: e.sisa_detik }))
      )
      .listen('.LockThresholdReached', (e: { peserta_id: number; jumlah_kunci: number }) =>
        tangani(store.terapkan({ jenis: 'ambang', pesertaId: e.peserta_id, jumlahKunci: e.jumlah_kunci }))
      )

    // "Realtime" hanya bila LANGGANAN channel berhasil (koneksi WebSocket saja belum cukup:
    // otorisasi channel bisa ditolak sementara socket tetap tersambung).
    channel.subscribed(() => {
      store.setKoneksi('terhubung')
      void muatMonitoring(false)
    })
    channel.error(() => store.setKoneksi('terputus'))

    const pusher = (echo.connector as unknown as { pusher?: { connection: { bind: (n: string, cb: (s: { current: string }) => void) => void } } }).pusher
    pusher?.connection.bind('state_change', (s) => {
      if (s.current === 'connecting') store.setKoneksi('menyambung')
      else if (s.current !== 'connected') store.setKoneksi('terputus')
    })
  } catch {
    store.setKoneksi('terputus')
  }
}

// ---------- Aksi ----------
function mintaBukaKunci(p: PesertaMonitoring) {
  target.value = p
  alasan.value = ''
}

async function bukaKunci() {
  if (!target.value || !alasanValid(alasan.value)) return
  aksiLoading.value = true
  try {
    await apiClient.post(`/peserta/${target.value.id}/unlock`, { alasan: alasan.value.trim() })
    store.terapkan({ jenis: 'status', pesertaId: target.value.id, status: 'mengerjakan' })
    feedback.value = `Kunci ${target.value.nama} dibuka.`
    target.value = null
  } catch (err) {
    errorMessage.value = err instanceof Error ? err.message : 'Gagal membuka kunci.'
  } finally {
    aksiLoading.value = false
  }
}

const targetReset = ref<PesertaMonitoring | null>(null)
async function resetLogin() {
  const p = targetReset.value
  if (!p) return
  const userId = userIdPeserta.value[p.id]
  if (!userId) {
    errorMessage.value = 'Akun siswa tidak ditemukan.'
    return
  }
  aksiLoading.value = true
  try {
    await apiClient.post(`/auth/reset-login/${userId}`)
    feedback.value = `Login ${p.nama} direset. Siswa dapat masuk kembali dari perangkat lain.`
    targetReset.value = null
    await muatMonitoring(false)
  } catch (err) {
    errorMessage.value = err instanceof Error ? err.message : 'Gagal mereset login.'
  } finally {
    aksiLoading.value = false
  }
}

function formatSisa(detik: number): string {
  const dua = (n: number) => String(n).padStart(2, '0')
  return `${dua(Math.floor(detik / 3600))}:${dua(Math.floor((detik % 3600) / 60))}:${dua(detik % 60)}`
}

const WARNA: Record<PesertaStatus, string> = {
  belum_login: 'bg-slate-100 text-slate-600',
  menunggu: 'bg-sky-100 text-sky-800',
  mengerjakan: 'bg-emerald-100 text-emerald-800',
  terkunci: 'bg-red-100 text-red-800',
  selesai: 'bg-slate-200 text-slate-700'
}

watch(sesiId, async (id) => {
  if (!id) return
  await muatMonitoring()
  berlangganan(id)
})

onMounted(async () => {
  // Ambang harus siap sebelum sesi dipilih, karena pemilihan sesi langsung memuat monitoring.
  await muatAmbang()
  await muatDaftarSesi()
  pollTimer = setInterval(() => {
    detik++
    // Terhubung: rekonsiliasi tiap 60 dtk. Terputus/menyambung: polling tiap 5 dtk.
    const interval = store.koneksi === 'terhubung' ? 60 : 5
    if (detik % interval === 0) void muatMonitoring(false)
  }, 1000)
})

onUnmounted(() => {
  if (pollTimer) clearInterval(pollTimer)
  berhenti()
  store.tutupAlert()
})
</script>

<template>
  <StafLayout>
    <div class="space-y-4">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 class="text-2xl font-bold tracking-tight text-slate-900">Monitoring Pengawas</h1>
          <p class="text-sm text-slate-500">
            Status siswa realtime.
            <span v-if="infoSesi">{{ infoSesi.mapel }} - {{ infoSesi.ruang }}</span>
          </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <span
            class="rounded-full px-2.5 py-1 text-xs font-semibold"
            :class="store.koneksi === 'terhubung' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'"
            role="status"
          >
            {{ store.koneksi === 'terhubung' ? 'Langsung' : store.koneksi === 'menyambung' ? 'Menyambung...' : 'Pembaruan berkala' }}
          </span>
          <button class="btn-secondary !min-h-0 !py-1.5 text-xs" @click="aktifkanSuara">
            {{ suaraAktif ? 'Suara alert: aktif' : 'Aktifkan suara alert' }}
          </button>
        </div>
      </div>

      <div v-if="store.alertAktif" class="flex items-center justify-between rounded-xl border border-red-300 bg-red-50 p-3 text-sm font-semibold text-red-800" role="alert">
        <span>Ada siswa yang terkunci atau melewati ambang kunci. Periksa kartu berwarna merah.</span>
        <button class="rounded-lg px-2 py-1 text-xs underline" @click="store.tutupAlert()">Tutup</button>
      </div>
      <div v-if="errorMessage" class="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert">{{ errorMessage }}</div>
      <div v-if="feedback" class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800" role="status">{{ feedback }}</div>

      <div class="card flex flex-col gap-3 bg-white p-4 sm:flex-row sm:items-end">
        <label class="flex-1 text-xs font-semibold text-slate-600">
          Sesi ruang
          <select v-model="sesiId" class="input mt-1">
            <option v-for="s in sesiOpsi" :key="s.id" :value="s.id">{{ s.label }}</option>
          </select>
        </label>
        <label class="text-xs font-semibold text-slate-600">
          Filter status
          <select v-model="filterStatus" class="input mt-1">
            <option value="semua">Semua</option>
            <option v-for="(label, key) in LABEL_STATUS" :key="key" :value="key">{{ label }}</option>
          </select>
        </label>
      </div>

      <div class="grid grid-cols-2 gap-2 sm:grid-cols-5">
        <div v-for="(label, key) in LABEL_STATUS" :key="key" class="rounded-xl p-3 text-center" :class="WARNA[key]">
          <p class="text-xs font-semibold">{{ label }}</p>
          <p class="text-xl font-bold">{{ ringkasan[key] }}</p>
        </div>
      </div>

      <p v-if="sesiOpsi.length === 0 && !loading" class="card bg-white p-8 text-center text-sm text-slate-500">
        Anda belum ditugaskan mengawasi sesi ujian apa pun.
      </p>
      <p v-else-if="loading" class="py-6 text-center text-sm text-slate-500">Memuat data...</p>
      <p v-else-if="tampil.length === 0" class="py-6 text-center text-sm text-slate-500">Tidak ada peserta.</p>

      <ul class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        <li
          v-for="p in tampil"
          :key="p.id"
          class="card space-y-2 bg-white p-4"
          :class="p.status === 'terkunci' ? 'border-2 border-red-500 bg-red-50' : ''"
        >
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="truncate text-sm font-bold text-slate-900">{{ p.nama }}</p>
              <p class="text-xs text-slate-500">{{ p.nis }} - {{ p.kelas }}</p>
            </div>
            <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-bold" :class="WARNA[p.status]">{{ LABEL_STATUS[p.status] }}</span>
          </div>
          <div class="flex items-center justify-between text-xs text-slate-600">
            <span>Terjawab: <strong>{{ p.terjawab }}</strong></span>
            <span>Sisa: <strong class="font-mono">{{ p.sisaDetik > 0 ? formatSisa(p.sisaDetik) : '-' }}</strong></span>
          </div>
          <p v-if="p.jumlahKunci > 0" class="text-xs font-semibold" :class="p.melebihiAmbang ? 'text-red-700' : 'text-amber-700'">
            Dikunci {{ p.jumlahKunci }}x<span v-if="p.melebihiAmbang"> - melewati ambang{{ ambang ? ` (${ambang}x)` : '' }}, periksa siswa ini</span>
          </p>
          <div class="flex gap-2 pt-1">
            <button v-if="p.status === 'terkunci'" class="btn-primary flex-1 !min-h-touch text-xs" @click="mintaBukaKunci(p)">Buka Kunci</button>
            <button
              v-if="['menunggu', 'mengerjakan', 'terkunci'].includes(p.status)"
              class="btn-secondary flex-1 !min-h-touch text-xs"
              @click="targetReset = p"
            >
              Reset Login
            </button>
          </div>
        </li>
      </ul>
    </div>

    <!-- Modal Buka Kunci -->
    <div v-if="target" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true" aria-labelledby="judul-buka">
      <div class="card w-full max-w-md space-y-3 bg-white p-5">
        <h2 id="judul-buka" class="text-base font-bold text-slate-900">Buka kunci: {{ target.nama }}</h2>
        <p class="text-xs text-slate-500">Alasan wajib diisi (minimal 5 karakter) dan tercatat dalam riwayat aktivitas.</p>
        <div class="flex flex-wrap gap-1.5">
          <button v-for="a in ALASAN_CEPAT" :key="a" type="button" class="rounded-full border border-slate-300 px-2.5 py-1 text-xs hover:bg-slate-100" @click="alasan = a">{{ a }}</button>
        </div>
        <textarea v-model="alasan" rows="3" class="input" placeholder="Tuliskan alasan membuka kunci..."></textarea>
        <div class="flex gap-2">
          <button class="btn-secondary flex-1" :disabled="aksiLoading" @click="target = null">Batal</button>
          <button class="btn-primary flex-1" :disabled="aksiLoading || !alasanValid(alasan)" @click="bukaKunci">
            {{ aksiLoading ? 'Memproses...' : 'Buka Kunci' }}
          </button>
        </div>
      </div>
    </div>

    <!-- Modal Reset Login -->
    <div v-if="targetReset" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
      <div class="card w-full max-w-sm space-y-3 bg-white p-5">
        <h2 class="text-base font-bold text-slate-900">Reset login {{ targetReset.nama }}?</h2>
        <p class="text-sm text-slate-600">Sesi login siswa dihapus agar dapat masuk dari perangkat lain. Jawaban dan waktu ujian tidak berubah.</p>
        <div class="flex gap-2">
          <button class="btn-secondary flex-1" :disabled="aksiLoading" @click="targetReset = null">Batal</button>
          <button class="btn-primary flex-1" :disabled="aksiLoading" @click="resetLogin">{{ aksiLoading ? 'Memproses...' : 'Ya, Reset' }}</button>
        </div>
      </div>
    </div>
  </StafLayout>
</template>
