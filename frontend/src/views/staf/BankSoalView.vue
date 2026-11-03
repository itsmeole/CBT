<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import StafNav from '@/components/StafNav.vue'
import { apiClient } from '@/api/client'
import { renderMathInHtml } from '@/lib/katex'

type TipeSoal = 'pg' | 'pg_kompleks' | 'benar_salah' | 'menjodohkan' | 'essay'

interface OpsiItem {
  id?: number
  konten: string
  sisi?: 'kiri' | 'kanan'
}

interface SoalItem {
  id: number
  mapel_id: number
  guru_id: number
  tipe: TipeSoal
  konten: string
  kunci: any
  rubrik?: string
  mapel?: { id: number; nama: string; kode: string }
  guru?: { id: number; user?: { nama: string } }
  opsi_soal?: OpsiItem[]
}

const loading = ref(false)
const mapelList = ref<any[]>([])
const soalList = ref<SoalItem[]>([])
const selectedMapel = ref<number | ''>('')
const selectedTipe = ref<string>('')
const searchQuery = ref('')
const feedbackMessage = ref('')
const errorMessage = ref('')

// Form Modal State
const showModal = ref(false)
const isEditing = ref(false)
const editId = ref<number | null>(null)
const formMapelId = ref<number | null>(null)
const formTipe = ref<TipeSoal>('pg')
const formKonten = ref('')
const formRubrik = ref('')
const formOpsi = ref<{ konten: string; sisi?: 'kiri' | 'kanan'; isCorrect?: boolean; jawabanBS?: 'Benar' | 'Salah' }[]>([])
const formMenjodohkan = ref<{ kiri: string; kanan: string }[]>([])

// Media Upload Modal
const showMediaModal = ref(false)
const mediaFile = ref<File | null>(null)
const mediaLoading = ref(false)
const uploadedMediaUrl = ref<string>('')

// Word Import Wizard State
const showWordImportModal = ref(false)
const wordFile = ref<File | null>(null)
const wordImportStep = ref<1 | 2>(1)
const wordPreviewItems = ref<any[]>([])
const wordImportLoading = ref(false)
const wordImportErrors = ref<any[]>([])

async function fetchInitial() {
  loading.value = true
  try {
    const resMapel = await apiClient.get('/mapel')
    mapelList.value = resMapel.data.data || []
    if (mapelList.value.length > 0 && !selectedMapel.value) {
      selectedMapel.value = mapelList.value[0].id
    }
    await fetchSoal()
  } catch (err: any) {
    errorMessage.value = err.message || 'Gagal memuat data.'
  } finally {
    loading.value = false
  }
}

async function fetchSoal() {
  loading.value = true
  errorMessage.value = ''
  try {
    const params: any = {}
    if (selectedMapel.value) params.mapel_id = selectedMapel.value
    if (selectedTipe.value) params.tipe = selectedTipe.value
    if (searchQuery.value) params.search = searchQuery.value

    const res = await apiClient.get('/bank-soal', { params })
    soalList.value = res.data.data || []
  } catch (err: any) {
    errorMessage.value = err.message || 'Gagal memuat bank soal.'
  } finally {
    loading.value = false
  }
}

const filteredSoal = computed(() => {
  return soalList.value
})

function initOpsiForType(tipe: TipeSoal) {
  if (tipe === 'pg') {
    formOpsi.value = [
      { konten: '', isCorrect: true },
      { konten: '', isCorrect: false },
      { konten: '', isCorrect: false },
      { konten: '', isCorrect: false }
    ]
  } else if (tipe === 'pg_kompleks') {
    formOpsi.value = [
      { konten: '', isCorrect: true },
      { konten: '', isCorrect: true },
      { konten: '', isCorrect: false },
      { konten: '', isCorrect: false }
    ]
  } else if (tipe === 'benar_salah') {
    formOpsi.value = [
      { konten: 'Pernyataan 1', jawabanBS: 'Benar' },
      { konten: 'Pernyataan 2', jawabanBS: 'Salah' }
    ]
  } else if (tipe === 'menjodohkan') {
    formMenjodohkan.value = [
      { kiri: 'Pertanyaan A', kanan: 'Jawaban A' },
      { kiri: 'Pertanyaan B', kanan: 'Jawaban B' }
    ]
  } else {
    formOpsi.value = []
  }
}

function openCreateModal() {
  isEditing.value = false
  editId.value = null
  formMapelId.value = (selectedMapel.value as number) || (mapelList.value[0]?.id ?? 1)
  formTipe.value = 'pg'
  formKonten.value = ''
  formRubrik.value = ''
  initOpsiForType('pg')
  showModal.value = true
}

function handleTipeChange() {
  initOpsiForType(formTipe.value)
}

function openEditModal(item: SoalItem) {
  isEditing.value = true
  editId.value = item.id
  formMapelId.value = item.mapel_id
  formTipe.value = item.tipe
  formKonten.value = item.konten
  formRubrik.value = item.rubrik || ''

  if (item.tipe === 'pg') {
    formOpsi.value = (item.opsi_soal || []).map((o, idx) => ({
      konten: o.konten,
      isCorrect: String(item.kunci) === String(idx + 1) || String(item.kunci) === String(o.id)
    }))
  } else if (item.tipe === 'pg_kompleks') {
    const kunciArr = Array.isArray(item.kunci) ? item.kunci : []
    formOpsi.value = (item.opsi_soal || []).map((o, idx) => ({
      konten: o.konten,
      isCorrect: kunciArr.includes(idx + 1) || kunciArr.includes(o.id)
    }))
  } else if (item.tipe === 'benar_salah') {
    const kunciMap = typeof item.kunci === 'object' && item.kunci !== null ? item.kunci : {}
    formOpsi.value = (item.opsi_soal || []).map((o, idx) => ({
      konten: o.konten,
      jawabanBS: kunciMap[idx + 1] || (o.id ? kunciMap[o.id] : undefined) || 'Benar'
    }))
  } else if (item.tipe === 'menjodohkan') {
    const kiri = (item.opsi_soal || []).filter(o => o.sisi === 'kiri')
    const kanan = (item.opsi_soal || []).filter(o => o.sisi === 'kanan')
    formMenjodohkan.value = kiri.map((k, idx) => ({
      kiri: k.konten,
      kanan: kanan[idx]?.konten || ''
    }))
  }

  showModal.value = true
}

async function saveSoal() {
  loading.value = true
  errorMessage.value = ''
  feedbackMessage.value = ''

  let payloadKunci: any = null
  let payloadOpsi: any[] = []

  if (formTipe.value === 'pg') {
    const correctIdx = formOpsi.value.findIndex(o => o.isCorrect)
    payloadKunci = correctIdx >= 0 ? correctIdx + 1 : 1
    payloadOpsi = formOpsi.value.map(o => ({ konten: o.konten }))
  } else if (formTipe.value === 'pg_kompleks') {
    payloadKunci = formOpsi.value
      .map((o, idx) => (o.isCorrect ? idx + 1 : null))
      .filter(v => v !== null)
    payloadOpsi = formOpsi.value.map(o => ({ konten: o.konten }))
  } else if (formTipe.value === 'benar_salah') {
    payloadKunci = {}
    formOpsi.value.forEach((o, idx) => {
      payloadKunci[idx + 1] = o.jawabanBS || 'Benar'
    })
    payloadOpsi = formOpsi.value.map(o => ({ konten: o.konten }))
  } else if (formTipe.value === 'menjodohkan') {
    payloadKunci = {}
    formMenjodohkan.value.forEach((pair, idx) => {
      payloadOpsi.push({ konten: pair.kiri, sisi: 'kiri' })
      payloadOpsi.push({ konten: pair.kanan, sisi: 'kanan' })
      payloadKunci[idx + 1] = idx + 1
    })
  }

  const data = {
    mapel_id: formMapelId.value,
    tipe: formTipe.value,
    konten: formKonten.value,
    kunci: payloadKunci,
    rubrik: formRubrik.value,
    opsi: payloadOpsi
  }

  try {
    if (isEditing.value && editId.value) {
      await apiClient.put(`/bank-soal/${editId.value}`, data)
      feedbackMessage.value = 'Soal berhasil diperbarui.'
    } else {
      await apiClient.post('/bank-soal', data)
      feedbackMessage.value = 'Soal baru berhasil ditambahkan.'
    }
    showModal.value = false
    await fetchSoal()
  } catch (err: any) {
    errorMessage.value = err.message || 'Gagal menyimpan soal.'
  } finally {
    loading.value = false
  }
}

async function deleteSoal(id: number) {
  if (!confirm('Apakah Anda yakin ingin menghapus butir soal ini?')) return
  loading.value = true
  errorMessage.value = ''
  try {
    await apiClient.delete(`/bank-soal/${id}`)
    feedbackMessage.value = 'Soal berhasil dihapus.'
    await fetchSoal()
  } catch (err: any) {
    errorMessage.value = err.message || 'Gagal menghapus soal.'
  } finally {
    loading.value = false
  }
}

// Media Upload
function onMediaFileSelect(e: Event) {
  const target = e.target as HTMLInputElement
  if (target.files && target.files.length > 0) {
    mediaFile.value = target.files[0]
  }
}

async function uploadMedia() {
  if (!mediaFile.value) return
  mediaLoading.value = true
  try {
    const fd = new FormData()
    fd.append('file', mediaFile.value)
    const res = await apiClient.post('/media', fd, {
      headers: { 'Content-Type': 'multipart/form-data' }
    })
    uploadedMediaUrl.value = res.data.data.url
  } catch (err: any) {
    alert(err.message || 'Gagal mengunggah media.')
  } finally {
    mediaLoading.value = false
  }
}

function insertMediaSnippet() {
  if (!uploadedMediaUrl.value) return
  if (uploadedMediaUrl.value.match(/\.(mp3|wav|ogg)$/i)) {
    formKonten.value += `\n<audio controls src="${uploadedMediaUrl.value}"></audio>\n`
  } else {
    formKonten.value += `\n<img src="${uploadedMediaUrl.value}" alt="Media Soal" class="max-w-md rounded-lg shadow-sm" />\n`
  }
  showMediaModal.value = false
  uploadedMediaUrl.value = ''
  mediaFile.value = null
}

// Download Word Template
function downloadWordTemplate() {
  const url = `${apiClient.defaults.baseURL}/templates/word`
  window.open(url, '_blank')
}

// Export Word Soal
function exportWordSoal() {
  if (!selectedMapel.value) {
    alert('Pilih mata pelajaran terlebih dahulu untuk mengekspor soal.')
    return
  }
  const url = `${apiClient.defaults.baseURL}/export/word/bank-soal?mapel_id=${selectedMapel.value}`
  window.open(url, '_blank')
}

// Word (.docx) Import Wizard
function openWordImport() {
  wordFile.value = null
  wordImportStep.value = 1
  wordPreviewItems.value = []
  wordImportErrors.value = []
  showWordImportModal.value = true
}

function onWordFileSelect(e: Event) {
  const target = e.target as HTMLInputElement
  if (target.files && target.files.length > 0) {
    wordFile.value = target.files[0]
  }
}

async function previewWord() {
  if (!wordFile.value) return
  wordImportLoading.value = true
  errorMessage.value = ''
  try {
    const fd = new FormData()
    fd.append('file', wordFile.value)
    const res = await apiClient.post('/import/word/preview', fd, {
      headers: { 'Content-Type': 'multipart/form-data' }
    })
    wordPreviewItems.value = res.data.data.items || []
    wordImportErrors.value = res.data.data.errors || []
    wordImportStep.value = 2
  } catch (err: any) {
    errorMessage.value = err.message || 'Gagal memproses dokumen Word.'
  } finally {
    wordImportLoading.value = false
  }
}

async function commitWord() {
  if (!selectedMapel.value) {
    alert('Pilih mata pelajaran tujuan impor terlebih dahulu.')
    return
  }
  wordImportLoading.value = true
  errorMessage.value = ''
  try {
    await apiClient.post('/import/word/commit', {
      mapel_id: selectedMapel.value,
      items: wordPreviewItems.value
    })
    feedbackMessage.value = 'Soal dari berkas Word berhasil disimpan ke Bank Soal.'
    showWordImportModal.value = false
    await fetchSoal()
  } catch (err: any) {
    errorMessage.value = err.message || 'Gagal menyimpan soal hasil impor.'
  } finally {
    wordImportLoading.value = false
  }
}

onMounted(() => {
  fetchInitial()
})
</script>

<template>
  <div class="min-h-screen bg-slate-50 text-slate-800">
    <StafNav />

    <main class="mx-auto max-w-7xl p-4 sm:p-6">
      <div class="mb-6 flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
        <div>
          <h1 class="text-2xl font-bold text-slate-900">Bank Soal & Dokumen Word</h1>
          <p class="text-sm text-slate-500">
            Kelola butir soal 5 tipe, visualisasi KaTeX, serta impor & ekspor format Microsoft Word (.docx).
          </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <button @click="downloadWordTemplate" class="btn-secondary !min-h-0 !py-2 text-xs">
            📄 Unduh Template Word
          </button>
          <button @click="openWordImport" class="btn-secondary !min-h-0 !py-2 text-xs">
            📥 Impor Dokumen Word
          </button>
          <button @click="exportWordSoal" class="btn-secondary !min-h-0 !py-2 text-xs">
            📤 Ekspor ke Word (.docx)
          </button>
          <button @click="openCreateModal" class="btn-primary !min-h-0 !py-2 text-xs">
            + Tambah Soal Manual
          </button>
        </div>
      </div>

      <!-- Feedback Banner -->
      <div v-if="feedbackMessage" class="mb-4 rounded-lg bg-green-50 p-3 text-sm font-medium text-status-ok border border-green-200">
        ✓ {{ feedbackMessage }}
      </div>
      <div v-if="errorMessage" class="mb-4 rounded-lg bg-red-50 p-3 text-sm font-medium text-status-danger border border-red-200">
        ⚠ {{ errorMessage }}
      </div>

      <!-- Filters Bar -->
      <div class="card mb-6 flex flex-wrap items-center gap-4 bg-white p-4">
        <div class="flex items-center gap-2">
          <label class="text-xs font-semibold text-slate-600">Mapel:</label>
          <select v-model="selectedMapel" @change="fetchSoal" class="input !min-h-0 !py-1 text-sm max-w-xs">
            <option v-for="m in mapelList" :key="m.id" :value="m.id">
              {{ m.kode }} - {{ m.nama }}
            </option>
          </select>
        </div>

        <div class="flex items-center gap-2">
          <label class="text-xs font-semibold text-slate-600">Tipe Soal:</label>
          <select v-model="selectedTipe" @change="fetchSoal" class="input !min-h-0 !py-1 text-sm max-w-xs">
            <option value="">Semua Tipe</option>
            <option value="pg">Pilihan Ganda (PG)</option>
            <option value="pg_kompleks">PG Kompleks</option>
            <option value="benar_salah">Benar / Salah</option>
            <option value="menjodohkan">Menjodohkan</option>
            <option value="essay">Essay / Uraian</option>
          </select>
        </div>

        <div class="flex flex-1 items-center justify-end gap-2">
          <input
            v-model="searchQuery"
            @keyup.enter="fetchSoal"
            type="search"
            placeholder="Cari konten soal..."
            class="input !min-h-0 !py-1 text-sm max-w-xs"
          />
          <button @click="fetchSoal" class="btn-secondary !min-h-0 !py-1 text-xs">Cari</button>
        </div>
      </div>

      <!-- List Soal Cards -->
      <div class="space-y-4">
        <div
          v-for="(soal, index) in filteredSoal"
          :key="soal.id"
          class="card bg-white p-5 transition-shadow hover:shadow-md"
        >
          <div class="mb-3 flex items-start justify-between gap-4">
            <div class="flex items-center gap-2">
              <span class="flex h-7 w-7 items-center justify-center rounded-full bg-brand-100 font-bold text-xs text-brand-700">
                #{{ index + 1 }}
              </span>
              <span class="rounded bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700 uppercase">
                {{ soal.tipe.replace('_', ' ') }}
              </span>
              <span class="text-xs font-medium text-slate-400">
                {{ soal.mapel?.nama }}
              </span>
            </div>

            <div class="flex items-center gap-2">
              <button @click="openEditModal(soal)" class="text-xs font-semibold text-brand-600 hover:underline">
                Ubah
              </button>
              <button @click="deleteSoal(soal.id)" class="text-xs font-semibold text-status-danger hover:underline">
                Hapus
              </button>
            </div>
          </div>

          <!-- Soal Content with Math KaTeX -->
          <div
            class="prose prose-sm max-w-none text-slate-800 mb-4"
            v-html="renderMathInHtml(soal.konten)"
          ></div>

          <!-- Opsi Display -->
          <div v-if="soal.opsi_soal && soal.opsi_soal.length > 0" class="mt-3 border-t border-slate-100 pt-3">
            <p class="text-xs font-bold text-slate-500 mb-2">Opsi / Pernyataan:</p>
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
              <div
                v-for="(opsi, oIdx) in soal.opsi_soal"
                :key="oIdx"
                class="rounded border border-slate-100 bg-slate-50 p-2 text-xs flex items-center gap-2"
              >
                <span class="font-bold text-slate-500">{{ String.fromCharCode(65 + oIdx) }}.</span>
                <span v-html="renderMathInHtml(opsi.konten)"></span>
                <span v-if="opsi.sisi" class="text-[10px] bg-slate-200 px-1 rounded ml-auto">{{ opsi.sisi }}</span>
              </div>
            </div>
          </div>
        </div>

        <div v-if="filteredSoal.length === 0 && !loading" class="card bg-white p-12 text-center text-slate-400">
          Belum ada butir soal untuk kriteria ini. Tambahkan secara manual atau impor dari dokumen Word.
        </div>
      </div>
    </main>

    <!-- Create / Edit Soal Modal -->
    <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div class="card w-full max-w-3xl bg-white max-h-[92vh] overflow-y-auto">
        <div class="mb-4 flex items-center justify-between border-b pb-3">
          <h2 class="text-lg font-bold text-slate-800">
            {{ isEditing ? 'Ubah Butir Soal' : 'Tambah Butir Soal Baru' }}
          </h2>
          <button @click="showModal = false" class="text-slate-400 hover:text-slate-600">✕</button>
        </div>

        <form @submit.prevent="saveSoal" class="space-y-4">
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-medium text-slate-700">Mata Pelajaran</label>
              <select v-model="formMapelId" required class="input !min-h-0 !py-1.5 text-sm">
                <option v-for="m in mapelList" :key="m.id" :value="m.id">{{ m.kode }} - {{ m.nama }}</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-700">Tipe Soal</label>
              <select v-model="formTipe" @change="handleTipeChange" :disabled="isEditing" class="input !min-h-0 !py-1.5 text-sm">
                <option value="pg">Pilihan Ganda (PG)</option>
                <option value="pg_kompleks">PG Kompleks (Multi-Jawaban)</option>
                <option value="benar_salah">Benar / Salah</option>
                <option value="menjodohkan">Menjodohkan</option>
                <option value="essay">Essay / Uraian</option>
              </select>
            </div>
          </div>

          <!-- Soal Konten -->
          <div>
            <div class="flex items-center justify-between mb-1">
              <label class="block text-xs font-medium text-slate-700">Pertanyaan / Konten Soal</label>
              <button type="button" @click="showMediaModal = true" class="text-xs font-semibold text-brand-600 hover:underline">
                📷 Sisipkan Gambar / Audio
              </button>
            </div>
            <textarea
              v-model="formKonten"
              rows="4"
              required
              placeholder="Tuliskan pertanyaan di sini. Gunakan $rumus$ untuk rumus matematika KaTeX..."
              class="input text-sm font-sans"
            ></textarea>

            <!-- Live KaTeX Preview -->
            <div v-if="formKonten" class="mt-2 rounded bg-slate-50 border p-3">
              <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Pratinjau Rumus Math (KaTeX):</p>
              <div class="text-sm text-slate-800" v-html="renderMathInHtml(formKonten)"></div>
            </div>
          </div>

          <!-- Dynamic Options Form -->
          <!-- 1. Pilihan Ganda -->
          <div v-if="formTipe === 'pg'" class="space-y-2">
            <label class="block text-xs font-bold text-slate-700">Opsi Jawaban (Pilih 1 kunci yang benar):</label>
            <div v-for="(opsi, idx) in formOpsi" :key="idx" class="flex items-center gap-2">
              <input
                type="radio"
                name="kunci_pg"
                :checked="opsi.isCorrect"
                @change="formOpsi.forEach((o, i) => o.isCorrect = (i === idx))"
                class="h-4 w-4 text-brand-600"
              />
              <span class="font-bold text-sm w-4">{{ String.fromCharCode(65 + idx) }}.</span>
              <input v-model="opsi.konten" required placeholder="Teks opsi..." class="input !min-h-0 !py-1 text-sm flex-1" />
            </div>
          </div>

          <!-- 2. PG Kompleks -->
          <div v-if="formTipe === 'pg_kompleks'" class="space-y-2">
            <label class="block text-xs font-bold text-slate-700">Opsi Jawaban (Centang semua kunci yang benar):</label>
            <div v-for="(opsi, idx) in formOpsi" :key="idx" class="flex items-center gap-2">
              <input type="checkbox" v-model="opsi.isCorrect" class="h-4 w-4 rounded text-brand-600" />
              <span class="font-bold text-sm w-4">{{ String.fromCharCode(65 + idx) }}.</span>
              <input v-model="opsi.konten" required placeholder="Teks opsi..." class="input !min-h-0 !py-1 text-sm flex-1" />
            </div>
          </div>

          <!-- 3. Benar / Salah -->
          <div v-if="formTipe === 'benar_salah'" class="space-y-2">
            <label class="block text-xs font-bold text-slate-700">Daftar Pernyataan & Kunci:</label>
            <div v-for="(item, idx) in formOpsi" :key="idx" class="flex items-center gap-2">
              <span class="font-bold text-xs w-6">{{ idx + 1 }}.</span>
              <input v-model="item.konten" required placeholder="Pernyataan..." class="input !min-h-0 !py-1 text-sm flex-1" />
              <select v-model="item.jawabanBS" class="input !min-h-0 !py-1 text-xs w-28">
                <option value="Benar">Benar</option>
                <option value="Salah">Salah</option>
              </select>
            </div>
            <button
              type="button"
              @click="formOpsi.push({ konten: '', jawabanBS: 'Benar' })"
              class="text-xs text-brand-600 font-semibold"
            >
              + Tambah Pernyataan
            </button>
          </div>

          <!-- 4. Menjodohkan -->
          <div v-if="formTipe === 'menjodohkan'" class="space-y-2">
            <label class="block text-xs font-bold text-slate-700">Pasangan Menjodohkan (Kiri ke Kanan):</label>
            <div v-for="(pair, idx) in formMenjodohkan" :key="idx" class="flex items-center gap-2">
              <span class="font-bold text-xs w-4">{{ idx + 1 }}.</span>
              <input v-model="pair.kiri" required placeholder="Item Kiri (Pertanyaan)..." class="input !min-h-0 !py-1 text-sm flex-1" />
              <span>↔</span>
              <input v-model="pair.kanan" required placeholder="Item Kanan (Jawaban)..." class="input !min-h-0 !py-1 text-sm flex-1" />
            </div>
            <button
              type="button"
              @click="formMenjodohkan.push({ kiri: '', kanan: '' })"
              class="text-xs text-brand-600 font-semibold"
            >
              + Tambah Pasangan
            </button>
          </div>

          <!-- 5. Essay Rubrik -->
          <div v-if="formTipe === 'essay'">
            <label class="block text-xs font-medium text-slate-700">Rubrik / Pedoman Penilaian (Opsional)</label>
            <textarea
              v-model="formRubrik"
              rows="3"
              placeholder="Petunjuk penilaian bagi guru saat koreksi manual..."
              class="input text-sm"
            ></textarea>
          </div>

          <div class="flex justify-end gap-2 border-t pt-3">
            <button type="button" @click="showModal = false" class="btn-secondary !min-h-0 !py-1.5 text-xs">Batal</button>
            <button type="submit" class="btn-primary !min-h-0 !py-1.5 text-xs" :disabled="loading">
              {{ loading ? 'Menyimpan...' : 'Simpan Butir Soal' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Media Upload Modal -->
    <div v-if="showMediaModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div class="card w-full max-w-md bg-white">
        <h3 class="text-base font-bold text-slate-800 mb-3">Unggah Media Soal</h3>
        <p class="text-xs text-slate-500 mb-4">
          Pilih gambar (JPG/PNG/WEBP maks 2MB) atau berkas suara (MP3/WAV maks 10MB).
        </p>

        <input type="file" accept="image/*,audio/*" @change="onMediaFileSelect" class="input !min-h-0 !py-1 text-xs mb-3" />

        <div v-if="uploadedMediaUrl" class="rounded bg-green-50 p-2 text-xs text-status-ok border border-green-200 mb-3">
          ✓ Media berhasil diunggah! Klik "Sisipkan ke Soal".
        </div>

        <div class="flex justify-end gap-2">
          <button @click="showMediaModal = false" class="btn-secondary !min-h-0 !py-1 text-xs">Tutup</button>
          <button
            v-if="!uploadedMediaUrl"
            @click="uploadMedia"
            :disabled="!mediaFile || mediaLoading"
            class="btn-primary !min-h-0 !py-1 text-xs"
          >
            {{ mediaLoading ? 'Mengunggah...' : 'Unggah Media' }}
          </button>
          <button
            v-else
            @click="insertMediaSnippet"
            class="btn-primary !min-h-0 !py-1 text-xs"
          >
            Sisipkan ke Soal
          </button>
        </div>
      </div>
    </div>

    <!-- Word 2-Step Import Wizard Modal -->
    <div v-if="showWordImportModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div class="card w-full max-w-3xl bg-white max-h-[90vh] overflow-y-auto">
        <div class="mb-4 flex items-center justify-between border-b pb-3">
          <h2 class="text-lg font-bold text-slate-800">
            Impor Soal Word (.docx) (Langkah {{ wordImportStep }} dari 2)
          </h2>
          <button @click="showWordImportModal = false" class="text-slate-400 hover:text-slate-600">✕</button>
        </div>

        <!-- Step 1: Upload Word Document -->
        <div v-if="wordImportStep === 1" class="space-y-4">
          <p class="text-sm text-slate-600">
            Unggah dokumen Microsoft Word (.docx) dengan tag format baku [SOAL]...[/SOAL].
          </p>

          <div class="rounded-lg border-2 border-dashed border-slate-300 p-8 text-center">
            <input type="file" accept=".docx" @change="onWordFileSelect" class="hidden" id="wordFileInput" />
            <label for="wordFileInput" class="cursor-pointer">
              <div class="text-4xl mb-2">📄</div>
              <p class="text-sm font-semibold text-brand-600">Klik untuk memilih berkas Word (.docx)</p>
              <p class="text-xs text-slate-400 mt-1">Mendukung teks, gambar, tabel, dan rumus matematika Word</p>
            </label>
            <p v-if="wordFile" class="mt-3 text-xs font-semibold text-slate-700 bg-slate-100 py-1 px-3 rounded inline-block">
              Terpilih: {{ wordFile.name }}
            </p>
          </div>

          <div class="flex justify-end gap-2 pt-2">
            <button @click="showWordImportModal = false" class="btn-secondary !min-h-0 !py-2 text-xs">Batal</button>
            <button
              @click="previewWord"
              :disabled="!wordFile || wordImportLoading"
              class="btn-primary !min-h-0 !py-2 text-xs"
            >
              {{ wordImportLoading ? 'Membaca Dokumen Word...' : 'Lanjut ke Pratinjau Soal →' }}
            </button>
          </div>
        </div>

        <!-- Step 2: Preview Questions & Validation -->
        <div v-if="wordImportStep === 2" class="space-y-4">
          <div class="grid grid-cols-3 gap-3 text-center">
            <div class="rounded bg-slate-100 p-2">
              <p class="text-xs text-slate-500">Total Soal Terdeteksi</p>
              <p class="text-lg font-bold text-slate-800">{{ wordPreviewItems.length }}</p>
            </div>
            <div class="rounded bg-green-50 p-2">
              <p class="text-xs text-green-700">Soal Valid</p>
              <p class="text-lg font-bold text-status-ok">{{ wordPreviewItems.filter(i => !i.error).length }}</p>
            </div>
            <div class="rounded bg-red-50 p-2">
              <p class="text-xs text-red-700">Soal Error</p>
              <p class="text-lg font-bold text-status-danger">{{ wordImportErrors.length }}</p>
            </div>
          </div>

          <!-- Error Alert if any -->
          <div v-if="wordImportErrors.length > 0" class="rounded-lg border border-red-200 bg-red-50 p-3 text-xs text-red-700">
            <h4 class="font-bold mb-1">Ditemukan Kesalahan pada Format Soal:</h4>
            <div v-for="(err, eIdx) in wordImportErrors" :key="eIdx">
              • Soal #{{ err.index || '?' }}: {{ err.message }}
            </div>
            <p class="mt-2 italic font-semibold">
              * Menurut aturan FR-FE-111, simpan diblokir hingga dokumen diperbaiki.
            </p>
          </div>

          <!-- Preview Items List -->
          <div class="max-h-60 overflow-y-auto space-y-2 border rounded-lg p-2">
            <div
              v-for="(item, idx) in wordPreviewItems"
              :key="idx"
              class="rounded border p-2 text-xs bg-slate-50 flex items-start gap-2"
            >
              <span class="font-bold text-brand-700">#{{ idx + 1 }}</span>
              <span class="rounded bg-slate-200 px-1 py-0.5 text-[10px] uppercase font-bold">{{ item.tipe }}</span>
              <div class="flex-1 truncate" v-html="renderMathInHtml(item.konten)"></div>
              <span v-if="item.error" class="text-status-danger font-bold">Error</span>
              <span v-else class="text-status-ok font-bold">✓ Valid</span>
            </div>
          </div>

          <div class="flex justify-between items-center pt-2">
            <button @click="wordImportStep = 1" class="btn-secondary !min-h-0 !py-2 text-xs">← Kembali</button>
            <button
              @click="commitWord"
              :disabled="wordImportErrors.length > 0 || wordPreviewItems.length === 0 || wordImportLoading"
              class="btn-primary !min-h-0 !py-2 text-xs"
            >
              {{ wordImportLoading ? 'Menyimpan...' : 'Simpan Soal ke Bank Soal' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
