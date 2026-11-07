<script setup lang="ts">
import { ref, onMounted, computed, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { apiClient } from '@/api/client'
import { useUjianStore, type PesertaStatus } from '@/stores/ujian'
import { renderMathInHtml } from '@/lib/katex'
import { antrekanJawaban, bersihkanSemua } from '@/lib/offlineQueue'
import { kirimLock, kosongkanAntrean, type HasilSinkron } from '@/lib/syncEngine'
import {
  normalisasiSoal,
  nomorUrutBerikut,
  sudahDijawab,
  type NilaiJawaban,
  type PemicuLock,
  type SoalUjian
} from '@/lib/ujianKontrak'

type Jawaban = NilaiJawaban | undefined

const router = useRouter()
const ujianStore = useUjianStore()

const loading = ref(true)
const errorMessage = ref('')
const soalList = ref<SoalUjian[]>([])
const currentIndex = ref(0)
const answers = ref<Record<number, Jawaban>>({})
const raguMap = ref<Record<number, boolean>>({})

type SyncStatus = 'saved' | 'saving' | 'offline'
const syncStatus = ref<SyncStatus>('saved')
let clientSeq = 0

const showNavDrawer = ref(false)
const showSubmitModal = ref(false)
const submitLoading = ref(false)

const currentSoal = computed<SoalUjian | undefined>(() => soalList.value[currentIndex.value])
const terkunci = computed(() => ujianStore.terkunci)

const formattedTimer = computed(() => {
  const s = ujianStore.sisaWaktuDetik
  const jam = Math.floor(s / 3600)
  const menit = Math.floor((s % 3600) / 60)
  const detik = s % 60
  const dua = (n: number) => String(n).padStart(2, '0')
  return `${dua(jam)}:${dua(menit)}:${dua(detik)}`
})

const timerWarningClass = computed(() => {
  const s = ujianStore.sisaWaktuDetik
  if (s <= 300) return 'bg-red-600 text-white animate-pulse'
  if (s <= 600) return 'bg-amber-500 text-white'
  return 'bg-slate-800 text-white'
})

const totalSoalCount = computed(() => soalList.value.length)
const terjawabCount = computed(() => soalList.value.filter((s) => sudahDijawab(answers.value[s.soalId])).length)
const belumTerjawabCount = computed(() => totalSoalCount.value - terjawabCount.value)
const raguCount = computed(() => Object.values(raguMap.value).filter(Boolean).length)

function sama(a: unknown, b: unknown): boolean {
  return String(a) === String(b)
}

function pilihanKompleks(soalId: number): (string | number)[] {
  const j = answers.value[soalId]
  return Array.isArray(j) ? j : []
}

function petaJawaban(soalId: number): Record<string, string | number> {
  const j = answers.value[soalId]
  return j && typeof j === 'object' && !Array.isArray(j) ? j : {}
}

/** Muat daftar soal. Hanya valid saat status `mengerjakan` (BE menolak saat terkunci). */
async function muatSoal(): Promise<void> {
  const res = await apiClient.get('/ujian/soal')
  soalList.value = normalisasiSoal(res.data.data ?? [])
}

async function sinkronStateServer(): Promise<PesertaStatus | null> {
  const res = await apiClient.get('/ujian/state')
  const state = res.data.data
  if (!state) return null
  if (state.status === 'selesai') {
    await bersihkanSemua()
    router.replace({ name: 'siswa-selesai' })
    return 'selesai'
  }
  ujianStore.terapkanStateServer({ status: state.status, waktuSelesaiMs: state.waktu_selesai_ms ?? null })
  return state.status as PesertaStatus
}

async function sinkronJamServer(): Promise<void> {
  try {
    const res = await apiClient.get('/time')
    ujianStore.sinkronWaktu(res.data.time)
  } catch {
    /* offset 0 dipakai sementara; timer tetap berjalan dari waktu_selesai server */
  }
}

async function fetchExamData() {
  loading.value = true
  errorMessage.value = ''
  try {
    await sinkronJamServer()
    const res = await apiClient.get('/ujian/state')
    const state = res.data.data

    if (state.status === 'selesai') {
      router.replace({ name: 'siswa-selesai' })
      return
    }
    if (state.status === 'menunggu' || state.status === 'belum_login') {
      router.replace({ name: 'siswa-tunggu' })
      return
    }

    ujianStore.terapkanStateServer({ status: state.status, waktuSelesaiMs: state.waktu_selesai_ms ?? null })
    const tersimpan: Record<string, NilaiJawaban> = state.jawaban_tersimpan ?? {}
    answers.value = Object.fromEntries(Object.entries(tersimpan).map(([id, j]) => [Number(id), j]))

    // Saat terkunci soal tidak dimuat; overlay tampil dan dimuat setelah dibuka kuncinya.
    if (state.status === 'mengerjakan') await muatSoal()
  } catch (err) {
    errorMessage.value = err instanceof Error ? err.message : 'Gagal memuat soal ujian.'
  } finally {
    loading.value = false
  }
}

// ---------- Autosave (FR-FE-30, FR-FE-32, FR-FE-33) ----------
let saveDebounceTimer: ReturnType<typeof setTimeout> | null = null

function triggerAutosave(soalId: number) {
  if (terkunci.value) return
  syncStatus.value = 'saving'
  clientSeq = nomorUrutBerikut(clientSeq, Date.now())

  antrekanJawaban({
    soalId: String(soalId),
    jawaban: answers.value[soalId] ?? null,
    clientSeq,
    waktuMs: Date.now()
  })
    .catch(() => {})
    .then(() => {
      if (saveDebounceTimer) clearTimeout(saveDebounceTimer)
      saveDebounceTimer = setTimeout(sinkronkan, 400)
    })
}

async function sinkronkan(): Promise<HasilSinkron> {
  const hasil = await kosongkanAntrean()
  if (hasil === 'offline') {
    syncStatus.value = 'offline'
  } else if (hasil === 'terkunci') {
    ujianStore.terapkanStateServer({ status: 'terkunci', waktuSelesaiMs: ujianStore.waktuSelesaiMs })
  } else if (hasil === 'selesai') {
    await sinkronStateServer()
  } else {
    syncStatus.value = 'saved'
  }
  return hasil
}

function selectPgAnswer(opsiId: number | string) {
  if (!currentSoal.value || terkunci.value) return
  answers.value[currentSoal.value.soalId] = opsiId
  triggerAutosave(currentSoal.value.soalId)
}

function togglePgKompleksAnswer(opsiId: number | string) {
  if (!currentSoal.value || terkunci.value) return
  const id = currentSoal.value.soalId
  const arr = [...pilihanKompleks(id)]
  const idx = arr.findIndex((v) => sama(v, opsiId))
  if (idx >= 0) arr.splice(idx, 1)
  else arr.push(opsiId)
  answers.value[id] = arr
  triggerAutosave(id)
}

function setBenarSalah(opsiId: number | string, val: 'BENAR' | 'SALAH') {
  if (!currentSoal.value || terkunci.value) return
  const id = currentSoal.value.soalId
  answers.value[id] = { ...petaJawaban(id), [String(opsiId)]: val }
  triggerAutosave(id)
}

function setMenjodohkan(kiriId: number | string, kananId: string) {
  if (!currentSoal.value || terkunci.value) return
  const id = currentSoal.value.soalId
  const next = { ...petaJawaban(id) }
  if (kananId === '') delete next[String(kiriId)]
  else next[String(kiriId)] = kananId
  answers.value[id] = next
  triggerAutosave(id)
}

function onEssayInput(event: Event) {
  if (!currentSoal.value || terkunci.value) return
  const id = currentSoal.value.soalId
  answers.value[id] = (event.target as HTMLTextAreaElement).value
  triggerAutosave(id)
}

function toggleRagu() {
  if (!currentSoal.value) return
  const sid = currentSoal.value.soalId
  raguMap.value[sid] = !raguMap.value[sid]
}

function goToQuestion(idx: number) {
  if (idx >= 0 && idx < soalList.value.length) {
    currentIndex.value = idx
    showNavDrawer.value = false
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }
}

function nextQuestion() {
  if (currentIndex.value < soalList.value.length - 1) goToQuestion(currentIndex.value + 1)
  else showSubmitModal.value = true
}

function prevQuestion() {
  if (currentIndex.value > 0) goToQuestion(currentIndex.value - 1)
}

// ---------- Submit (FR-FE-70) ----------
async function submitExam() {
  if (submitLoading.value) return
  submitLoading.value = true
  try {
    // Pastikan antrean jawaban terkirim sebelum menyerahkan ujian.
    await kosongkanAntrean()
    await apiClient.post('/ujian/submit')
    await bersihkanSemua()
    router.replace({ name: 'siswa-selesai' })
  } catch (err) {
    showSubmitModal.value = false
    errorMessage.value = err instanceof Error ? err.message : 'Gagal mengumpulkan ujian. Silakan coba kembali.'
  } finally {
    submitLoading.value = false
  }
}

// ---------- Kunci sesi (FR-FE-50, FR-FE-51, FR-FE-54) ----------
let sudahFullscreen = false

function laporkanPelanggaran(pemicu: PemicuLock) {
  if (ujianStore.status !== 'mengerjakan') return
  // Kunci tampilan seketika; server tetap sumber kebenaran status (dikonfirmasi lewat cek berkala).
  ujianStore.terapkanStateServer({ status: 'terkunci', waktuSelesaiMs: ujianStore.waktuSelesaiMs })
  showSubmitModal.value = false
  showNavDrawer.value = false
  void kirimLock(pemicu)
}

function onVisibilityChange() {
  if (document.visibilityState === 'hidden') laporkanPelanggaran('visibilitychange')
}
const onPageHide = () => laporkanPelanggaran('pagehide')
const onBlur = () => laporkanPelanggaran('blur')
const onOffline = () => {
  syncStatus.value = 'offline'
}
function onFullscreenChange() {
  if (document.fullscreenElement) {
    sudahFullscreen = true
  } else if (sudahFullscreen) {
    sudahFullscreen = false
    laporkanPelanggaran('fullscreenchange')
  }
}
async function onOnline() {
  await sinkronkan()
}

function pulihkanDariKunci() {
  // Dipanggil saat server melaporkan status kembali `mengerjakan`.
  if (soalList.value.length === 0) void muatSoal()
  void sinkronkan()
}

async function cekStatusBerkala() {
  const sebelumnya = ujianStore.status
  try {
    const status = await sinkronStateServer()
    if (status === 'mengerjakan' && sebelumnya === 'terkunci') pulihkanDariKunci()
    if (status === 'menunggu' || status === 'belum_login') router.replace({ name: 'siswa-tunggu' })
  } catch {
    /* jaringan putus: coba lagi pada interval berikutnya */
  }
}

// ---------- Anti-curang (FR-FE-60) ----------
function preventCheatingEvents(e: Event) {
  e.preventDefault()
  return false
}

let tickTimer: ReturnType<typeof setInterval> | null = null
let tickSecond = 0

onMounted(() => {
  fetchExamData()

  tickTimer = setInterval(() => {
    ujianStore.tick()
    tickSecond++
    if (ujianStore.waktuSelesaiMs && ujianStore.sisaWaktuDetik <= 0 && !submitLoading.value) {
      void submitExam()
    }
    // Kunci dicek tiap 3 detik agar unlock cepat terasa; saat berjalan normal tiap 15 detik.
    if (tickSecond % (terkunci.value ? 3 : 15) === 0) void cekStatusBerkala()
    // Coba kirim ulang antrean bila sebelumnya offline.
    if (syncStatus.value === 'offline' && tickSecond % 5 === 0) void sinkronkan()
  }, 1000)

  document.addEventListener('contextmenu', preventCheatingEvents)
  document.addEventListener('copy', preventCheatingEvents)
  document.addEventListener('cut', preventCheatingEvents)
  document.addEventListener('paste', preventCheatingEvents)
  document.addEventListener('selectstart', preventCheatingEvents)

  document.addEventListener('visibilitychange', onVisibilityChange)
  document.addEventListener('fullscreenchange', onFullscreenChange)
  window.addEventListener('pagehide', onPageHide)
  window.addEventListener('blur', onBlur)
  window.addEventListener('offline', onOffline)
  window.addEventListener('online', onOnline)
})

onUnmounted(() => {
  if (tickTimer) clearInterval(tickTimer)
  if (saveDebounceTimer) clearTimeout(saveDebounceTimer)

  document.removeEventListener('contextmenu', preventCheatingEvents)
  document.removeEventListener('copy', preventCheatingEvents)
  document.removeEventListener('cut', preventCheatingEvents)
  document.removeEventListener('paste', preventCheatingEvents)
  document.removeEventListener('selectstart', preventCheatingEvents)

  document.removeEventListener('visibilitychange', onVisibilityChange)
  document.removeEventListener('fullscreenchange', onFullscreenChange)
  window.removeEventListener('pagehide', onPageHide)
  window.removeEventListener('blur', onBlur)
  window.removeEventListener('offline', onOffline)
  window.removeEventListener('online', onOnline)
})
</script>

<template>
  <div class="min-h-screen bg-slate-100 pb-24 text-slate-800 select-none">
    <header class="sticky top-0 z-40 flex items-center justify-between border-b border-slate-200 bg-white px-4 py-2.5 shadow-sm">
      <button
        @click="showNavDrawer = true"
        class="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-bold text-slate-800 min-h-touch min-w-touch"
      >
        <span>No. {{ currentIndex + 1 }} / {{ soalList.length }}</span>
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4 text-slate-500">
          <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
        </svg>
      </button>

      <div class="flex items-center gap-1.5 text-xs font-medium" role="status" aria-live="polite">
        <span v-if="syncStatus === 'saving'" class="flex items-center gap-1 text-brand-600">
          <span class="inline-block h-2 w-2 animate-ping rounded-full bg-brand-600"></span>
          Menyimpan...
        </span>
        <span v-else-if="syncStatus === 'saved'" class="flex items-center gap-1 text-status-ok font-semibold">Tersimpan</span>
        <span v-else class="flex items-center gap-1 text-status-danger font-semibold">Offline (Tersimpan Lokal)</span>
      </div>

      <div
        class="flex items-center gap-1.5 rounded-xl px-2.5 py-1 font-mono text-xs font-bold tracking-wider transition-colors min-h-touch"
        :class="timerWarningClass"
      >
        <span>{{ formattedTimer }}</span>
      </div>
    </header>

    <div v-if="loading" class="p-10 text-center text-sm text-slate-500">Memuat soal ujian...</div>
    <div v-else-if="errorMessage" class="mx-auto max-w-xl p-4">
      <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700" role="alert">{{ errorMessage }}</div>
      <button class="btn-primary mt-3" @click="fetchExamData">Coba Lagi</button>
    </div>

    <main v-else-if="currentSoal" class="mx-auto max-w-xl p-4 sm:p-6">
      <div class="card bg-white p-5 shadow-sm space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
          <div class="flex items-center gap-2">
            <span class="flex h-7 w-7 items-center justify-center rounded-full bg-brand-600 font-bold text-xs text-white">
              {{ currentIndex + 1 }}
            </span>
            <span class="rounded bg-slate-100 px-2 py-0.5 text-xs font-bold uppercase text-slate-700">
              {{ currentSoal.tipe.replace('_', ' ') }}
            </span>
          </div>

          <label class="flex items-center gap-1.5 text-xs font-bold text-amber-700 cursor-pointer">
            <input type="checkbox" :checked="raguMap[currentSoal.soalId]" @change="toggleRagu" class="h-4 w-4 rounded text-amber-500" />
            <span>Ragu-ragu</span>
          </label>
        </div>

        <div class="prose prose-base max-w-none text-slate-800 text-[16px] leading-relaxed" dir="auto" v-html="renderMathInHtml(currentSoal.konten)"></div>

        <!-- 1. Pilihan Ganda -->
        <div v-if="currentSoal.tipe === 'pg'" class="space-y-2 pt-2">
          <div
            v-for="(opsi, oIdx) in currentSoal.opsi"
            :key="opsi.id"
            @click="selectPgAnswer(opsi.id)"
            class="flex items-center gap-3 rounded-xl border p-3.5 transition-all cursor-pointer min-h-touch"
            :class="sama(answers[currentSoal.soalId], opsi.id) ? 'border-brand-600 bg-brand-50/70 font-semibold' : 'border-slate-200 bg-white hover:bg-slate-50'"
          >
            <div
              class="flex h-6 w-6 items-center justify-center rounded-full border text-xs font-bold shrink-0"
              :class="sama(answers[currentSoal.soalId], opsi.id) ? 'border-brand-600 bg-brand-600 text-white' : 'border-slate-300 text-slate-600'"
            >
              {{ String.fromCharCode(65 + oIdx) }}
            </div>
            <div class="text-[16px] flex-1" dir="auto" v-html="renderMathInHtml(opsi.konten)"></div>
          </div>
        </div>

        <!-- 2. PG Kompleks -->
        <div v-if="currentSoal.tipe === 'pg_kompleks'" class="space-y-2 pt-2">
          <p class="text-xs font-semibold text-slate-500 italic">Pilih satu atau lebih jawaban yang benar:</p>
          <div
            v-for="(opsi, oIdx) in currentSoal.opsi"
            :key="opsi.id"
            @click="togglePgKompleksAnswer(opsi.id)"
            class="flex items-center gap-3 rounded-xl border p-3.5 transition-all cursor-pointer min-h-touch"
            :class="pilihanKompleks(currentSoal.soalId).some((v) => sama(v, opsi.id)) ? 'border-brand-600 bg-brand-50/70 font-semibold' : 'border-slate-200 bg-white hover:bg-slate-50'"
          >
            <input
              type="checkbox"
              :checked="pilihanKompleks(currentSoal.soalId).some((v) => sama(v, opsi.id))"
              class="h-5 w-5 rounded text-brand-600 pointer-events-none shrink-0"
            />
            <span class="font-bold text-xs text-slate-500 shrink-0">{{ String.fromCharCode(65 + oIdx) }}.</span>
            <div class="text-[16px] flex-1" dir="auto" v-html="renderMathInHtml(opsi.konten)"></div>
          </div>
        </div>

        <!-- 3. Benar / Salah -->
        <div v-if="currentSoal.tipe === 'benar_salah'" class="space-y-3 pt-2">
          <p class="text-xs font-semibold text-slate-500 italic">Tentukan nilai kebenaran untuk tiap pernyataan:</p>
          <div v-for="opsi in currentSoal.opsi" :key="opsi.id" class="rounded-xl border border-slate-200 p-3 bg-slate-50 space-y-2">
            <div class="text-[15px] font-medium text-slate-800" dir="auto" v-html="renderMathInHtml(opsi.konten)"></div>
            <div class="flex gap-2">
              <button
                type="button"
                @click="setBenarSalah(opsi.id, 'BENAR')"
                class="flex-1 rounded-lg border py-2 text-xs font-bold transition-all min-h-touch"
                :class="petaJawaban(currentSoal.soalId)[String(opsi.id)] === 'BENAR' ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-300 bg-white text-slate-700'"
              >
                Benar
              </button>
              <button
                type="button"
                @click="setBenarSalah(opsi.id, 'SALAH')"
                class="flex-1 rounded-lg border py-2 text-xs font-bold transition-all min-h-touch"
                :class="petaJawaban(currentSoal.soalId)[String(opsi.id)] === 'SALAH' ? 'border-red-600 bg-red-600 text-white' : 'border-slate-300 bg-white text-slate-700'"
              >
                Salah
              </button>
            </div>
          </div>
        </div>

        <!-- 4. Menjodohkan (dropdown, bukan drag-and-drop) -->
        <div v-if="currentSoal.tipe === 'menjodohkan'" class="space-y-3 pt-2">
          <p class="text-xs font-semibold text-slate-500 italic">Jodohkan item di kolom kiri dengan pilihan yang tepat:</p>
          <div v-for="(kiri, kIdx) in currentSoal.kiri" :key="kiri.id" class="rounded-xl border border-slate-200 p-3 bg-slate-50 space-y-2">
            <div class="text-[15px] font-medium text-slate-800">
              <span class="font-bold text-brand-700 mr-1 shrink-0">{{ kIdx + 1 }}.</span>
              <span dir="auto" v-html="renderMathInHtml(kiri.konten)"></span>
            </div>
            <div>
              <label class="block text-[11px] font-bold text-slate-500 mb-1">Pilih Pasangan:</label>
              <select
                :value="petaJawaban(currentSoal.soalId)[String(kiri.id)] ?? ''"
                @change="setMenjodohkan(kiri.id, ($event.target as HTMLSelectElement).value)"
                class="input text-sm !min-h-touch"
              >
                <option value="">-- Pilih Jawaban --</option>
                <option v-for="(kanan, knIdx) in currentSoal.kanan" :key="kanan.id" :value="kanan.id">
                  {{ String.fromCharCode(65 + knIdx) }}. {{ kanan.konten.replace(/<[^>]*>/g, '') }}
                </option>
              </select>
            </div>
          </div>
        </div>

        <!-- 5. Essay -->
        <div v-if="currentSoal.tipe === 'essay'" class="space-y-2 pt-2">
          <label class="block text-xs font-semibold text-slate-500">Tuliskan jawaban lengkap Anda di bawah ini:</label>
          <textarea
            :value="(answers[currentSoal.soalId] as string) ?? ''"
            @input="onEssayInput"
            rows="6"
            placeholder="Ketik uraian jawaban di sini..."
            class="input text-[16px] leading-relaxed p-3 select-text"
          ></textarea>
          <p class="text-[11px] text-slate-400 text-right">{{ String(answers[currentSoal.soalId] ?? '').length }} Karakter</p>
        </div>
      </div>
    </main>

    <footer class="fixed bottom-0 left-0 right-0 z-40 border-t border-slate-200 bg-white p-3 shadow-lg">
      <div class="mx-auto flex max-w-xl items-center justify-between gap-2">
        <button @click="prevQuestion" :disabled="currentIndex === 0" class="btn-secondary flex-1 !min-h-touch text-xs font-bold">← Sebelumnya</button>
        <button
          @click="toggleRagu"
          class="btn-secondary !min-h-touch !px-3 text-xs font-bold"
          :class="currentSoal && raguMap[currentSoal.soalId] ? '!bg-amber-100 !border-amber-300 text-amber-800' : ''"
        >
          Flag Ragu
        </button>
        <button v-if="currentIndex < soalList.length - 1" @click="nextQuestion" class="btn-primary flex-1 !min-h-touch text-xs font-bold">Selanjutnya →</button>
        <button v-else @click="showSubmitModal = true" class="btn-primary flex-1 !min-h-touch text-xs font-bold bg-emerald-600 hover:bg-emerald-700">Selesai Ujian</button>
      </div>
    </footer>

    <!-- Daftar nomor soal -->
    <div v-if="showNavDrawer" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/50 p-2 sm:p-4">
      <div class="card w-full max-w-md bg-white p-4 max-h-[85vh] overflow-y-auto rounded-t-2xl sm:rounded-2xl">
        <div class="mb-3 flex items-center justify-between border-b pb-2">
          <h3 class="text-sm font-bold text-slate-800">Daftar Nomor Soal</h3>
          <button @click="showNavDrawer = false" class="rounded-lg p-1 text-slate-400 hover:text-slate-600 hover:bg-slate-100" aria-label="Tutup">✕</button>
        </div>
        <div class="flex items-center justify-around text-[11px] mb-4 text-slate-600">
          <div class="flex items-center gap-1"><span class="h-3 w-3 rounded bg-brand-600"></span> Terjawab</div>
          <div class="flex items-center gap-1"><span class="h-3 w-3 rounded bg-amber-500"></span> Ragu-ragu</div>
          <div class="flex items-center gap-1"><span class="h-3 w-3 rounded bg-slate-200"></span> Belum</div>
        </div>
        <div class="grid grid-cols-5 gap-2.5">
          <button
            v-for="(soal, idx) in soalList"
            :key="soal.soalId"
            @click="goToQuestion(idx)"
            class="flex h-11 w-full items-center justify-center rounded-xl font-bold text-xs transition-transform active:scale-95"
            :class="[
              currentIndex === idx ? 'ring-2 ring-brand-500 ring-offset-2' : '',
              raguMap[soal.soalId]
                ? 'bg-amber-500 text-white'
                : sudahDijawab(answers[soal.soalId])
                  ? 'bg-brand-600 text-white'
                  : 'bg-slate-100 text-slate-700 hover:bg-slate-200'
            ]"
          >
            {{ idx + 1 }}
          </button>
        </div>
        <div class="mt-5 pt-3 border-t">
          <button @click="showSubmitModal = true; showNavDrawer = false" class="btn-primary w-full text-xs font-bold bg-emerald-600">Kumpulkan Ujian Sekarang</button>
        </div>
      </div>
    </div>

    <!-- Konfirmasi submit (FR-FE-70) -->
    <div v-if="showSubmitModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div class="card w-full max-w-sm bg-white p-5 text-center space-y-4">
        <h3 class="text-base font-bold text-slate-900">Konfirmasi Kumpulkan Ujian</h3>
        <div class="grid grid-cols-3 gap-2 text-center text-xs">
          <div class="rounded-lg bg-slate-100 p-2"><p class="text-slate-400">Total</p><p class="text-base font-bold text-slate-800">{{ totalSoalCount }}</p></div>
          <div class="rounded-lg bg-green-50 p-2"><p class="text-green-700">Terjawab</p><p class="text-base font-bold text-status-ok">{{ terjawabCount }}</p></div>
          <div class="rounded-lg bg-amber-50 p-2"><p class="text-amber-700">Ragu / Kosong</p><p class="text-base font-bold text-status-warn">{{ belumTerjawabCount + raguCount }}</p></div>
        </div>
        <div v-if="belumTerjawabCount > 0 || raguCount > 0" class="rounded-xl bg-amber-50 border border-amber-200 p-3.5 text-xs text-amber-800 text-left">
          Masih terdapat <strong>{{ belumTerjawabCount }} butir soal belum dijawab</strong> dan <strong>{{ raguCount }} butir soal ragu-ragu</strong>. Apakah Anda yakin ingin menyelesaikan ujian?
        </div>
        <div v-else class="rounded-xl bg-green-50 border border-green-200 p-3.5 text-xs text-green-800">Seluruh butir soal telah terjawab.</div>
        <div class="flex gap-2 pt-2">
          <button @click="showSubmitModal = false" class="btn-secondary flex-1 text-xs">Periksa Kembali</button>
          <button @click="submitExam" :disabled="submitLoading" class="btn-primary flex-1 text-xs bg-emerald-600 hover:bg-emerald-700 font-bold">
            {{ submitLoading ? 'Mengirim...' : 'Ya, Kumpulkan' }}
          </button>
        </div>
      </div>
    </div>

    <!-- Overlay terkunci: input nonaktif, timer tetap berjalan (FR-FE-44, FR-FE-54) -->
    <div
      v-if="terkunci"
      class="fixed inset-0 z-[60] flex flex-col items-center justify-center gap-4 bg-slate-900/95 p-6 text-center text-white"
      role="alertdialog"
      aria-live="assertive"
      aria-labelledby="judul-kunci"
    >
      <div class="text-5xl" aria-hidden="true">🔒</div>
      <h2 id="judul-kunci" class="text-xl font-bold">Ujian terkunci, hubungi pengawas</h2>
      <p class="max-w-xs text-sm text-slate-300">
        Anda meninggalkan halaman ujian. Waktu tetap berjalan. Layar akan terbuka otomatis setelah pengawas membuka kunci.
      </p>
      <div class="rounded-xl px-4 py-2 font-mono text-lg font-bold tracking-wider" :class="timerWarningClass">{{ formattedTimer }}</div>
    </div>
  </div>
</template>
