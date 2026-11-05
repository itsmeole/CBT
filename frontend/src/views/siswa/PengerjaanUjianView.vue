<script setup lang="ts">
import { ref, onMounted, computed, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { apiClient } from '@/api/client'
import { useUjianStore } from '@/stores/ujian'
import { renderMathInHtml } from '@/lib/katex'
import { antrekanJawaban, bersihkanSemua } from '@/lib/offlineQueue'

interface SoalItem {
  id: number
  tipe: 'pg' | 'pg_kompleks' | 'benar_salah' | 'menjodohkan' | 'essay'
  nomor_urut: number
  konten: string
  opsi?: { id: number; konten: string; sisi?: 'kiri' | 'kanan' }[]
}

const router = useRouter()
const ujianStore = useUjianStore()

const loading = ref(true)
const soalList = ref<SoalItem[]>([])
const currentIndex = ref(0)
const answers = ref<Record<number, any>>({})
const raguMap = ref<Record<number, boolean>>({})

// Sync state
type SyncStatus = 'saved' | 'saving' | 'offline'
const syncStatus = ref<SyncStatus>('saved')
const clientSeq = ref(0)

// Navigation drawer & submit modal
const showNavDrawer = ref(false)
const showSubmitModal = ref(false)
const submitLoading = ref(false)

// Timer display
const timerInterval = ref<any>(null)

const currentSoal = computed<SoalItem | undefined>(() => soalList.value[currentIndex.value])

// Timer formatted
const formattedTimer = computed(() => {
  const s = ujianStore.sisaWaktuDetik
  if (s <= 0) return '00:00:00'
  const hours = Math.floor(s / 3600)
  const minutes = Math.floor((s % 3600) / 60)
  const seconds = s % 60
  return `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`
})

const timerWarningClass = computed(() => {
  const s = ujianStore.sisaWaktuDetik
  if (s <= 300) return 'bg-red-600 text-white animate-pulse'
  if (s <= 600) return 'bg-amber-500 text-white'
  return 'bg-slate-800 text-white'
})

// Recap stats for submit modal
const totalSoalCount = computed(() => soalList.value.length)
const terjawabCount = computed(() => {
  return soalList.value.filter(s => isSoalAnswered(s.id)).length
})
const belumTerjawabCount = computed(() => totalSoalCount.value - terjawabCount.value)
const raguCount = computed(() => {
  return Object.values(raguMap.value).filter(Boolean).length
})

function isSoalAnswered(soalId: number): boolean {
  const ans = answers.value[soalId]
  if (ans === undefined || ans === null || ans === '') return false
  if (Array.isArray(ans)) return ans.length > 0
  if (typeof ans === 'object') return Object.keys(ans).length > 0
  return true
}

async function fetchExamData() {
  loading.value = true
  try {
    // 1. Check state
    const stateRes = await apiClient.get('/ujian/state')
    const state = stateRes.data

    if (state.status === 'selesai') {
      router.replace({ name: 'siswa-selesai' })
      return
    }

    if (state.waktu_selesai_ms) {
      ujianStore.terapkanStateServer({
        status: state.status,
        waktuSelesaiMs: state.waktu_selesai_ms
      })
      ujianStore.sinkronWaktu(state.waktu_server_ms || Date.now())
    }

    // 2. Fetch questions (stripped from keys and scores)
    const soalRes = await apiClient.get('/ujian/soal')
    soalList.value = soalRes.data.data || []

    // 3. Populate existing answers if any
    const ansMap: Record<number, any> = {}
    const rMap: Record<number, boolean> = {}

    // In a fresh session or reload, answers can be populated
    if (soalRes.data.jawaban_tersimpan) {
      soalRes.data.jawaban_tersimpan.forEach((j: any) => {
        ansMap[j.bank_soal_id] = j.jawaban
        rMap[j.bank_soal_id] = !!j.ragu_ragu
      })
    }

    answers.value = ansMap
    raguMap.value = rMap
  } catch (err: any) {
    alert(err.message || 'Gagal memuat soal ujian.')
  } finally {
    loading.value = false
  }
}

// Autosave handler (FR-FE-30, FR-FE-32)
let saveDebounceTimer: any = null

function triggerAutosave(soalId: number) {
  syncStatus.value = 'saving'
  clientSeq.value++

  const currentSeq = clientSeq.value
  const jawabanVal = answers.value[soalId]
  const isRagu = !!raguMap.value[soalId]

  // Queue to IndexedDB
  antrekanJawaban({
    soalId: String(soalId),
    jawaban: jawabanVal,
    clientSeq: currentSeq,
    waktuMs: Date.now()
  }).catch(() => {})

  if (saveDebounceTimer) clearTimeout(saveDebounceTimer)
  saveDebounceTimer = setTimeout(async () => {
    try {
      await apiClient.put('/ujian/jawaban', {
        soal_id: soalId,
        jawaban: jawabanVal,
        client_seq: currentSeq,
        ragu_ragu: isRagu
      })
      syncStatus.value = 'saved'
    } catch {
      syncStatus.value = 'offline'
    }
  }, 400)
}

function selectPgAnswer(opsiId: number) {
  if (!currentSoal.value) return
  answers.value[currentSoal.value.id] = opsiId
  triggerAutosave(currentSoal.value.id)
}

function togglePgKompleksAnswer(opsiId: number) {
  if (!currentSoal.value) return
  const cur = answers.value[currentSoal.value.id] || []
  const arr = Array.isArray(cur) ? [...cur] : []
  const idx = arr.indexOf(opsiId)
  if (idx >= 0) {
    arr.splice(idx, 1)
  } else {
    arr.push(opsiId)
  }
  answers.value[currentSoal.value.id] = arr
  triggerAutosave(currentSoal.value.id)
}

function setBenarSalah(statementIndex: number, val: 'Benar' | 'Salah') {
  if (!currentSoal.value) return
  const cur = answers.value[currentSoal.value.id] || {}
  const next = { ...cur, [statementIndex]: val }
  answers.value[currentSoal.value.id] = next
  triggerAutosave(currentSoal.value.id)
}

function setMenjodohkan(kiriId: number, kananId: string) {
  if (!currentSoal.value) return
  const cur = answers.value[currentSoal.value.id] || {}
  const next = { ...cur, [kiriId]: kananId }
  answers.value[currentSoal.value.id] = next
  triggerAutosave(currentSoal.value.id)
}

function onEssayInput() {
  if (!currentSoal.value) return
  triggerAutosave(currentSoal.value.id)
}

function toggleRagu() {
  if (!currentSoal.value) return
  const sid = currentSoal.value.id
  raguMap.value[sid] = !raguMap.value[sid]
  triggerAutosave(sid)
}

// Navigation helpers
function goToQuestion(idx: number) {
  if (idx >= 0 && idx < soalList.value.length) {
    currentIndex.value = idx
    showNavDrawer.value = false
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }
}

function nextQuestion() {
  if (currentIndex.value < soalList.value.length - 1) {
    goToQuestion(currentIndex.value + 1)
  } else {
    showSubmitModal.value = true
  }
}

function prevQuestion() {
  if (currentIndex.value > 0) {
    goToQuestion(currentIndex.value - 1)
  }
}

// Submit Final Exam (FR-FE-70)
async function submitExam() {
  submitLoading.value = true
  try {
    await apiClient.post('/ujian/submit')
    await bersihkanSemua()
    router.replace({ name: 'siswa-selesai' })
  } catch (err: any) {
    alert(err.message || 'Gagal mengumpulkan ujian. Silakan coba kembali.')
  } finally {
    submitLoading.value = false
  }
}

// Security: Prevent contextmenu, copy, cut, paste, selectstart (FR-FE-60)
function preventCheatingEvents(e: Event) {
  e.preventDefault()
  return false
}

onMounted(() => {
  fetchExamData()

  // Start ticker
  timerInterval.value = setInterval(() => {
    ujianStore.tick()
    // Auto submit if timer runs out (FR-BE-65)
    if (ujianStore.waktuSelesaiMs && ujianStore.sisaWaktuDetik <= 0) {
      submitExam()
    }
  }, 1000)

  // Attach anti-cheat handlers
  document.addEventListener('contextmenu', preventCheatingEvents)
  document.addEventListener('copy', preventCheatingEvents)
  document.addEventListener('cut', preventCheatingEvents)
  document.addEventListener('paste', preventCheatingEvents)
  document.addEventListener('selectstart', preventCheatingEvents)
})

onUnmounted(() => {
  if (timerInterval.value) clearInterval(timerInterval.value)
  if (saveDebounceTimer) clearTimeout(saveDebounceTimer)

  document.removeEventListener('contextmenu', preventCheatingEvents)
  document.removeEventListener('copy', preventCheatingEvents)
  document.removeEventListener('cut', preventCheatingEvents)
  document.removeEventListener('paste', preventCheatingEvents)
  document.removeEventListener('selectstart', preventCheatingEvents)
})
</script>

<template>
  <div class="min-h-screen bg-slate-100 pb-24 text-slate-800 select-none">
    <!-- Sticky Top Bar (FR-FE-20) -->
    <header class="sticky top-0 z-40 flex items-center justify-between border-b border-slate-200 bg-white px-4 py-2.5 shadow-sm">
      <!-- Question Number & Drawer Trigger -->
      <button
        @click="showNavDrawer = true"
        class="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-bold text-slate-800 min-h-touch min-w-touch"
      >
        <span>No. {{ currentIndex + 1 }} / {{ soalList.length }}</span>
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4 text-slate-500">
          <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
        </svg>
      </button>

      <!-- Sync Status Indicator (FR-FE-33) -->
      <div class="flex items-center gap-1.5 text-xs font-medium">
        <span v-if="syncStatus === 'saving'" class="flex items-center gap-1 text-brand-600">
          <span class="inline-block h-2 w-2 animate-ping rounded-full bg-brand-600"></span>
          Menyimpan...
        </span>
        <span v-else-if="syncStatus === 'saved'" class="flex items-center gap-1 text-status-ok font-semibold">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-3.5 w-3.5 text-emerald-600">
            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
          </svg>
          Tersimpan
        </span>
        <span v-else class="flex items-center gap-1 text-status-danger font-semibold">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-3.5 w-3.5 text-red-600">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
          </svg>
          Offline (Tersimpan Lokal)
        </span>
      </div>

      <!-- Synchronized Server Timer (FR-FE-41, FR-FE-42) -->
      <div
        class="flex items-center gap-1.5 rounded-xl px-2.5 py-1 font-mono text-xs font-bold tracking-wider transition-colors min-h-touch"
        :class="timerWarningClass"
      >
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-3.5 w-3.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
        </svg>
        <span>{{ formattedTimer }}</span>
      </div>
    </header>

    <!-- Main Question Content Area (FR-FE-21) -->
    <main v-if="currentSoal" class="mx-auto max-w-xl p-4 sm:p-6">
      <div class="card bg-white p-5 shadow-sm space-y-4">
        <!-- Question Header & Type Badge -->
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
            <input
              type="checkbox"
              :checked="raguMap[currentSoal.id]"
              @change="toggleRagu"
              class="h-4 w-4 rounded text-amber-500"
            />
            <span>Ragu-ragu</span>
          </label>
        </div>

        <!-- Question Prompt with KaTeX Math Rendering -->
        <div
          class="prose prose-base max-w-none text-slate-800 text-[16px] leading-relaxed"
          dir="auto"
          v-html="renderMathInHtml(currentSoal.konten)"
        ></div>

        <!-- Input Renderers for 5 Types (FR-FE-22) -->
        <!-- 1. Pilihan Ganda (PG) -->
        <div v-if="currentSoal.tipe === 'pg'" class="space-y-2 pt-2">
          <div
            v-for="(opsi, oIdx) in currentSoal.opsi"
            :key="opsi.id"
            @click="selectPgAnswer(opsi.id)"
            class="flex items-center gap-3 rounded-xl border p-3.5 transition-all cursor-pointer min-h-touch"
            :class="answers[currentSoal.id] === opsi.id ? 'border-brand-600 bg-brand-50/70 font-semibold' : 'border-slate-200 bg-white hover:bg-slate-50'"
          >
            <div
              class="flex h-6 w-6 items-center justify-center rounded-full border text-xs font-bold shrink-0"
              :class="answers[currentSoal.id] === opsi.id ? 'border-brand-600 bg-brand-600 text-white' : 'border-slate-300 text-slate-600'"
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
            :class="(answers[currentSoal.id] || []).includes(opsi.id) ? 'border-brand-600 bg-brand-50/70 font-semibold' : 'border-slate-200 bg-white hover:bg-slate-50'"
          >
            <input
              type="checkbox"
              :checked="(answers[currentSoal.id] || []).includes(opsi.id)"
              class="h-5 w-5 rounded text-brand-600 pointer-events-none shrink-0"
            />
            <span class="font-bold text-xs text-slate-500 shrink-0">{{ String.fromCharCode(65 + oIdx) }}.</span>
            <div class="text-[16px] flex-1" dir="auto" v-html="renderMathInHtml(opsi.konten)"></div>
          </div>
        </div>

        <!-- 3. Benar / Salah -->
        <div v-if="currentSoal.tipe === 'benar_salah'" class="space-y-3 pt-2">
          <p class="text-xs font-semibold text-slate-500 italic">Tentukan nilai kebenaran untuk tiap pernyataan:</p>
          <div
            v-for="(opsi, oIdx) in currentSoal.opsi"
            :key="opsi.id"
            class="rounded-xl border border-slate-200 p-3 bg-slate-50 space-y-2"
          >
            <div class="text-[15px] font-medium text-slate-800" dir="auto" v-html="renderMathInHtml(opsi.konten)"></div>
            <div class="flex gap-2">
              <button
                type="button"
                @click="setBenarSalah(oIdx + 1, 'Benar')"
                class="flex-1 rounded-lg border py-2 text-xs font-bold transition-all min-h-touch"
                :class="answers[currentSoal.id]?.[oIdx + 1] === 'Benar' ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-300 bg-white text-slate-700'"
              >
                Benar
              </button>
              <button
                type="button"
                @click="setBenarSalah(oIdx + 1, 'Salah')"
                class="flex-1 rounded-lg border py-2 text-xs font-bold transition-all min-h-touch"
                :class="answers[currentSoal.id]?.[oIdx + 1] === 'Salah' ? 'border-red-600 bg-red-600 text-white' : 'border-slate-300 bg-white text-slate-700'"
              >
                Salah
              </button>
            </div>
          </div>
        </div>

        <!-- 4. Menjodohkan (Dropdown Mobile-Friendly, NO Drag-and-drop!) -->
        <div v-if="currentSoal.tipe === 'menjodohkan'" class="space-y-3 pt-2">
          <p class="text-xs font-semibold text-slate-500 italic">Jodohkan item di kolom kiri dengan pilihan yang tepat:</p>
          <div
            v-for="(kiri, kIdx) in currentSoal.opsi?.filter(o => o.sisi === 'kiri')"
            :key="kiri.id"
            class="rounded-xl border border-slate-200 p-3 bg-slate-50 space-y-2"
          >
            <div class="text-[15px] font-medium text-slate-800">
              <span class="font-bold text-brand-700 mr-1 shrink-0">{{ kIdx + 1 }}.</span>
              <span dir="auto" v-html="renderMathInHtml(kiri.konten)"></span>
            </div>
            <div>
              <label class="block text-[11px] font-bold text-slate-500 mb-1">Pilih Pasangan:</label>
              <select
                :value="answers[currentSoal.id]?.[kIdx + 1] || ''"
                @change="setMenjodohkan(kIdx + 1, ($event.target as HTMLSelectElement).value)"
                class="input text-sm !min-h-touch"
              >
                <option value="">-- Pilih Jawaban --</option>
                <option
                  v-for="(kanan, knIdx) in currentSoal.opsi?.filter(o => o.sisi === 'kanan')"
                  :key="kanan.id"
                  :value="knIdx + 1"
                >
                  {{ String.fromCharCode(65 + knIdx) }}. {{ kanan.konten.replace(/<[^>]*>/g, '') }}
                </option>
              </select>
            </div>
          </div>
        </div>

        <!-- 5. Essay / Uraian -->
        <div v-if="currentSoal.tipe === 'essay'" class="space-y-2 pt-2">
          <label class="block text-xs font-semibold text-slate-500">Tuliskan jawaban lengkap Anda di bawah ini:</label>
          <textarea
            v-model="answers[currentSoal.id]"
            @input="onEssayInput"
            rows="6"
            placeholder="Ketik uraian jawaban di sini..."
            class="input text-[16px] leading-relaxed p-3"
          ></textarea>
          <p class="text-[11px] text-slate-400 text-right">
            {{ (answers[currentSoal.id] || '').length }} Karakter
          </p>
        </div>
      </div>
    </main>

    <!-- Bottom Sticky Navigation Bar -->
    <footer class="fixed bottom-0 left-0 right-0 z-40 border-t border-slate-200 bg-white p-3 shadow-lg">
      <div class="mx-auto flex max-w-xl items-center justify-between gap-2">
        <button
          @click="prevQuestion"
          :disabled="currentIndex === 0"
          class="btn-secondary flex-1 !min-h-touch text-xs font-bold"
        >
          ← Sebelumnya
        </button>

        <button
          @click="toggleRagu"
          class="btn-secondary !min-h-touch !px-3 text-xs font-bold"
          :class="currentSoal && raguMap[currentSoal.id] ? '!bg-amber-100 !border-amber-300 text-amber-800' : ''"
        >
          Flag Ragu
        </button>

        <button
          v-if="currentIndex < soalList.length - 1"
          @click="nextQuestion"
          class="btn-primary flex-1 !min-h-touch text-xs font-bold"
        >
          Selanjutnya →
        </button>

        <button
          v-else
          @click="showSubmitModal = true"
          class="btn-primary flex-1 !min-h-touch text-xs font-bold bg-emerald-600 hover:bg-emerald-700 flex items-center justify-center gap-1.5"
        >
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
          </svg>
          <span>Selesai Ujian</span>
        </button>
      </div>
    </footer>

    <!-- Navigation Grid Drawer / Modal -->
    <div v-if="showNavDrawer" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/50 p-2 sm:p-4">
      <div class="card w-full max-w-md bg-white p-4 max-h-[85vh] overflow-y-auto rounded-t-2xl sm:rounded-2xl">
        <div class="mb-3 flex items-center justify-between border-b pb-2">
          <h3 class="text-sm font-bold text-slate-800">Daftar Nomor Soal</h3>
          <button @click="showNavDrawer = false" class="rounded-lg p-1 text-slate-400 hover:text-slate-600 hover:bg-slate-100">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <!-- Legend -->
        <div class="flex items-center justify-around text-[11px] mb-4 text-slate-600">
          <div class="flex items-center gap-1">
            <span class="h-3 w-3 rounded bg-brand-600"></span> Terjawab
          </div>
          <div class="flex items-center gap-1">
            <span class="h-3 w-3 rounded bg-amber-500"></span> Ragu-ragu
          </div>
          <div class="flex items-center gap-1">
            <span class="h-3 w-3 rounded bg-slate-200"></span> Belum
          </div>
        </div>

        <!-- Grid of Numbers -->
        <div class="grid grid-cols-5 gap-2.5">
          <button
            v-for="(soal, idx) in soalList"
            :key="soal.id"
            @click="goToQuestion(idx)"
            class="flex h-11 w-full items-center justify-center rounded-xl font-bold text-xs transition-transform active:scale-95"
            :class="[
              currentIndex === idx ? 'ring-2 ring-brand-500 ring-offset-2' : '',
              raguMap[soal.id]
                ? 'bg-amber-500 text-white'
                : isSoalAnswered(soal.id)
                  ? 'bg-brand-600 text-white'
                  : 'bg-slate-100 text-slate-700 hover:bg-slate-200'
            ]"
          >
            {{ idx + 1 }}
          </button>
        </div>

        <div class="mt-5 pt-3 border-t">
          <button @click="showSubmitModal = true; showNavDrawer = false" class="btn-primary w-full text-xs font-bold bg-emerald-600">
            Kumpulkan Ujian Sekarang
          </button>
        </div>
      </div>
    </div>

    <!-- Custom Submit Confirmation Dialog (FR-FE-70) -->
    <div v-if="showSubmitModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div class="card w-full max-w-sm bg-white p-5 text-center space-y-4">
        <h3 class="text-base font-bold text-slate-900">Konfirmasi Kumpulkan Ujian</h3>

        <!-- Status Summary Recap -->
        <div class="grid grid-cols-3 gap-2 text-center text-xs">
          <div class="rounded-lg bg-slate-100 p-2">
            <p class="text-slate-400">Total</p>
            <p class="text-base font-bold text-slate-800">{{ totalSoalCount }}</p>
          </div>
          <div class="rounded-lg bg-green-50 p-2">
            <p class="text-green-700">Terjawab</p>
            <p class="text-base font-bold text-status-ok">{{ terjawabCount }}</p>
          </div>
          <div class="rounded-lg bg-amber-50 p-2">
            <p class="text-amber-700">Ragu / Kosong</p>
            <p class="text-base font-bold text-status-warn">{{ belumTerjawabCount + raguCount }}</p>
          </div>
        </div>

        <div v-if="belumTerjawabCount > 0 || raguCount > 0" class="rounded-xl bg-amber-50 border border-amber-200 p-3.5 text-xs text-amber-800 text-left flex items-start gap-2">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4 shrink-0 text-amber-600 mt-0.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" />
          </svg>
          <div>
            Masih terdapat <strong>{{ belumTerjawabCount }} butir soal belum dijawab</strong> dan <strong>{{ raguCount }} butir soal ragu-ragu</strong>. Apakah Anda yakin ingin menyelesaikan ujian?
          </div>
        </div>
        <div v-else class="rounded-xl bg-green-50 border border-green-200 p-3.5 text-xs text-green-800 flex items-center justify-center gap-2">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4 text-emerald-600">
            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
          </svg>
          <span>Hebat! Seluruh butir soal telah berhasil Anda jawab.</span>
        </div>

        <div class="flex gap-2 pt-2">
          <button @click="showSubmitModal = false" class="btn-secondary flex-1 text-xs">
            Periksa Kembali
          </button>
          <button
            @click="submitExam"
            :disabled="submitLoading"
            class="btn-primary flex-1 text-xs bg-emerald-600 hover:bg-emerald-700 font-bold"
          >
            {{ submitLoading ? 'Mengirim...' : 'Ya, Kumpulkan' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
