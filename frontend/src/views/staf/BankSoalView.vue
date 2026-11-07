<script setup lang="ts">
import { ref, onMounted, computed, defineAsyncComponent } from 'vue'
import StafLayout from '@/components/StafLayout.vue'

const RichEditor = defineAsyncComponent(() => import('@/components/RichEditor.vue'))
const editorKonten = ref<{ insertImage: (src: string) => void; insertAudio: (src: string) => void } | null>(null)
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue'
import { apiClient } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { renderMathInHtml } from '@/lib/katex'
import { downloadFileFromApi } from '@/lib/download'

const auth = useAuthStore()
const isSuperadmin = computed(() => auth.user?.role === 'superadmin')
const isOperator = computed(() => auth.user?.role === 'operator')
const isGuru = computed(() => auth.user?.role === 'guru')
const teacherMapelIds = computed(() => ((auth.user as any)?.mapel_ids || []) as number[])

const canManageSelectedMapel = computed(() => {
  return isSuperadmin.value || isOperator.value || isGuru.value
})

type TipeSoal = 'pg' | 'pg_kompleks' | 'benar_salah' | 'menjodohkan' | 'essay'

interface OpsiItem {
  id?: number
  konten: string
  sisi?: 'kiri' | 'kanan'
  urutan?: number
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

// Action Loading & Confirmation States
const isSavingSoal = ref(false)
const showDeleteModal = ref(false)
const soalIdToDelete = ref<number | null>(null)
const isDeletingSoal = ref(false)
const downloadTemplateLoading = ref(false)
const exportLoading = ref(false)

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
const wordImportMapelId = ref<number | ''>('')
const wordPreviewItems = ref<any[]>([])
const wordImportLoading = ref(false)
const wordImportErrors = ref<any[]>([])

async function fetchInitial() {
  loading.value = true
  errorMessage.value = ''
  try {
    const resMapel = await apiClient.get('/mapel')
    mapelList.value = resMapel.data.data || []
    if (mapelList.value.length > 0 && !selectedMapel.value) {
      if (isGuru.value) {
        const firstTaught = mapelList.value.find(m => teacherMapelIds.value.includes(m.id))
        selectedMapel.value = firstTaught ? firstTaught.id : mapelList.value[0].id
      } else {
        selectedMapel.value = mapelList.value[0].id
      }
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
    const rawKunci = String(item.kunci || '').toUpperCase().trim()
    let foundCorrect = false
    formOpsi.value = (item.opsi_soal || []).map((o, idx) => {
      const huruf = String.fromCharCode(65 + idx)
      const isCorrect = rawKunci === huruf
        || rawKunci === String(idx + 1)
        || rawKunci === String(o.id)
        || rawKunci === String(o.urutan)
      if (isCorrect) foundCorrect = true
      return {
        konten: o.konten,
        isCorrect
      }
    })
    if (!foundCorrect && formOpsi.value.length > 0) {
      formOpsi.value[0].isCorrect = true
    }
  } else if (item.tipe === 'pg_kompleks') {
    const rawKunci = Array.isArray(item.kunci)
      ? item.kunci.map(k => String(k).toUpperCase().trim())
      : (typeof item.kunci === 'object' && item.kunci !== null
          ? Object.keys(item.kunci).map(k => String(k).toUpperCase().trim())
          : [String(item.kunci || '').toUpperCase().trim()])
    formOpsi.value = (item.opsi_soal || []).map((o, idx) => {
      const huruf = String.fromCharCode(65 + idx)
      const isCorrect = rawKunci.includes(huruf)
        || rawKunci.includes(String(idx + 1))
        || rawKunci.includes(String(o.id))
        || rawKunci.includes(String(o.urutan))
      return {
        konten: o.konten,
        isCorrect
      }
    })
  } else if (item.tipe === 'benar_salah') {
    const kunciMap = typeof item.kunci === 'object' && item.kunci !== null ? item.kunci : {}
    formOpsi.value = (item.opsi_soal || []).map((o, idx) => ({
      konten: o.konten,
      jawabanBS: kunciMap[idx + 1] || (o.id ? kunciMap[o.id] : undefined) || 'Benar'
    }))
  } else if (item.tipe === 'menjodohkan') {
    const kiri = (item.opsi_soal || []).filter(o => o.sisi === 'kiri')
    const kanan = (item.opsi_soal || []).filter(o => o.sisi === 'kanan')
    const kunciMap = typeof item.kunci === 'object' && item.kunci !== null ? item.kunci : {}
    formMenjodohkan.value = kiri.map((k, idx) => {
      let targetKanan = kanan[idx]?.konten || ''
      const targetVal = kunciMap[idx + 1] || (k.id ? kunciMap[k.id] : undefined)
      if (typeof targetVal === 'string' && /^[a-z]$/i.test(targetVal)) {
        const knIdx = targetVal.toLowerCase().charCodeAt(0) - 97
        if (kanan[knIdx]) targetKanan = kanan[knIdx].konten
      } else if (typeof targetVal === 'number' || (typeof targetVal === 'string' && /^[0-9]+$/.test(targetVal))) {
        const knIdx = parseInt(String(targetVal), 10) - 1
        if (kanan[knIdx]) targetKanan = kanan[knIdx].konten
      }
      return {
        kiri: k.konten,
        kanan: targetKanan
      }
    })
  } else {
    formOpsi.value = []
  }

  showModal.value = true
}

async function saveSoal() {
  // Editor kaya tidak punya atribut `required`; pastikan ada teks atau media (gambar/audio).
  const adaIsi = formKonten.value.replace(/<[^>]*>/g, '').trim() !== '' || /<(img|audio)\b/i.test(formKonten.value)
  if (!adaIsi) {
    errorMessage.value = 'Isi pertanyaan wajib diisi.'
    return
  }
  loading.value = true
  isSavingSoal.value = true
  errorMessage.value = ''
  feedbackMessage.value = ''

  let payloadKunci: any = null
  let payloadOpsi: any[] = []

  if (formTipe.value === 'pg') {
    const correctIdx = formOpsi.value.findIndex(o => o.isCorrect)
    if (correctIdx === -1 && formOpsi.value.length > 0) {
      errorMessage.value = 'Silakan pilih satu opsi sebagai kunci jawaban yang benar.'
      loading.value = false
      isSavingSoal.value = false
      return
    }
    payloadKunci = correctIdx >= 0 ? String.fromCharCode(65 + correctIdx) : 'A'
    payloadOpsi = formOpsi.value.map(o => ({ konten: o.konten }))
  } else if (formTipe.value === 'pg_kompleks') {
    const keys = formOpsi.value
      .map((o, idx) => (o.isCorrect ? String.fromCharCode(65 + idx) : null))
      .filter((v): v is string => v !== null)
    if (keys.length < 2) {
      errorMessage.value = 'Pilih minimal 2 opsi sebagai kunci jawaban benar untuk tipe PG Kompleks.'
      loading.value = false
      isSavingSoal.value = false
      return
    }
    payloadKunci = keys
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
      payloadKunci[idx + 1] = String.fromCharCode(97 + idx)
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
    const details = err.response?.data?.error?.details
    if (details && typeof details === 'object') {
      const msgs = Object.values(details).flat().join(', ')
      errorMessage.value = `${err.response?.data?.error?.message || 'Gagal menyimpan soal.'} (${msgs})`
    } else {
      errorMessage.value = err.response?.data?.error?.message || err.message || 'Gagal menyimpan soal.'
    }
  } finally {
    loading.value = false
    isSavingSoal.value = false
  }
}

function promptDeleteSoal(id: number) {
  soalIdToDelete.value = id
  showDeleteModal.value = true
}

async function confirmDeleteSoal() {
  if (!soalIdToDelete.value) return
  isDeletingSoal.value = true
  errorMessage.value = ''
  feedbackMessage.value = ''
  try {
    await apiClient.delete(`/bank-soal/${soalIdToDelete.value}`)
    feedbackMessage.value = 'Butir soal berhasil dihapus.'
    showDeleteModal.value = false
    soalIdToDelete.value = null
    await fetchSoal()
  } catch (err: any) {
    errorMessage.value = err.message || 'Gagal menghapus butir soal.'
  } finally {
    isDeletingSoal.value = false
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
    editorKonten.value?.insertAudio(uploadedMediaUrl.value)
  } else {
    editorKonten.value?.insertImage(uploadedMediaUrl.value)
  }
  showMediaModal.value = false
  uploadedMediaUrl.value = ''
  mediaFile.value = null
}

// Download Word Template
async function downloadWordTemplate() {
  errorMessage.value = ''
  downloadTemplateLoading.value = true
  try {
    await downloadFileFromApi('/templates/word', 'template_soal.docx')
  } catch (err: any) {
    errorMessage.value = err.response?.data?.message || err.message || 'Gagal mengunduh template soal Word.'
  } finally {
    downloadTemplateLoading.value = false
  }
}

// Export Word Soal
async function exportWordSoal() {
  if (!selectedMapel.value) {
    errorMessage.value = 'Silakan pilih Mata Pelajaran terlebih dahulu sebelum melakukan ekspor.'
    return
  }
  errorMessage.value = ''
  exportLoading.value = true
  try {
    const selectedObj = mapelList.value.find(m => m.id === selectedMapel.value)
    const filename = `bank_soal_${selectedObj?.kode || selectedMapel.value}.docx`
    await downloadFileFromApi(`/export/word/bank-soal?mapel_id=${selectedMapel.value}`, filename)
  } catch (err: any) {
    errorMessage.value = err.response?.data?.message || err.message || 'Gagal mengekspor soal Word.'
  } finally {
    exportLoading.value = false
  }
}

// Word (.docx) Import Wizard
function openWordImport() {
  wordFile.value = null
  wordImportStep.value = 1
  wordPreviewItems.value = []
  wordImportErrors.value = []
  wordImportMapelId.value = selectedMapel.value || (mapelList.value[0]?.id ?? '')
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
  const targetMapelId = wordImportMapelId.value || selectedMapel.value
  if (!targetMapelId) {
    errorMessage.value = 'Silakan pilih Mata Pelajaran tujuan impor terlebih dahulu.'
    return
  }
  wordImportLoading.value = true
  errorMessage.value = ''
  try {
    await apiClient.post('/import/word/commit', {
      mapel_id: targetMapelId,
      items: wordPreviewItems.value
    })
    feedbackMessage.value = 'Soal dari berkas Word berhasil disimpan ke Bank Soal.'
    showWordImportModal.value = false
    selectedMapel.value = targetMapelId
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
  <StafLayout>
    <div class="space-y-6">
      <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
        <div>
          <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Bank Soal</h1>
          <p class="text-sm text-slate-500">
            Kelola butir soal 5 tipe, visualisasi formula KaTeX, dan berkas ujian.
          </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <span v-if="!canManageSelectedMapel" class="inline-flex items-center gap-1.5 rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-600 border border-slate-200">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-3.5 w-3.5 text-slate-500">
              <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
              <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
            </svg>
            <span>Mode Lihat Saja</span>
          </span>
          <button
            @click="downloadWordTemplate"
            :disabled="downloadTemplateLoading"
            class="btn-secondary !min-h-0 !py-2 text-xs flex items-center gap-1.5 cursor-pointer disabled:opacity-60"
            title="Unduh format template soal .docx"
          >
            <span v-if="downloadTemplateLoading" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-slate-600 border-t-transparent"></span>
            <svg v-else xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m.75 12 3 3m0 0 3-3m-3 3v-6m-1.5-9H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
            </svg>
            <span>{{ downloadTemplateLoading ? 'Mengunduh...' : 'Unduh Template' }}</span>
          </button>
          <button v-if="canManageSelectedMapel" @click="openWordImport" class="btn-secondary !min-h-0 !py-2 text-xs flex items-center gap-1.5" title="Impor butir soal dari dokumen Word (.docx)">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
            </svg>
            <span>Impor Soal</span>
          </button>
          <button
            @click="exportWordSoal"
            :disabled="exportLoading"
            class="btn-secondary !min-h-0 !py-2 text-xs flex items-center gap-1.5 cursor-pointer disabled:opacity-60"
            title="Ekspor daftar butir soal ke dokumen Word (.docx)"
          >
            <span v-if="exportLoading" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-slate-600 border-t-transparent"></span>
            <svg v-else xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
            </svg>
            <span>{{ exportLoading ? 'Mengekspor...' : 'Ekspor Soal' }}</span>
          </button>
          <button v-if="canManageSelectedMapel" @click="openCreateModal" class="btn-primary !min-h-0 !py-2 text-xs flex items-center gap-1.5 font-medium shadow-xs">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
            </svg>
            <span>Tambah Soal</span>
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

      <!-- Filters Toolbar -->
      <div class="card bg-white p-3.5 rounded-2xl border border-slate-200/80 shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3">
          <div class="flex items-center gap-2">
            <label class="text-xs font-semibold text-slate-600">Mapel:</label>
            <select v-model="selectedMapel" @change="fetchSoal" class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-800 focus:border-brand-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-brand-100 transition-colors">
              <option v-for="m in mapelList" :key="m.id" :value="m.id">
                {{ m.kode }} - {{ m.nama }}{{ isGuru && teacherMapelIds.includes(m.id) ? ' (Diampu)' : '' }}
              </option>
            </select>
          </div>

          <div class="flex items-center gap-2">
            <label class="text-xs font-semibold text-slate-600">Tipe:</label>
            <select v-model="selectedTipe" @change="fetchSoal" class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-800 focus:border-brand-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-brand-100 transition-colors">
              <option value="">Semua Tipe Soal</option>
              <option value="pg">Pilihan Ganda (PG)</option>
              <option value="pg_kompleks">PG Kompleks</option>
              <option value="benar_salah">Benar / Salah</option>
              <option value="menjodohkan">Menjodohkan</option>
              <option value="essay">Essay / Uraian</option>
            </select>
          </div>

          <span class="hidden sm:inline-flex items-center rounded-lg bg-slate-100 px-2.5 py-1 text-[11px] font-semibold text-slate-600">
            {{ filteredSoal.length }} Butir Soal
          </span>
        </div>

        <div class="relative w-full md:w-72">
          <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
            </svg>
          </div>
          <input
            v-model="searchQuery"
            @input="fetchSoal"
            type="text"
            placeholder="Cari konten soal..."
            class="w-full rounded-xl border border-slate-200 bg-slate-50 py-1.5 pl-9 pr-3 text-xs text-slate-800 placeholder-slate-400 focus:border-brand-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-brand-100 transition-all"
          />
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

            <div v-if="canManageSelectedMapel && (isSuperadmin || isOperator || soal.guru_id === (auth.user as any)?.guru_id || !soal.guru_id)" class="flex items-center gap-1.5">
              <button
                type="button"
                @click="openEditModal(soal)"
                class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 hover:border-brand-500 hover:bg-brand-50 hover:text-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-200 transition-all shadow-2xs active:scale-95 cursor-pointer"
                title="Ubah Butir Soal"
                aria-label="Ubah Butir Soal"
              >
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
                  <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                </svg>
              </button>
              <button
                type="button"
                @click="promptDeleteSoal(soal.id)"
                class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 hover:border-red-300 hover:bg-red-50 hover:text-red-600 focus:outline-none focus:ring-2 focus:ring-red-200 transition-all shadow-2xs active:scale-95 cursor-pointer"
                title="Hapus Butir Soal"
                aria-label="Hapus Butir Soal"
              >
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
                  <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                </svg>
              </button>
            </div>
          </div>

          <!-- Soal Content with Math KaTeX -->
          <div
            class="prose prose-sm max-w-none text-slate-800 mb-4"
            dir="auto"
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
                dir="auto"
              >
                <span class="font-bold text-slate-500 shrink-0">{{ String.fromCharCode(65 + oIdx) }}.</span>
                <span class="flex-1" dir="auto" v-html="renderMathInHtml(opsi.konten)"></span>
                <span v-if="opsi.sisi" class="text-[10px] bg-slate-200 px-1 rounded ml-auto shrink-0">{{ opsi.sisi }}</span>
              </div>
            </div>
          </div>
        </div>

        <div v-if="filteredSoal.length === 0 && !loading" class="card bg-white p-12 text-center text-slate-400">
          Belum ada butir soal untuk kriteria ini. Tambahkan secara manual atau impor dari dokumen Word.
        </div>
      </div>

    <!-- Create / Edit Soal Modal -->
    <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div class="card w-full max-w-3xl bg-white max-h-[92vh] overflow-y-auto">
        <div class="mb-4 flex items-center justify-between border-b pb-3">
          <h2 class="text-lg font-bold text-slate-800">
            {{ isEditing ? 'Ubah Butir Soal' : 'Tambah Butir Soal Baru' }}
          </h2>
          <button @click="showModal = false" class="rounded-lg p-1 text-slate-400 hover:text-slate-600 hover:bg-slate-100">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <form @submit.prevent="saveSoal" class="space-y-4">
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-medium text-slate-700">Mata Pelajaran</label>
              <select v-model="formMapelId" required class="input !min-h-0 !py-1.5 text-sm">
                <option v-for="m in mapelList" :key="m.id" :value="m.id">{{ m.kode }} - {{ m.nama }}{{ isGuru && teacherMapelIds.includes(m.id) ? ' (Diampu)' : '' }}</option>
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
              <button type="button" @click="showMediaModal = true" class="text-xs font-semibold text-brand-600 hover:underline flex items-center gap-1">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-3.5 w-3.5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
                </svg>
                <span>Sisipkan Gambar / Audio</span>
              </button>
            </div>
            <!-- Editor TipTap dimuat lazy (hanya halaman staf, tidak masuk bundle siswa) -->
            <RichEditor ref="editorKonten" v-model="formKonten" placeholder="Tuliskan pertanyaan di sini" />

            <!-- Live KaTeX Preview -->
            <div v-if="formKonten" class="mt-2 rounded bg-slate-50 border p-3">
              <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Pratinjau Rumus Math (KaTeX):</p>
              <div class="text-sm text-slate-800" dir="auto" v-html="renderMathInHtml(formKonten)"></div>
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
                class="h-4 w-4 text-brand-600 shrink-0"
              />
              <span class="font-bold text-sm w-4 shrink-0">{{ String.fromCharCode(65 + idx) }}.</span>
              <input v-model="opsi.konten" required dir="auto" placeholder="Teks opsi..." class="input !min-h-0 !py-1 text-sm flex-1" />
            </div>
          </div>

          <!-- 2. PG Kompleks -->
          <div v-if="formTipe === 'pg_kompleks'" class="space-y-2">
            <label class="block text-xs font-bold text-slate-700">Opsi Jawaban (Centang semua kunci yang benar):</label>
            <div v-for="(opsi, idx) in formOpsi" :key="idx" class="flex items-center gap-2">
              <input type="checkbox" v-model="opsi.isCorrect" class="h-4 w-4 rounded text-brand-600 shrink-0" />
              <span class="font-bold text-sm w-4 shrink-0">{{ String.fromCharCode(65 + idx) }}.</span>
              <input v-model="opsi.konten" required dir="auto" placeholder="Teks opsi..." class="input !min-h-0 !py-1 text-sm flex-1" />
            </div>
          </div>

          <!-- 3. Benar / Salah -->
          <div v-if="formTipe === 'benar_salah'" class="space-y-2">
            <label class="block text-xs font-bold text-slate-700">Daftar Pernyataan & Kunci:</label>
            <div v-for="(item, idx) in formOpsi" :key="idx" class="flex items-center gap-2">
              <span class="font-bold text-xs w-6 shrink-0">{{ idx + 1 }}.</span>
              <input v-model="item.konten" required dir="auto" placeholder="Pernyataan..." class="input !min-h-0 !py-1 text-sm flex-1" />
              <select v-model="item.jawabanBS" class="input !min-h-0 !py-1 text-xs w-28 shrink-0">
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
              <span class="font-bold text-xs w-4 shrink-0">{{ idx + 1 }}.</span>
              <input v-model="pair.kiri" required dir="auto" placeholder="Item Kiri (Pertanyaan)..." class="input !min-h-0 !py-1 text-sm flex-1" />
              <span class="shrink-0">↔</span>
              <input v-model="pair.kanan" required dir="auto" placeholder="Item Kanan (Jawaban)..." class="input !min-h-0 !py-1 text-sm flex-1" />
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
              dir="auto"
              placeholder="Petunjuk penilaian bagi guru saat koreksi manual..."
              class="input text-sm"
            ></textarea>
          </div>

          <div class="flex justify-end gap-2 border-t pt-3">
            <button type="button" @click="showModal = false" :disabled="isSavingSoal" class="btn-secondary !min-h-0 !py-2 text-xs cursor-pointer">Batal</button>
            <button
              type="submit"
              class="btn-primary !min-h-0 !py-2 text-xs flex items-center justify-center gap-1.5 cursor-pointer disabled:opacity-50"
              :disabled="isSavingSoal"
            >
              <span v-if="isSavingSoal" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white border-t-transparent"></span>
              <span>{{ isSavingSoal ? 'Menyimpan...' : (isEditing ? 'Simpan Perubahan' : 'Simpan Butir Soal') }}</span>
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

        <div v-if="uploadedMediaUrl" class="rounded-xl bg-green-50 p-2.5 text-xs text-status-ok border border-green-200 mb-3 flex items-center gap-2">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4 text-emerald-600">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
          </svg>
          <span>Media berhasil diunggah! Klik "Sisipkan ke Soal".</span>
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
          <button @click="showWordImportModal = false" class="rounded-lg p-1 text-slate-400 hover:text-slate-600 hover:bg-slate-100">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <!-- Step 1: Upload Word Document -->
        <div v-if="wordImportStep === 1" class="space-y-4">
          <p class="text-sm text-slate-600">
            Unggah dokumen Microsoft Word (.docx) berisi tabel soal sesuai template resmi (satu soal satu tabel; isi hanya kolom kanan).
          </p>

          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Mata Pelajaran Tujuan Impor *</label>
            <select v-model="wordImportMapelId" class="w-full rounded-xl border border-slate-200 bg-white p-2.5 text-xs text-slate-800 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100">
              <option value="" disabled>-- Pilih Mata Pelajaran --</option>
              <option v-for="m in mapelList" :key="m.id" :value="m.id">
                {{ m.kode }} - {{ m.nama }}{{ isGuru && teacherMapelIds.includes(m.id) ? ' (Diampu)' : '' }}
              </option>
            </select>
          </div>

          <div class="rounded-2xl border-2 border-dashed border-slate-300 p-8 text-center bg-slate-50/50 hover:bg-slate-50 transition-colors">
            <input type="file" accept=".docx" @change="onWordFileSelect" class="hidden" id="wordFileInput" />
            <label for="wordFileInput" class="cursor-pointer">
              <div class="flex h-12 w-12 mx-auto items-center justify-center rounded-2xl bg-blue-50 text-blue-600 mb-3 shadow-xs">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-6 w-6">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m.75 12 3 3m0 0 3-3m-3 3v-6m-1.5-9H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                </svg>
              </div>
              <p class="text-sm font-semibold text-brand-600">Klik untuk memilih berkas Word (.docx)</p>
              <p class="text-xs text-slate-400 mt-1">Mendukung teks, gambar, tabel, dan rumus matematika Word</p>
            </label>
            <p v-if="wordFile" class="mt-3 text-xs font-semibold text-slate-700 bg-white py-1.5 px-3.5 rounded-lg border border-slate-200 inline-block shadow-xs">
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
            <div class="rounded-xl bg-slate-100 p-2.5">
              <p class="text-xs text-slate-500">Total Soal Terdeteksi</p>
              <p class="text-lg font-bold text-slate-800 font-mono">{{ wordPreviewItems.length }}</p>
            </div>
            <div class="rounded-xl bg-green-50 p-2.5">
              <p class="text-xs text-green-700">Soal Valid</p>
              <p class="text-lg font-bold text-status-ok font-mono">{{ wordPreviewItems.filter(i => !i.error).length }}</p>
            </div>
            <div class="rounded-xl bg-red-50 p-2.5">
              <p class="text-xs text-red-700">Soal Error</p>
              <p class="text-lg font-bold text-status-danger font-mono">{{ wordImportErrors.length }}</p>
            </div>
          </div>

          <!-- Error Alert if any -->
          <div v-if="wordImportErrors.length > 0" class="rounded-xl border border-red-200 bg-red-50 p-3.5 text-xs text-red-700">
            <h4 class="font-bold mb-1">Ditemukan Kesalahan pada Format Soal:</h4>
            <div v-for="(err, eIdx) in wordImportErrors" :key="eIdx">
              • Soal #{{ err.index || '?' }}: {{ err.message }}
            </div>
            <p class="mt-2 italic font-semibold">
              * Harap perbaiki seluruh kesalahan format di atas sebelum menyimpan butir soal.
            </p>
          </div>

          <!-- Preview Items List -->
          <div class="max-h-60 overflow-y-auto space-y-2 border rounded-xl p-2.5 bg-white">
            <div
              v-for="(item, idx) in wordPreviewItems"
              :key="idx"
              class="rounded-lg border border-slate-200 p-2.5 text-xs bg-slate-50 flex items-start gap-2"
            >
              <span class="font-bold text-brand-700 font-mono shrink-0">#{{ idx + 1 }}</span>
              <span class="rounded bg-slate-200 px-1.5 py-0.5 text-[10px] uppercase font-bold shrink-0">{{ item.tipe }}</span>
              <div class="flex-1 truncate" dir="auto" v-html="renderMathInHtml(item.konten || item.teks)"></div>
              <span v-if="item.error" class="text-status-danger font-bold">Error</span>
              <span v-else class="text-status-ok font-bold flex items-center gap-1">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-3.5 w-3.5 text-emerald-600">
                  <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                </svg>
                Valid
              </span>
            </div>
          </div>

          <div class="flex justify-between items-center pt-2">
            <button @click="wordImportStep = 1" :disabled="wordImportLoading" class="btn-secondary !min-h-0 !py-2 text-xs">← Kembali</button>
            <button
              @click="commitWord"
              :disabled="wordImportErrors.length > 0 || wordPreviewItems.length === 0 || wordImportLoading"
              class="btn-primary !min-h-0 !py-2 text-xs flex items-center justify-center gap-1.5 cursor-pointer disabled:opacity-50"
            >
              <span v-if="wordImportLoading" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white border-t-transparent"></span>
              <span>{{ wordImportLoading ? 'Menyimpan...' : 'Simpan Soal ke Bank Soal' }}</span>
            </button>
          </div>
        </div>
      </div>
    </div>
    </div>

    <!-- Confirm Delete Modal with Custom Styling -->
    <ConfirmDeleteModal
      v-model="showDeleteModal"
      :loading="isDeletingSoal"
      title="Hapus Butir Soal"
      message="Apakah Anda yakin ingin menghapus butir soal ini? Tindakan ini bersifat permanen dan butir soal tidak dapat dipulihkan."
      confirm-text="Ya, Hapus"
      cancel-text="Batal"
      @confirm="confirmDeleteSoal"
    />
  </StafLayout>
</template>
