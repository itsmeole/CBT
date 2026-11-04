<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import StafLayout from '@/components/StafLayout.vue'
import { apiClient } from '@/api/client'

type TabType = 'siswa' | 'guru' | 'kelas' | 'mapel' | 'ruang' | 'tahun_ajaran'

const activeTab = ref<TabType>('siswa')
const loading = ref(false)
const searchQuery = ref('')
const feedbackMessage = ref('')
const errorMessage = ref('')

// Data lists
const siswaList = ref<any[]>([])
const guruList = ref<any[]>([])
const kelasList = ref<any[]>([])
const mapelList = ref<any[]>([])
const ruangList = ref<any[]>([])
const tahunAjaranList = ref<any[]>([])

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
const tempFileKey = ref<string>('')

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
  return siswaList.value.filter(s => s.nama?.toLowerCase().includes(q) || s.nis?.toLowerCase().includes(q))
})

const filteredGuru = computed(() => {
  const q = searchQuery.value.toLowerCase()
  return guruList.value.filter(g => g.nama?.toLowerCase().includes(q) || g.nip?.toLowerCase().includes(q))
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
  formData.value = {}
  if (activeTab.value === 'siswa' && kelasList.value.length > 0) {
    formData.value.kelas_id = kelasList.value[0].id
    formData.value.jenis_kelamin = 'L'
  }
  if (activeTab.value === 'kelas' && tahunAjaranList.value.length > 0) {
    formData.value.tahun_ajaran_id = tahunAjaranList.value[0].id
  }
  if (activeTab.value === 'tahun_ajaran') {
    formData.value.semester = 1
    formData.value.aktif = true
  }
  showFormModal.value = true
}

function openEditModal(item: any) {
  isEditing.value = true
  editId.value = item.id
  formData.value = { ...item }
  showFormModal.value = true
}

async function saveEntity() {
  loading.value = true
  feedbackMessage.value = ''
  errorMessage.value = ''
  try {
    const endpoint = activeTab.value.replace('_', '-')
    if (isEditing.value && editId.value) {
      await apiClient.put(`/${endpoint}/${editId.value}`, formData.value)
      feedbackMessage.value = 'Data berhasil diperbarui.'
    } else {
      await apiClient.post(`/${endpoint}`, formData.value)
      feedbackMessage.value = 'Data berhasil ditambahkan.'
    }
    showFormModal.value = false
    await fetchData()
  } catch (err: any) {
    errorMessage.value = err.message || 'Gagal menyimpan data.'
  } finally {
    loading.value = false
  }
}

async function deleteEntity(id: number) {
  if (!confirm('Apakah Anda yakin ingin menghapus data ini?')) return
  loading.value = true
  feedbackMessage.value = ''
  errorMessage.value = ''
  try {
    const endpoint = activeTab.value.replace('_', '-')
    await apiClient.delete(`/${endpoint}/${id}`)
    feedbackMessage.value = 'Data berhasil dihapus.'
    await fetchData()
  } catch (err: any) {
    errorMessage.value = err.message || 'Gagal menghapus data.'
  } finally {
    loading.value = false
  }
}

// Download Excel Template
function downloadTemplate(jenis: string) {
  const url = `${apiClient.defaults.baseURL}/templates/excel/${jenis}`
  window.open(url, '_blank')
}

// Excel Import Wizard
function openImport(jenis: string) {
  importJenis.value = jenis
  importFile.value = null
  importStep.value = 1
  importPreviewData.value = null
  importErrors.value = []
  tempFileKey.value = ''
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
    tempFileKey.value = res.data.data.temp_key || ''
    importStep.value = 2
  } catch (err: any) {
    errorMessage.value = err.message || 'Gagal memproses pratinjau Excel.'
  } finally {
    importLoading.value = false
  }
}

async function commitExcel() {
  importLoading.value = true
  errorMessage.value = ''
  try {
    await apiClient.post(`/import/excel/${importJenis.value}/commit`, {
      temp_key: tempFileKey.value
    })
    feedbackMessage.value = `Impor data ${importJenis.value} berhasil disimpan.`
    showImportModal.value = false
    await fetchData()
  } catch (err: any) {
    errorMessage.value = err.message || 'Gagal menyimpan impor data.'
  } finally {
    importLoading.value = false
  }
}

onMounted(() => {
  fetchData()
})

const currentListCount = computed(() => {
  switch (activeTab.value) {
    case 'siswa': return filteredSiswa.value.length
    case 'guru': return filteredGuru.value.length
    case 'kelas': return filteredKelas.value.length
    case 'mapel': return filteredMapel.value.length
    case 'ruang': return filteredRuang.value.length
    case 'tahun_ajaran': return filteredTahunAjaran.value.length
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
            v-if="['siswa', 'guru', 'kelas'].includes(activeTab)"
            @click="downloadTemplate(activeTab)"
            class="btn-secondary !min-h-0 !py-2 text-xs flex items-center gap-1.5"
          >
            <!-- Download SVG -->
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
            </svg>
            <span>Unduh Template</span>
          </button>
          <button
            v-if="['siswa', 'guru', 'kelas'].includes(activeTab)"
            @click="openImport(activeTab)"
            class="btn-secondary !min-h-0 !py-2 text-xs flex items-center gap-1.5"
          >
            <!-- Upload SVG -->
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
            </svg>
            <span>Impor Data</span>
          </button>
          <button @click="openCreateModal" class="btn-primary !min-h-0 !py-2 text-xs flex items-center gap-1.5">
            <!-- Plus SVG -->
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
            </svg>
            <span>Tambah Data</span>
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

      <!-- Tabs Navigation -->
      <div class="flex border-b border-slate-200 bg-white px-4 pt-2 rounded-t-xl overflow-x-auto gap-4">
        <button
          v-for="tab in [
            { id: 'siswa', label: 'Siswa' },
            { id: 'guru', label: 'Guru' },
            { id: 'kelas', label: 'Kelas' },
            { id: 'mapel', label: 'Mata Pelajaran' },
            { id: 'ruang', label: 'Ruang Ujian' },
            { id: 'tahun_ajaran', label: 'Tahun Ajaran' }
          ]"
          :key="tab.id"
          @click="handleTabChange(tab.id as TabType)"
          class="border-b-2 py-3 px-2 text-sm font-medium transition-colors"
          :class="activeTab === tab.id ? 'border-brand-600 font-bold text-brand-600' : 'border-transparent text-slate-500 hover:text-slate-800'"
        >
          {{ tab.label }}
        </button>
      </div>

      <!-- Content Area -->
      <div class="rounded-b-2xl border border-t-0 border-slate-200 bg-white p-5 shadow-xs">
        <div class="mb-4 flex items-center justify-between gap-4">
          <div class="relative w-full max-w-xs">
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
          <span v-if="loading" class="text-xs text-slate-400 flex items-center gap-1.5">
            <span class="inline-block h-2 w-2 animate-ping rounded-full bg-brand-600"></span>
            Memuat data...
          </span>
          <span v-else class="text-xs font-bold text-slate-400 font-mono">
            {{ currentListCount }} Data
          </span>
        </div>

        <!-- 1. Siswa Table -->
        <div v-if="activeTab === 'siswa'" class="overflow-x-auto">
          <table class="w-full text-left text-sm text-slate-600">
            <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-700">
              <tr>
                <th class="p-3">NIS</th>
                <th class="p-3">Nama</th>
                <th class="p-3">Kelas</th>
                <th class="p-3">L/P</th>
                <th class="p-3 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="item in filteredSiswa" :key="item.id" class="hover:bg-slate-50">
                <td class="p-3 font-mono font-medium text-slate-900">{{ item.nis }}</td>
                <td class="p-3 font-medium text-slate-900">{{ item.nama }}</td>
                <td class="p-3">{{ item.kelas?.nama || '-' }}</td>
                <td class="p-3">{{ item.jenis_kelamin }}</td>
                <td class="p-3 text-right">
                  <button @click="openEditModal(item)" class="text-brand-600 hover:underline mr-3 text-xs">Ubah</button>
                  <button @click="deleteEntity(item.id)" class="text-status-danger hover:underline text-xs">Hapus</button>
                </td>
              </tr>
              <tr v-if="filteredSiswa.length === 0">
                <td colspan="5" class="p-6 text-center text-slate-400">Belum ada data siswa.</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- 2. Guru Table -->
        <div v-if="activeTab === 'guru'" class="overflow-x-auto">
          <table class="w-full text-left text-sm text-slate-600">
            <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-700">
              <tr>
                <th class="p-3">NIP</th>
                <th class="p-3">Nama Guru</th>
                <th class="p-3">Email</th>
                <th class="p-3 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="item in filteredGuru" :key="item.id" class="hover:bg-slate-50">
                <td class="p-3 font-mono font-medium text-slate-900">{{ item.nip }}</td>
                <td class="p-3 font-medium text-slate-900">{{ item.nama }}</td>
                <td class="p-3">{{ item.email || '-' }}</td>
                <td class="p-3 text-right">
                  <button @click="openEditModal(item)" class="text-brand-600 hover:underline mr-3 text-xs">Ubah</button>
                  <button @click="deleteEntity(item.id)" class="text-status-danger hover:underline text-xs">Hapus</button>
                </td>
              </tr>
              <tr v-if="filteredGuru.length === 0">
                <td colspan="4" class="p-6 text-center text-slate-400">Belum ada data guru.</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- 3. Kelas Table -->
        <div v-if="activeTab === 'kelas'" class="overflow-x-auto">
          <table class="w-full text-left text-sm text-slate-600">
            <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-700">
              <tr>
                <th class="p-3">Nama Kelas</th>
                <th class="p-3">Tingkat</th>
                <th class="p-3">Tahun Ajaran</th>
                <th class="p-3 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="item in filteredKelas" :key="item.id" class="hover:bg-slate-50">
                <td class="p-3 font-medium text-slate-900">{{ item.nama }}</td>
                <td class="p-3">{{ item.tingkat }}</td>
                <td class="p-3">{{ item.tahun_ajaran?.nama || '-' }}</td>
                <td class="p-3 text-right">
                  <button @click="openEditModal(item)" class="text-brand-600 hover:underline mr-3 text-xs">Ubah</button>
                  <button @click="deleteEntity(item.id)" class="text-status-danger hover:underline text-xs">Hapus</button>
                </td>
              </tr>
              <tr v-if="filteredKelas.length === 0">
                <td colspan="4" class="p-6 text-center text-slate-400">Belum ada data kelas.</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- 4. Mapel Table -->
        <div v-if="activeTab === 'mapel'" class="overflow-x-auto">
          <table class="w-full text-left text-sm text-slate-600">
            <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-700">
              <tr>
                <th class="p-3">Kode</th>
                <th class="p-3">Nama Mata Pelajaran</th>
                <th class="p-3 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="item in filteredMapel" :key="item.id" class="hover:bg-slate-50">
                <td class="p-3 font-mono font-medium text-slate-900">{{ item.kode }}</td>
                <td class="p-3 font-medium text-slate-900">{{ item.nama }}</td>
                <td class="p-3 text-right">
                  <button @click="openEditModal(item)" class="text-brand-600 hover:underline mr-3 text-xs">Ubah</button>
                  <button @click="deleteEntity(item.id)" class="text-status-danger hover:underline text-xs">Hapus</button>
                </td>
              </tr>
              <tr v-if="filteredMapel.length === 0">
                <td colspan="3" class="p-6 text-center text-slate-400">Belum ada data mata pelajaran.</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- 5. Ruang Table -->
        <div v-if="activeTab === 'ruang'" class="overflow-x-auto">
          <table class="w-full text-left text-sm text-slate-600">
            <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-700">
              <tr>
                <th class="p-3">Kode Ruang</th>
                <th class="p-3">Nama Ruang</th>
                <th class="p-3">Kapasitas</th>
                <th class="p-3 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="item in filteredRuang" :key="item.id" class="hover:bg-slate-50">
                <td class="p-3 font-mono font-medium text-slate-900">{{ item.kode }}</td>
                <td class="p-3 font-medium text-slate-900">{{ item.nama }}</td>
                <td class="p-3">{{ item.kapasitas }} Peserta</td>
                <td class="p-3 text-right">
                  <button @click="openEditModal(item)" class="text-brand-600 hover:underline mr-3 text-xs">Ubah</button>
                  <button @click="deleteEntity(item.id)" class="text-status-danger hover:underline text-xs">Hapus</button>
                </td>
              </tr>
              <tr v-if="filteredRuang.length === 0">
                <td colspan="4" class="p-6 text-center text-slate-400">Belum ada data ruang.</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- 6. Tahun Ajaran Table -->
        <div v-if="activeTab === 'tahun_ajaran'" class="overflow-x-auto">
          <table class="w-full text-left text-sm text-slate-600">
            <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-700">
              <tr>
                <th class="p-3">Tahun Ajaran</th>
                <th class="p-3">Semester</th>
                <th class="p-3">Status</th>
                <th class="p-3 text-right">Aksi</th>
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
                <td class="p-3 text-right">
                  <button @click="openEditModal(item)" class="text-brand-600 hover:underline mr-3 text-xs">Ubah</button>
                  <button @click="deleteEntity(item.id)" class="text-status-danger hover:underline text-xs">Hapus</button>
                </td>
              </tr>
              <tr v-if="filteredTahunAjaran.length === 0">
                <td colspan="4" class="p-6 text-center text-slate-400">Belum ada data tahun ajaran.</td>
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
              <label class="block text-xs font-medium text-slate-700">NIS</label>
              <input v-model="formData.nis" required class="input !min-h-0 !py-1.5 text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-700">Nama Lengkap</label>
              <input v-model="formData.nama" required class="input !min-h-0 !py-1.5 text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-700">Kelas</label>
              <select v-model="formData.kelas_id" required class="input !min-h-0 !py-1.5 text-sm">
                <option v-for="k in kelasList" :key="k.id" :value="k.id">{{ k.nama }}</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-700">Jenis Kelamin</label>
              <select v-model="formData.jenis_kelamin" class="input !min-h-0 !py-1.5 text-sm">
                <option value="L">Laki-laki (L)</option>
                <option value="P">Perempuan (P)</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-700">Kata Sandi (Opsional)</label>
              <input v-model="formData.password" type="password" placeholder="Default: cbt12345" class="input !min-h-0 !py-1.5 text-sm" />
            </div>
          </template>

          <!-- Guru Fields -->
          <template v-if="activeTab === 'guru'">
            <div>
              <label class="block text-xs font-medium text-slate-700">NIP</label>
              <input v-model="formData.nip" required class="input !min-h-0 !py-1.5 text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-700">Nama Lengkap</label>
              <input v-model="formData.nama" required class="input !min-h-0 !py-1.5 text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-700">Email</label>
              <input v-model="formData.email" type="email" class="input !min-h-0 !py-1.5 text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-700">Kata Sandi (Opsional)</label>
              <input v-model="formData.password" type="password" placeholder="Default: guru12345" class="input !min-h-0 !py-1.5 text-sm" />
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
            <button type="button" @click="showFormModal = false" class="btn-secondary !min-h-0 !py-1.5 text-xs">Batal</button>
            <button type="submit" class="btn-primary !min-h-0 !py-1.5 text-xs" :disabled="loading">
              {{ loading ? 'Menyimpan...' : 'Simpan' }}
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
                <span>{{ err.errors ? err.errors.join(', ') : err.message }}</span>
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
            <button @click="importStep = 1" class="btn-secondary !min-h-0 !py-2 text-xs">← Kembali</button>
            <button
              @click="commitExcel"
              :disabled="importErrors.length > 0 || importLoading"
              class="btn-primary !min-h-0 !py-2 text-xs"
            >
              {{ importLoading ? 'Menyimpan...' : 'Konfirmasi & Simpan ke Basis Data' }}
            </button>
          </div>
        </div>
      </div>
    </div>
    </div>
  </StafLayout>
</template>

