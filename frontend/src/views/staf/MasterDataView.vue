<script setup lang="ts">
import TabelMemuat from '@/components/TabelMemuat.vue'
import { ref, onMounted, computed, watch } from 'vue'
import { useRoute } from 'vue-router'
import { TAB_MASTER, tabValid } from '@/lib/navigasi'
import { ambilHalaman } from '@/lib/paginasi'
import StafLayout from '@/components/StafLayout.vue'
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue'
import { apiClient } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { downloadFileFromApi } from '@/lib/download'

type TabType = 'siswa' | 'guru' | 'kelas' | 'mapel' | 'ruang' | 'tahun_ajaran' | 'guru_mapel'

const UKURAN_HALAMAN = 25
const halaman = ref(1)
const filterKelasId = ref<number | ''>('')

const auth = useAuthStore()
const isSuperadmin = computed(() => auth.user?.role === 'superadmin')
const isOperatorOrSuperadmin = computed(() => auth.user?.role === 'superadmin' || auth.user?.role === 'operator')

const route = useRoute()
// Tab dipilih lewat sub-menu sidebar (query `tab`).
const activeTab = ref<TabType>(tabValid(TAB_MASTER, route.query.tab) as TabType)
const judulTab = computed(() => TAB_MASTER.find((t) => t.tab === activeTab.value)?.nama ?? 'Master Data')
// Tahun ajaran hanya dapat diubah Superadmin; data master lain oleh Operator dan Superadmin.
const bolehUbahTab = computed(() => (activeTab.value === 'tahun_ajaran' ? isSuperadmin.value : isOperatorOrSuperadmin.value))
const loading = ref(false)
const searchQuery = ref('')
const feedbackMessage = ref('')
const errorMessage = ref('')

// Action Loading & Confirmation States
const saveLoading = ref(false)
const showDeleteModal = ref(false)
const entityIdToDelete = ref<number | null>(null)
const deleteLoading = ref(false)
const deleteTitle = ref('Hapus Data')
const deleteMessage = ref('')
const downloadTemplateLoading = ref(false)

// Data lists
const siswaList = ref<any[]>([])
const guruList = ref<any[]>([])
const kelasList = ref<any[]>([])
const mapelList = ref<any[]>([])
const ruangList = ref<any[]>([])
const tahunAjaranList = ref<any[]>([])
const guruMapelList = ref<any[]>([])

// Modal Form State
const showFormModal = ref(false)
const isEditing = ref(false)
const editId = ref<number | null>(null)
const formData = ref<Record<string, any>>({})

// Excel Import Modal State
const showImportModal = ref(false)
const importFile = ref<File | null>(null)
const importJenis = ref<string>('siswa')
const importLoading = ref(false)
const importStep = ref<1 | 2>(1)
const importPreviewData = ref<any>(null)
const importErrors = ref<any[]>([])
const importRows = ref<Record<string, string>[]>([])

async function fetchData() {
  loading.value = true
  errorMessage.value = ''
  try {
    const [taRes, klsRes, mplRes, rngRes] = await Promise.all([
      apiClient.get('/tahun-ajaran'),
      apiClient.get('/kelas'),
      apiClient.get('/mapel'),
      apiClient.get('/ruang')
    ])
    tahunAjaranList.value = taRes.data.data || []
    kelasList.value = klsRes.data.data || []
    mapelList.value = mplRes.data.data || []
    ruangList.value = rngRes.data.data || []

    if (activeTab.value === 'siswa') {
      const res = await apiClient.get('/siswa')
      siswaList.value = res.data.data || []
    } else if (activeTab.value === 'guru') {
      const res = await apiClient.get('/guru')
      guruList.value = res.data.data || []
    } else if (activeTab.value === 'guru_mapel') {
      const [gmRes, guruRes] = await Promise.all([apiClient.get('/guru-mapel'), apiClient.get('/guru')])
      guruMapelList.value = gmRes.data.data || []
      guruList.value = guruRes.data.data || []
    }
  } catch (err: any) {
    errorMessage.value = err.message || 'Gagal memuat data.'
  } finally {
    loading.value = false
  }
}

async function handleTabChange(tab: TabType) {
  activeTab.value = tab
  searchQuery.value = ''
  filterKelasId.value = ''
  halaman.value = 1
  if (tab === 'guru_mapel') {
    await fetchData()
    return
  }
  if (tab === 'siswa' && siswaList.value.length === 0) {
    loading.value = true
    const res = await apiClient.get('/siswa')
    siswaList.value = res.data.data || []
    loading.value = false
  } else if (tab === 'guru' && guruList.value.length === 0) {
    loading.value = true
    const res = await apiClient.get('/guru')
    guruList.value = res.data.data || []
    loading.value = false
  }
}

// Filtered Lists
const filteredSiswa = computed(() => {
  const q = searchQuery.value.toLowerCase()
  return siswaList.value.filter(s => {
    if (filterKelasId.value !== '' && (s.kelas_id ?? s.kelas?.id) !== filterKelasId.value) return false
    const nama = (s.nama || s.user?.name || '').toLowerCase()
    const nis = (s.nis || '').toLowerCase()
    const username = (s.username || s.user?.username || '').toLowerCase()
    return nama.includes(q) || nis.includes(q) || username.includes(q)
  })
})

const filteredGuruMapel = computed(() => {
  const q = searchQuery.value.toLowerCase()
  return guruMapelList.value.filter(gm =>
    (gm.guru?.user?.name || '').toLowerCase().includes(q) || (gm.mapel?.nama || '').toLowerCase().includes(q) || (gm.kelas?.nama || '').toLowerCase().includes(q)
  )
})

// Paginasi sisi klien untuk daftar besar (siswa & guru)
const halamanSiswa = computed(() => ambilHalaman(filteredSiswa.value, halaman.value, UKURAN_HALAMAN))
const halamanGuru = computed(() => ambilHalaman(filteredGuru.value, halaman.value, UKURAN_HALAMAN))
const halamanGuruMapel = computed(() => ambilHalaman(filteredGuruMapel.value, halaman.value, UKURAN_HALAMAN))
const infoHalaman = computed(() => {
  if (activeTab.value === 'siswa') return halamanSiswa.value
  if (activeTab.value === 'guru') return halamanGuru.value
  if (activeTab.value === 'guru_mapel') return halamanGuruMapel.value
  return null
})

watch([searchQuery, filterKelasId], () => {
  halaman.value = 1
})

const filteredGuru = computed(() => {
  const q = searchQuery.value.toLowerCase()
  return guruList.value.filter(g => {
    const nama = (g.nama || g.user?.name || '').toLowerCase()
    const nip = (g.nip || '').toLowerCase()
    const username = (g.username || g.user?.username || '').toLowerCase()
    return nama.includes(q) || nip.includes(q) || username.includes(q)
  })
})

const filteredKelas = computed(() => {
  const q = searchQuery.value.toLowerCase()
  return kelasList.value.filter(k => k.nama?.toLowerCase().includes(q))
})

const filteredMapel = computed(() => {
  const q = searchQuery.value.toLowerCase()
  return mapelList.value.filter(m => m.nama?.toLowerCase().includes(q) || m.kode?.toLowerCase().includes(q))
})

const filteredRuang = computed(() => {
  const q = searchQuery.value.toLowerCase()
  return ruangList.value.filter(r => r.nama?.toLowerCase().includes(q) || r.kode?.toLowerCase().includes(q))
})

const filteredTahunAjaran = computed(() => {
  const q = searchQuery.value.toLowerCase()
  return tahunAjaranList.value.filter(ta => ta.nama?.toLowerCase().includes(q))
})

// Create / Edit Entity
function openCreateModal() {
  isEditing.value = false
  editId.value = null
  if (activeTab.value === 'siswa') {
    formData.value = {
      nis: '',
      nama: '',
      kelas_id: kelasList.value[0]?.id || '',
      jk: 'L',
      username: '',
      password: ''
    }
  } else if (activeTab.value === 'guru') {
    formData.value = {
      nip: '',
      nama: '',
      username: '',
      password: '',
      mapel_ids: [],
      role: 'guru'
    }
  } else if (activeTab.value === 'kelas') {
    formData.value = {
      nama: '',
      tingkat: '7',
      tahun_ajaran_id: tahunAjaranList.value[0]?.id || ''
    }
  } else if (activeTab.value === 'mapel') {
    formData.value = { kode: '', nama: '' }
  } else if (activeTab.value === 'ruang') {
    formData.value = { kode: '', nama: '', kapasitas: 36 }
  } else if (activeTab.value === 'tahun_ajaran') {
    formData.value = { semester: 1, aktif: true }
  } else if (activeTab.value === 'guru_mapel') {
    formData.value = {
      guru_id: guruList.value[0]?.id || '',
      mapel_id: mapelList.value[0]?.id || '',
      kelas_id: kelasList.value[0]?.id || '',
      tahun_ajaran_id: tahunAjaranList.value.find(t => t.aktif)?.id || tahunAjaranList.value[0]?.id || ''
    }
  } else {
    formData.value = {}
  }
  showFormModal.value = true
}

function openEditModal(item: any) {
  isEditing.value = true
  editId.value = item.id
  if (activeTab.value === 'siswa') {
    formData.value = {
      id: item.id,
      nis: item.nis || '',
      nama: item.nama || item.user?.name || '',
      kelas_id: item.kelas_id || item.kelas?.id || '',
      jk: item.jk || item.jenis_kelamin || 'L',
      username: item.username || item.user?.username || '',
      password: ''
    }
  } else if (activeTab.value === 'guru') {
    formData.value = {
      id: item.id,
      nip: item.nip || '',
      nama: item.nama || item.user?.name || '',
      username: item.username || item.user?.username || '',
      password: '',
      mapel_ids: (item.mapel || []).map((m: any) => m.id),
      role: item.user?.role || 'guru'
    }
  } else {
    formData.value = { ...item }
  }
  showFormModal.value = true
}

async function saveEntity() {
  loading.value = true
  saveLoading.value = true
  feedbackMessage.value = ''
  errorMessage.value = ''
  try {
    const endpoint = activeTab.value.replace('_', '-')
    let payload = { ...formData.value }

    if (activeTab.value === 'siswa') {
      payload = {
        nis: formData.value.nis,
        name: formData.value.nama,
        kelas_id: formData.value.kelas_id,
        jk: formData.value.jk || 'L',
        username: formData.value.username,
        ...(formData.value.password ? { password: formData.value.password } : {})
      }
    } else if (activeTab.value === 'guru') {
      payload = {
        nip: formData.value.nip || null,
        name: formData.value.nama,
        username: formData.value.username,
        mapel_ids: formData.value.mapel_ids || [],
        ...(formData.value.role ? { role: formData.value.role } : {}),
        ...(formData.value.password ? { password: formData.value.password } : {})
      }
    }

    if (isEditing.value && editId.value) {
      await apiClient.put(`/${endpoint}/${editId.value}`, payload)
      feedbackMessage.value = 'Data berhasil diperbarui.'
    } else {
      await apiClient.post(`/${endpoint}`, payload)
      feedbackMessage.value = 'Data berhasil ditambahkan.'
    }
    showFormModal.value = false
    await fetchData()
  } catch (err: any) {
    errorMessage.value = err.message || 'Gagal menyimpan data.'
  } finally {
    loading.value = false
    saveLoading.value = false
  }
}

function promptDeleteEntity(id: number, label?: string) {
  entityIdToDelete.value = id
  const tabName = {
    siswa: 'siswa',
    guru: 'guru',
    kelas: 'kelas',
    mapel: 'mata pelajaran',
    ruang: 'ruang ujian',
    tahun_ajaran: 'tahun ajaran',
    guru_mapel: 'pengampu mapel'
  }[activeTab.value] || 'data'

  deleteTitle.value = `Hapus Data ${tabName.charAt(0).toUpperCase() + tabName.slice(1)}`
  deleteMessage.value = label
    ? `Apakah Anda yakin ingin menghapus ${tabName} "${label}"? Tindakan ini bersifat permanen dan tidak dapat dibatalkan.`
    : `Apakah Anda yakin ingin menghapus data ${tabName} ini? Tindakan ini bersifat permanen dan tidak dapat dibatalkan.`
  showDeleteModal.value = true
}

async function confirmDeleteEntity() {
  if (!entityIdToDelete.value) return
  deleteLoading.value = true
  feedbackMessage.value = ''
  errorMessage.value = ''
  try {
    const endpoint = activeTab.value.replace('_', '-')
    await apiClient.delete(`/${endpoint}/${entityIdToDelete.value}`)
    feedbackMessage.value = 'Data berhasil dihapus.'
    showDeleteModal.value = false
    entityIdToDelete.value = null
    await fetchData()
  } catch (err: any) {
    errorMessage.value = err.message || 'Gagal menghapus data.'
  } finally {
    deleteLoading.value = false
  }
}

// Download Excel Template
async function downloadTemplate(jenis: string) {
  downloadTemplateLoading.value = true
  errorMessage.value = ''
  try {
    await downloadFileFromApi(`/templates/excel/${jenis}`, `template_${jenis}.xlsx`)
  } catch (err: any) {
    errorMessage.value = err.message || 'Gagal mengunduh template Excel.'
  } finally {
    downloadTemplateLoading.value = false
  }
}

// Excel Import Wizard
function openImport(jenis: string) {
  importJenis.value = jenis
  importFile.value = null
  importStep.value = 1
  importPreviewData.value = null
  importErrors.value = []
  importRows.value = []
  showImportModal.value = true
}

function onFileSelected(e: Event) {
  const target = e.target as HTMLInputElement
  if (target.files && target.files.length > 0) {
    importFile.value = target.files[0]
  }
}

async function previewExcel() {
  if (!importFile.value) return
  importLoading.value = true
  errorMessage.value = ''
  try {
    const fd = new FormData()
    fd.append('file', importFile.value)

    const res = await apiClient.post(`/import/excel/${importJenis.value}/preview`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' }
    })
    importPreviewData.value = res.data.data
    importErrors.value = res.data.data.errors || []
    importRows.value = res.data.data.preview || []
    importStep.value = 2
  } catch (err: any) {
    errorMessage.value = err.message || 'Gagal memproses pratinjau Excel.'
  } finally {
    importLoading.value = false
  }
}

const IMPORT_POLL_MS = 1500
const IMPORT_MAX_POLL = 400

async function commitExcel() {
  if (importErrors.value.length > 0 || importRows.value.length === 0) return
  importLoading.value = true
  errorMessage.value = ''
  try {
    // Tahap 2: BE memproses di antrean (transaksi tunggal), FE memantau statusnya.
    const res = await apiClient.post(`/import/excel/${importJenis.value}/commit`, { items: importRows.value })
    const importId: string = res.data.data.import_id

    for (let i = 0; i < IMPORT_MAX_POLL; i++) {
      await new Promise((resolve) => setTimeout(resolve, IMPORT_POLL_MS))
      const statusRes = await apiClient.get(`/import/excel/status/${importId}`)
      const status = statusRes.data.data
      if (status.status === 'selesai') {
        feedbackMessage.value = status.message || `Impor data ${importJenis.value} berhasil disimpan.`
        showImportModal.value = false
        await fetchData()
        return
      }
      if (status.status === 'gagal') {
        throw new Error(status.message || 'Impor gagal diproses.')
      }
    }
    throw new Error('Impor belum selesai diproses. Periksa daftar data beberapa saat lagi.')
  } catch (err) {
    errorMessage.value = err instanceof Error ? err.message : 'Gagal menyimpan impor data.'
  } finally {
    importLoading.value = false
  }
}

onMounted(() => {
  fetchData()
})

watch(
  () => route.query.tab,
  (tab) => {
    const baru = tabValid(TAB_MASTER, tab) as TabType
    if (baru !== activeTab.value) void handleTabChange(baru)
  }
)

const currentListCount = computed(() => {
  switch (activeTab.value) {
    case 'siswa': return filteredSiswa.value.length
    case 'guru': return filteredGuru.value.length
    case 'kelas': return filteredKelas.value.length
    case 'mapel': return filteredMapel.value.length
    case 'ruang': return filteredRuang.value.length
    case 'tahun_ajaran': return filteredTahunAjaran.value.length
    case 'guru_mapel': return filteredGuruMapel.value.length
    default: return 0
  }
})
</script>

<template>
  <StafLayout>
    <div class="space-y-6">
      <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
        <div>
          <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Master Data</h1>
          <p class="text-sm text-slate-500">Kelola data siswa, guru, kelas, mata pelajaran, dan ruang ujian sekolah.</p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <button
            v-if="['siswa', 'guru', 'kelas', 'guru_mapel'].includes(activeTab)"
            @click="downloadTemplate(activeTab)"
            :disabled="downloadTemplateLoading"
            class="btn-secondary !min-h-0 !py-2 text-xs flex items-center gap-1.5 cursor-pointer disabled:opacity-60"
          >
            <!-- Download SVG with Spinner -->
            <span v-if="downloadTemplateLoading" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-slate-600 border-t-transparent"></span>
            <svg v-else xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
            </svg>
            <span class="hidden sm:inline">{{ downloadTemplateLoading ? 'Mengunduh...' : 'Unduh Template' }}</span>
          </button>
          <span v-if="!bolehUbahTab" class="inline-flex items-center gap-1.5 rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-600 border border-slate-200">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-3.5 w-3.5 text-slate-500">
              <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
              <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
            </svg>
            <span>Mode Lihat Saja</span>
          </span>
          <button
            v-if="['siswa', 'guru', 'kelas', 'guru_mapel'].includes(activeTab) && isOperatorOrSuperadmin"
            @click="openImport(activeTab)"
            aria-label="Impor data" title="Impor data dari Excel"
            class="btn-secondary !min-h-0 !py-2 text-xs flex items-center gap-1.5"
          >
            <!-- Upload SVG -->
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
            </svg>
            <span class="hidden sm:inline">Impor Data</span>
          </button>
          <button v-if="bolehUbahTab" @click="openCreateModal" class="btn-primary !min-h-0 !py-2 text-xs flex items-center gap-1.5">
            <!-- Plus SVG -->
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
            </svg>
            <span class="sm:hidden">Tambah</span><span class="hidden sm:inline">Tambah Data</span>
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
      <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-xs">
        <h2 class="mb-3 text-base font-bold text-slate-900">{{ judulTab }}</h2>
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
          <div class="flex min-w-0 flex-1 flex-col gap-2 sm:flex-row sm:items-center">
          <div class="relative w-full sm:max-w-xs">
            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
              </svg>
            </div>
            <input
              v-model="searchQuery"
              type="text"
              placeholder="Cari data..."
              class="w-full rounded-xl border border-slate-200 bg-slate-50 py-2 pl-9 pr-3 text-xs text-slate-800 placeholder-slate-400 focus:border-brand-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-brand-100 transition-all"
            />
          </div>
          <select
            v-if="activeTab === 'siswa'"
            v-model="filterKelasId"
            aria-label="Filter kelas"
            class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-800 sm:w-44"
          >
            <option value="">Semua kelas</option>
            <option v-for="k in kelasList" :key="k.id" :value="k.id">{{ k.nama }}</option>
          </select>
          </div>
          <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600 sm:ml-auto">
            {{ currentListCount }} Data
          </span>
        </div>

        <!-- 0. Pengampu Mapel (guru - mapel - kelas) -->
        <div v-if="activeTab === 'guru_mapel'" class="overflow-x-auto">
          <table class="w-full text-left text-sm text-slate-600">
            <thead class="bg-slate-50 text-xs font-semibold text-slate-700">
              <tr>
                <th class="p-3">Guru</th>
                <th class="p-3">Mata Pelajaran</th>
                <th class="p-3">Kelas</th>
                <th class="p-3">Tahun Ajaran</th>
                <th v-if="isOperatorOrSuperadmin" class="p-3 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="item in halamanGuruMapel.item" :key="item.id" class="hover:bg-slate-50">
                <td class="p-3 font-medium text-slate-900">{{ item.guru?.user?.name || '-' }}</td>
                <td class="p-3">{{ item.mapel?.nama || '-' }}</td>
                <td class="p-3">{{ item.kelas?.nama || '-' }}</td>
                <td class="p-3">{{ item.tahun_ajaran?.nama || item.tahunAjaran?.nama || '-' }}</td>
                <td v-if="isOperatorOrSuperadmin" class="p-3 text-right">
                  <div class="flex items-center justify-end gap-1.5">
                    <button
                      type="button"
                      @click="promptDeleteEntity(item.id, `${item.guru?.user?.name} - ${item.mapel?.nama} (${item.kelas?.nama})`)"
                      class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 hover:border-red-300 hover:bg-red-50 hover:text-red-600 focus:outline-none focus:ring-2 focus:ring-red-200 transition-all shadow-2xs active:scale-95 cursor-pointer"
                      title="Hapus Data"
                      aria-label="Hapus Data"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                      </svg>
                    </button>
                  </div>
                </td>
              </tr>
              <TabelMemuat v-if="loading && filteredGuruMapel.length === 0" :kolom="5" />
              <tr v-if="!loading && filteredGuruMapel.length === 0">
                <td class="p-6 text-center text-slate-400">Belum ada data pengampu mapel.</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- 1. Siswa Table -->
        <div v-if="activeTab === 'siswa'" class="overflow-x-auto">
          <table class="w-full text-left text-sm text-slate-600">
            <thead class="bg-slate-50 text-xs font-semibold text-slate-700">
              <tr>
                <th class="p-3">NIS</th>
                <th class="p-3">Nama Siswa</th>
                <th class="p-3">Kelas</th>
                <th class="p-3">L/P</th>
                <th class="p-3">Username</th>
                <th v-if="isOperatorOrSuperadmin" class="p-3 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="item in halamanSiswa.item" :key="item.id" class="hover:bg-slate-50">
                <td class="p-3 font-mono font-medium text-slate-900">{{ item.nis }}</td>
                <td class="p-3 font-medium text-slate-900">{{ item.nama || item.user?.name }}</td>
                <td class="p-3">{{ item.kelas?.nama || '-' }}</td>
                <td class="p-3">
                  <span
                    class="inline-flex h-6 w-6 items-center justify-center rounded-md text-xs font-bold"
                    :class="(item.jk || item.jenis_kelamin) === 'P' ? 'bg-pink-100 text-pink-700' : 'bg-blue-100 text-blue-700'"
                  >
                    {{ item.jk || item.jenis_kelamin || 'L' }}
                  </span>
                </td>
                <td class="p-3 font-mono text-xs text-slate-700">{{ item.user?.username || '-' }}</td>
                <td v-if="isOperatorOrSuperadmin" class="p-3 text-right">
                  <div class="flex items-center justify-end gap-1.5">
                    <button
                      type="button"
                      @click="openEditModal(item)"
                      class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 hover:border-brand-500 hover:bg-brand-50 hover:text-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-200 transition-all shadow-2xs active:scale-95 cursor-pointer"
                      title="Ubah Data"
                      aria-label="Ubah Data"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                      </svg>
                    </button>
                    <button
                      type="button"
                      @click="promptDeleteEntity(item.id, item.nama || item.user?.name)"
                      class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 hover:border-red-300 hover:bg-red-50 hover:text-red-600 focus:outline-none focus:ring-2 focus:ring-red-200 transition-all shadow-2xs active:scale-95 cursor-pointer"
                      title="Hapus Data"
                      aria-label="Hapus Data"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                      </svg>
                    </button>
                  </div>
                </td>
              </tr>
              <TabelMemuat v-if="loading && filteredSiswa.length === 0" :kolom="6" />
              <tr v-if="!loading && filteredSiswa.length === 0">
                <td class="p-6 text-center text-slate-400">Belum ada data siswa.</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- 2. Guru Table -->
        <div v-if="activeTab === 'guru'" class="overflow-x-auto">
          <table class="w-full text-left text-sm text-slate-600">
            <thead class="bg-slate-50 text-xs font-semibold text-slate-700">
              <tr>
                <th class="p-3">NIP / NIY</th>
                <th class="p-3">Nama Guru</th>
                <th class="p-3">Username</th>
                <th class="p-3">Mata Pelajaran</th>
                <th v-if="isSuperadmin" class="p-3">Role</th>
                <th v-if="isOperatorOrSuperadmin" class="p-3 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="item in halamanGuru.item" :key="item.id" class="hover:bg-slate-50">
                <td class="p-3 font-mono font-medium text-slate-900">{{ item.nip || '-' }}</td>
                <td class="p-3 font-medium text-slate-900">{{ item.nama || item.user?.name }}</td>
                <td class="p-3 font-mono text-xs text-slate-700">{{ item.user?.username || '-' }}</td>
                <td class="p-3">
                  <div class="flex flex-wrap gap-1">
                    <span
                      v-for="m in (item.mapel || [])"
                      :key="m.id"
                      class="inline-block rounded-md bg-blue-50 border border-blue-200 px-2 py-0.5 text-[11px] font-semibold text-brand-700"
                    >
                      {{ m.nama }}
                    </span>
                    <span v-if="!item.mapel || item.mapel.length === 0" class="text-xs text-slate-400 italic">
                      Belum ada mapel
                    </span>
                  </div>
                </td>
                <td v-if="isSuperadmin" class="p-3">
                  <span
                    class="inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-bold uppercase"
                    :class="item.user?.role === 'operator' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700'"
                  >
                    {{ item.user?.role || 'guru' }}
                  </span>
                </td>
                <td v-if="isOperatorOrSuperadmin" class="p-3 text-right">
                  <div class="flex items-center justify-end gap-1.5">
                    <button
                      type="button"
                      @click="openEditModal(item)"
                      class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 hover:border-brand-500 hover:bg-brand-50 hover:text-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-200 transition-all shadow-2xs active:scale-95 cursor-pointer"
                      title="Ubah Data"
                      aria-label="Ubah Data"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                      </svg>
                    </button>
                    <button
                      type="button"
                      @click="promptDeleteEntity(item.id, item.nama || item.user?.name)"
                      class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 hover:border-red-300 hover:bg-red-50 hover:text-red-600 focus:outline-none focus:ring-2 focus:ring-red-200 transition-all shadow-2xs active:scale-95 cursor-pointer"
                      title="Hapus Data"
                      aria-label="Hapus Data"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                      </svg>
                    </button>
                  </div>
                </td>
              </tr>
              <TabelMemuat v-if="loading && filteredGuru.length === 0" :kolom="6" />
              <tr v-if="!loading && filteredGuru.length === 0">
                <td class="p-6 text-center text-slate-400">Belum ada data guru.</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- 3. Kelas Table -->
        <div v-if="activeTab === 'kelas'" class="overflow-x-auto">
          <table class="w-full text-left text-sm text-slate-600">
            <thead class="bg-slate-50 text-xs font-semibold text-slate-700">
              <tr>
                <th class="p-3">Nama Kelas</th>
                <th class="p-3">Tingkat</th>
                <th class="p-3">Tahun Ajaran</th>
                <th v-if="isOperatorOrSuperadmin" class="p-3 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="item in filteredKelas" :key="item.id" class="hover:bg-slate-50">
                <td class="p-3 font-medium text-slate-900">{{ item.nama }}</td>
                <td class="p-3">{{ item.tingkat }}</td>
                <td class="p-3">{{ item.tahun_ajaran?.nama || '-' }}</td>
                <td v-if="isOperatorOrSuperadmin" class="p-3 text-right">
                  <div class="flex items-center justify-end gap-1.5">
                    <button
                      type="button"
                      @click="openEditModal(item)"
                      class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 hover:border-brand-500 hover:bg-brand-50 hover:text-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-200 transition-all shadow-2xs active:scale-95 cursor-pointer"
                      title="Ubah Data"
                      aria-label="Ubah Data"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                      </svg>
                    </button>
                    <button
                      type="button"
                      @click="promptDeleteEntity(item.id, item.nama || item.user?.name)"
                      class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 hover:border-red-300 hover:bg-red-50 hover:text-red-600 focus:outline-none focus:ring-2 focus:ring-red-200 transition-all shadow-2xs active:scale-95 cursor-pointer"
                      title="Hapus Data"
                      aria-label="Hapus Data"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                      </svg>
                    </button>
                  </div>
                </td>
              </tr>
              <TabelMemuat v-if="loading && filteredKelas.length === 0" :kolom="4" />
              <tr v-if="!loading && filteredKelas.length === 0">
                <td class="p-6 text-center text-slate-400">Belum ada data kelas.</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- 4. Mapel Table -->
        <div v-if="activeTab === 'mapel'" class="overflow-x-auto">
          <table class="w-full text-left text-sm text-slate-600">
            <thead class="bg-slate-50 text-xs font-semibold text-slate-700">
              <tr>
                <th class="p-3">Kode</th>
                <th class="p-3">Nama Mata Pelajaran</th>
                <th v-if="isOperatorOrSuperadmin" class="p-3 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="item in filteredMapel" :key="item.id" class="hover:bg-slate-50">
                <td class="p-3 font-mono font-medium text-slate-900">{{ item.kode }}</td>
                <td class="p-3 font-medium text-slate-900">{{ item.nama }}</td>
                <td v-if="isOperatorOrSuperadmin" class="p-3 text-right">
                  <div class="flex items-center justify-end gap-1.5">
                    <button
                      type="button"
                      @click="openEditModal(item)"
                      class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 hover:border-brand-500 hover:bg-brand-50 hover:text-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-200 transition-all shadow-2xs active:scale-95 cursor-pointer"
                      title="Ubah Data"
                      aria-label="Ubah Data"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                      </svg>
                    </button>
                    <button
                      type="button"
                      @click="promptDeleteEntity(item.id, item.nama || item.user?.name)"
                      class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 hover:border-red-300 hover:bg-red-50 hover:text-red-600 focus:outline-none focus:ring-2 focus:ring-red-200 transition-all shadow-2xs active:scale-95 cursor-pointer"
                      title="Hapus Data"
                      aria-label="Hapus Data"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                      </svg>
                    </button>
                  </div>
                </td>
              </tr>
              <TabelMemuat v-if="loading && filteredMapel.length === 0" :kolom="3" />
              <tr v-if="!loading && filteredMapel.length === 0">
                <td class="p-6 text-center text-slate-400">Belum ada data mata pelajaran.</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- 5. Ruang Table -->
        <div v-if="activeTab === 'ruang'" class="overflow-x-auto">
          <table class="w-full text-left text-sm text-slate-600">
            <thead class="bg-slate-50 text-xs font-semibold text-slate-700">
              <tr>
                <th class="p-3">Kode Ruang</th>
                <th class="p-3">Nama Ruang</th>
                <th class="p-3">Kapasitas</th>
                <th v-if="isOperatorOrSuperadmin" class="p-3 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="item in filteredRuang" :key="item.id" class="hover:bg-slate-50">
                <td class="p-3 font-mono font-medium text-slate-900">{{ item.kode }}</td>
                <td class="p-3 font-medium text-slate-900">{{ item.nama }}</td>
                <td class="p-3">{{ item.kapasitas }} Peserta</td>
                <td v-if="isOperatorOrSuperadmin" class="p-3 text-right">
                  <div class="flex items-center justify-end gap-1.5">
                    <button
                      type="button"
                      @click="openEditModal(item)"
                      class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 hover:border-brand-500 hover:bg-brand-50 hover:text-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-200 transition-all shadow-2xs active:scale-95 cursor-pointer"
                      title="Ubah Data"
                      aria-label="Ubah Data"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                      </svg>
                    </button>
                    <button
                      type="button"
                      @click="promptDeleteEntity(item.id, item.nama || item.user?.name)"
                      class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 hover:border-red-300 hover:bg-red-50 hover:text-red-600 focus:outline-none focus:ring-2 focus:ring-red-200 transition-all shadow-2xs active:scale-95 cursor-pointer"
                      title="Hapus Data"
                      aria-label="Hapus Data"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                      </svg>
                    </button>
                  </div>
                </td>
              </tr>
              <TabelMemuat v-if="loading && filteredRuang.length === 0" :kolom="4" />
              <tr v-if="!loading && filteredRuang.length === 0">
                <td class="p-6 text-center text-slate-400">Belum ada data ruang.</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- 6. Tahun Ajaran Table -->
        <div v-if="activeTab === 'tahun_ajaran'" class="overflow-x-auto">
          <table class="w-full text-left text-sm text-slate-600">
            <thead class="bg-slate-50 text-xs font-semibold text-slate-700">
              <tr>
                <th class="p-3">Tahun Ajaran</th>
                <th class="p-3">Semester</th>
                <th class="p-3">Status</th>
                <th v-if="isSuperadmin" class="p-3 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="item in filteredTahunAjaran" :key="item.id" class="hover:bg-slate-50">
                <td class="p-3 font-medium text-slate-900">{{ item.nama }}</td>
                <td class="p-3">Semester {{ item.semester }}</td>
                <td class="p-3">
                  <span v-if="item.aktif" class="rounded bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-700">Aktif</span>
                  <span v-else class="text-xs text-slate-400">Non-aktif</span>
                </td>
                <td v-if="isSuperadmin" class="p-3 text-right">
                  <div class="flex items-center justify-end gap-1.5">
                    <button
                      type="button"
                      @click="openEditModal(item)"
                      class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 hover:border-brand-500 hover:bg-brand-50 hover:text-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-200 transition-all shadow-2xs active:scale-95 cursor-pointer"
                      title="Ubah Data"
                      aria-label="Ubah Data"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                      </svg>
                    </button>
                    <button
                      type="button"
                      @click="promptDeleteEntity(item.id, item.nama || item.user?.name)"
                      class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 hover:border-red-300 hover:bg-red-50 hover:text-red-600 focus:outline-none focus:ring-2 focus:ring-red-200 transition-all shadow-2xs active:scale-95 cursor-pointer"
                      title="Hapus Data"
                      aria-label="Hapus Data"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                      </svg>
                    </button>
                  </div>
                </td>
              </tr>
              <TabelMemuat v-if="loading && filteredTahunAjaran.length === 0" :kolom="4" />
              <tr v-if="!loading && filteredTahunAjaran.length === 0">
                <td class="p-6 text-center text-slate-400">Belum ada data tahun ajaran.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

    <!-- Create / Edit Modal -->
    <div v-if="showFormModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div class="card w-full max-w-md bg-white">
        <h2 class="mb-4 text-lg font-bold text-slate-800">
          {{ isEditing ? 'Ubah Data' : 'Tambah Data' }} ({{ activeTab.toUpperCase() }})
        </h2>

        <form @submit.prevent="saveEntity" class="space-y-3">
          <!-- Siswa Fields -->
          <template v-if="activeTab === 'siswa'">
            <div>
              <label class="block text-xs font-medium text-slate-700">NIS *</label>
              <input v-model="formData.nis" required placeholder="Nomor Induk Siswa" class="input !min-h-0 !py-1.5 text-sm font-mono" />
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-700">Nama Lengkap Siswa *</label>
              <input v-model="formData.nama" required placeholder="Nama Siswa" class="input !min-h-0 !py-1.5 text-sm" />
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-xs font-medium text-slate-700">Kelas *</label>
                <select v-model="formData.kelas_id" required class="input !min-h-0 !py-1.5 text-sm">
                  <option v-for="k in kelasList" :key="k.id" :value="k.id">{{ k.nama }}</option>
                </select>
              </div>
              <div>
                <label class="block text-xs font-medium text-slate-700">Jenis Kelamin *</label>
                <select v-model="formData.jk" class="input !min-h-0 !py-1.5 text-sm">
                  <option value="L">Laki-laki (L)</option>
                  <option value="P">Perempuan (P)</option>
                </select>
              </div>
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-700">Username *</label>
              <input v-model="formData.username" :required="!isEditing" placeholder="Username login siswa" class="input !min-h-0 !py-1.5 text-sm font-mono" />
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-700">
                Kata Sandi {{ isEditing ? '(Kosongkan jika tidak ingin diubah)' : '*' }}
              </label>
              <input v-model="formData.password" type="password" :required="!isEditing" placeholder="Minimal 6 karakter" class="input !min-h-0 !py-1.5 text-sm" />
            </div>
          </template>

          <!-- Guru Fields -->
          <template v-if="activeTab === 'guru'">
            <div>
              <label class="block text-xs font-medium text-slate-700">NIP / NIY (Opsional)</label>
              <input v-model="formData.nip" placeholder="NIP/NIY (Boleh dikosongkan)" class="input !min-h-0 !py-1.5 text-sm font-mono" />
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-700">Nama Lengkap Guru *</label>
              <input v-model="formData.nama" required placeholder="Nama Lengkap Beserta Gelar" class="input !min-h-0 !py-1.5 text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-700">Username *</label>
              <input v-model="formData.username" :required="!isEditing" placeholder="Username login guru" class="input !min-h-0 !py-1.5 text-sm font-mono" />
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-700">
                Kata Sandi {{ isEditing ? '(Kosongkan jika tidak ingin diubah)' : '*' }}
              </label>
              <input v-model="formData.password" type="password" :required="!isEditing" placeholder="Minimal 6 karakter" class="input !min-h-0 !py-1.5 text-sm" />
            </div>

            <!-- Mata Pelajaran yang Diampu (Multi-Select) -->
            <div>
              <label class="block text-xs font-medium text-slate-700 mb-1">
                Mata Pelajaran yang Diampu (Pilih 1 atau lebih)
              </label>
              <div class="max-h-36 overflow-y-auto rounded-xl border border-slate-200 bg-slate-50 p-2.5 space-y-1">
                <label
                  v-for="m in mapelList"
                  :key="m.id"
                  class="flex items-center gap-2.5 rounded-lg p-1.5 hover:bg-white text-xs text-slate-700 cursor-pointer transition-colors"
                >
                  <input
                    type="checkbox"
                    :value="m.id"
                    v-model="formData.mapel_ids"
                    class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                  />
                  <span class="font-bold text-slate-900 font-mono">{{ m.kode }}</span>
                  <span>-</span>
                  <span>{{ m.nama }}</span>
                </label>
                <p v-if="mapelList.length === 0" class="text-xs text-slate-400 italic">
                  Belum ada data mata pelajaran.
                </p>
              </div>
            </div>

            <!-- Superadmin: Pengaturan Role Guru -> Operator -->
            <div v-if="isSuperadmin" class="rounded-xl border border-amber-200 bg-amber-50/70 p-3 space-y-1.5">
              <label class="block text-xs font-bold text-amber-900">Role Guru</label>
              <div class="flex items-center gap-4 pt-1">
                <label class="flex items-center gap-1.5 text-xs font-semibold text-slate-800 cursor-pointer">
                  <input type="radio" value="guru" v-model="formData.role" class="text-brand-600" />
                  <span>Guru Standar</span>
                </label>
                <label class="flex items-center gap-1.5 text-xs font-semibold text-amber-900 cursor-pointer">
                  <input type="radio" value="operator" v-model="formData.role" class="text-amber-600" />
                  <span>Jadikan Operator</span>
                </label>
              </div>
            </div>
          </template>

          <!-- Kelas Fields -->
          <template v-if="activeTab === 'kelas'">
            <div>
              <label class="block text-xs font-medium text-slate-700">Nama Kelas</label>
              <input v-model="formData.nama" placeholder="Contoh: X RPL 1" required class="input !min-h-0 !py-1.5 text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-700">Tingkat</label>
              <input v-model="formData.tingkat" placeholder="Contoh: 10 atau X" required class="input !min-h-0 !py-1.5 text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-700">Tahun Ajaran</label>
              <select v-model="formData.tahun_ajaran_id" required class="input !min-h-0 !py-1.5 text-sm">
                <option v-for="ta in tahunAjaranList" :key="ta.id" :value="ta.id">{{ ta.nama }} (Sem {{ ta.semester }})</option>
              </select>
            </div>
          </template>

          <!-- Mapel Fields -->
          <template v-if="activeTab === 'mapel'">
            <div>
              <label class="block text-xs font-medium text-slate-700">Kode Mapel</label>
              <input v-model="formData.kode" placeholder="Contoh: MTK-10" required class="input !min-h-0 !py-1.5 text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-700">Nama Mata Pelajaran</label>
              <input v-model="formData.nama" placeholder="Contoh: Matematika Wajib" required class="input !min-h-0 !py-1.5 text-sm" />
            </div>
          </template>

          <!-- Ruang Fields -->
          <template v-if="activeTab === 'ruang'">
            <div>
              <label class="block text-xs font-medium text-slate-700">Kode Ruang</label>
              <input v-model="formData.kode" placeholder="Contoh: R-01" required class="input !min-h-0 !py-1.5 text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-700">Nama Ruang</label>
              <input v-model="formData.nama" placeholder="Contoh: Lab Komputer 1" required class="input !min-h-0 !py-1.5 text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-700">Kapasitas Peserta</label>
              <input v-model="formData.kapasitas" type="number" min="1" required class="input !min-h-0 !py-1.5 text-sm" />
            </div>
          </template>

          <!-- Pengampu Mapel Fields -->
          <template v-if="activeTab === 'guru_mapel'">
            <div>
              <label class="block text-xs font-medium text-slate-700">Guru *</label>
              <select v-model="formData.guru_id" required class="input !min-h-0 !py-1.5 text-sm">
                <option v-for="g in guruList" :key="g.id" :value="g.id">{{ g.nama || g.user?.name }}</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-700">Mata Pelajaran *</label>
              <select v-model="formData.mapel_id" required class="input !min-h-0 !py-1.5 text-sm">
                <option v-for="m in mapelList" :key="m.id" :value="m.id">{{ m.kode }} - {{ m.nama }}</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-700">Kelas *</label>
              <select v-model="formData.kelas_id" required class="input !min-h-0 !py-1.5 text-sm">
                <option v-for="k in kelasList" :key="k.id" :value="k.id">{{ k.nama }}</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-700">Tahun Ajaran *</label>
              <select v-model="formData.tahun_ajaran_id" required class="input !min-h-0 !py-1.5 text-sm">
                <option v-for="ta in tahunAjaranList" :key="ta.id" :value="ta.id">{{ ta.nama }} (Sem {{ ta.semester }})</option>
              </select>
            </div>
          </template>

          <!-- Tahun Ajaran Fields -->
          <template v-if="activeTab === 'tahun_ajaran'">
            <div>
              <label class="block text-xs font-medium text-slate-700">Tahun Ajaran</label>
              <input v-model="formData.nama" placeholder="Contoh: 2026/2027" required class="input !min-h-0 !py-1.5 text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-700">Semester</label>
              <select v-model="formData.semester" class="input !min-h-0 !py-1.5 text-sm">
                <option :value="1">Semester 1 (Ganjil)</option>
                <option :value="2">Semester 2 (Genap)</option>
              </select>
            </div>
            <div class="flex items-center gap-2 pt-2">
              <input type="checkbox" id="ta_aktif" v-model="formData.aktif" />
              <label for="ta_aktif" class="text-xs font-medium text-slate-700">Jadikan Tahun Ajaran Aktif</label>
            </div>
          </template>

          <div class="flex justify-end gap-2 pt-4">
            <button type="button" @click="showFormModal = false" :disabled="saveLoading" class="btn-secondary !min-h-0 !py-2 text-xs cursor-pointer">Batal</button>
            <button
              type="submit"
              class="btn-primary !min-h-0 !py-2 text-xs flex items-center justify-center gap-1.5 cursor-pointer disabled:opacity-50"
              :disabled="saveLoading"
            >
              <span v-if="saveLoading" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white border-t-transparent"></span>
              <span>{{ saveLoading ? 'Menyimpan...' : (isEditing ? 'Simpan Perubahan' : 'Simpan Data') }}</span>
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Excel 2-Step Import Wizard Modal -->
    <div v-if="showImportModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div class="card w-full max-w-2xl bg-white max-h-[90vh] overflow-y-auto">
        <div class="mb-4 flex items-center justify-between border-b pb-3">
          <h2 class="text-lg font-bold text-slate-800">
            Impor Data Excel: {{ importJenis.toUpperCase() }} (Langkah {{ importStep }} dari 2)
          </h2>
          <button @click="showImportModal = false" class="rounded-lg p-1 text-slate-400 hover:text-slate-600 hover:bg-slate-100">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <!-- Step 1: Upload File -->
        <div v-if="importStep === 1" class="space-y-4">
          <p class="text-sm text-slate-600">
            Unggah berkas Microsoft Excel (.xlsx / .xls) sesuai template resmi. Sistem akan memvalidasi kelengkapan data per baris.
          </p>

          <div class="rounded-2xl border-2 border-dashed border-slate-300 p-8 text-center bg-slate-50/50 hover:bg-slate-50 transition-colors">
            <input type="file" accept=".xlsx,.xls" @change="onFileSelected" class="hidden" id="excelFile" />
            <label for="excelFile" class="cursor-pointer">
              <div class="flex h-12 w-12 mx-auto items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600 mb-3 shadow-xs">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-6 w-6">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m6.75 12-3-3m0 0-3 3m3-3v6m-1.5-15H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                </svg>
              </div>
              <p class="text-sm font-semibold text-brand-600">Klik untuk memilih berkas Excel</p>
              <p class="text-xs text-slate-400 mt-1">Maksimal 10MB (.xlsx)</p>
            </label>
            <p v-if="importFile" class="mt-3 text-xs font-semibold text-slate-700 bg-white py-1.5 px-3.5 rounded-lg border border-slate-200 inline-block shadow-xs">
              Terpilih: {{ importFile.name }}
            </p>
          </div>

          <div class="flex justify-end gap-2 pt-2">
            <button @click="showImportModal = false" class="btn-secondary !min-h-0 !py-2 text-xs">Batal</button>
            <button
              @click="previewExcel"
              :disabled="!importFile || importLoading"
              class="btn-primary !min-h-0 !py-2 text-xs"
            >
              {{ importLoading ? 'Memeriksa Berkas...' : 'Lanjut ke Pratinjau →' }}
            </button>
          </div>
        </div>

        <!-- Step 2: Preview & Validation Table -->
        <div v-if="importStep === 2" class="space-y-4">
          <div class="grid grid-cols-3 gap-3 text-center">
            <div class="rounded-xl bg-slate-100 p-2.5">
              <p class="text-xs text-slate-500">Total Baris</p>
              <p class="text-lg font-bold text-slate-800 font-mono">{{ importPreviewData?.total_rows || 0 }}</p>
            </div>
            <div class="rounded-xl bg-green-50 p-2.5">
              <p class="text-xs text-green-700">Baris Valid</p>
              <p class="text-lg font-bold text-status-ok font-mono">{{ importPreviewData?.valid_count || 0 }}</p>
            </div>
            <div class="rounded-xl bg-red-50 p-2.5">
              <p class="text-xs text-red-700">Baris Error</p>
              <p class="text-lg font-bold text-status-danger font-mono">{{ importErrors.length }}</p>
            </div>
          </div>

          <!-- Error Details Table -->
          <div v-if="importErrors.length > 0" class="rounded-xl border border-red-200 bg-red-50 p-3.5">
            <h4 class="text-xs font-bold text-red-800 mb-2">Ditemukan Kesalahan Validasi:</h4>
            <div class="max-h-48 overflow-y-auto text-xs text-red-700 space-y-1">
              <div v-for="(err, idx) in importErrors" :key="idx" class="flex gap-2">
                <span class="font-bold">Baris {{ err.row }}:</span>
                <span>{{ err.column ? `[${err.column}] ` : '' }}{{ err.errors ? err.errors.join(', ') : err.message }}</span>
              </div>
            </div>
            <p class="text-xs text-red-600 mt-2 italic font-semibold">
              * Harap perbaiki kesalahan pada berkas Excel Anda terlebih dahulu sebelum menyimpan data.
            </p>
          </div>

          <div v-else class="rounded-xl border border-green-200 bg-green-50 p-3.5 text-xs text-green-800 flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4 text-emerald-600">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
            </svg>
            <span>Seluruh data valid dan siap disimpan ke basis data secara aman.</span>
          </div>

          <div class="flex justify-between items-center pt-2">
            <button @click="importStep = 1" :disabled="importLoading" class="btn-secondary !min-h-0 !py-2 text-xs cursor-pointer">← Kembali</button>
            <button
              @click="commitExcel"
              :disabled="importErrors.length > 0 || importLoading"
              class="btn-primary !min-h-0 !py-2 text-xs flex items-center justify-center gap-1.5 cursor-pointer disabled:opacity-50"
            >
              <span v-if="importLoading" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white border-t-transparent"></span>
              <span>{{ importLoading ? 'Memproses di antrean...' : 'Konfirmasi & Simpan ke Basis Data' }}</span>
            </button>
          </div>
        </div>
      </div>
    </div>
    </div>

    <!-- Paginasi (siswa, guru, pengampu mapel) -->
    <nav v-if="infoHalaman && infoHalaman.total > 0" class="mt-3 flex flex-col gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-xs text-slate-600 sm:flex-row sm:items-center sm:justify-between" aria-label="Paginasi">
      <span>Menampilkan {{ infoHalaman.dari }}-{{ infoHalaman.sampai }} dari {{ infoHalaman.total }}</span>
      <div class="flex items-center gap-2">
        <button class="btn-secondary !min-h-0 !py-1.5 text-xs" :disabled="infoHalaman.halaman <= 1" @click="halaman = infoHalaman.halaman - 1">Sebelumnya</button>
        <span class="font-mono">{{ infoHalaman.halaman }} / {{ infoHalaman.totalHalaman }}</span>
        <button class="btn-secondary !min-h-0 !py-1.5 text-xs" :disabled="infoHalaman.halaman >= infoHalaman.totalHalaman" @click="halaman = infoHalaman.halaman + 1">Berikutnya</button>
      </div>
    </nav>

    <!-- Confirm Delete Modal with Custom Styling -->
    <ConfirmDeleteModal
      v-model="showDeleteModal"
      :loading="deleteLoading"
      :title="deleteTitle"
      :message="deleteMessage"
      confirm-text="Ya, Hapus"
      cancel-text="Batal"
      @confirm="confirmDeleteEntity"
    />
  </StafLayout>
</template>

