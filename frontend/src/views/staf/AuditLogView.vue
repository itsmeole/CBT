<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import StafLayout from '@/components/StafLayout.vue'
import { apiClient } from '@/api/client'

interface BarisLog {
  id: number
  aksi: string
  target_type: string | null
  target_id: number | null
  data: Record<string, unknown> | null
  created_at: string
  aktor: { name: string; username: string; role: string } | null
}

/** Aksi yang dicatat sistem (append-only). */
const AKSI = [
  'auth.login',
  'auth.logout',
  'auth.reset_login',
  'ujian.mulai_sesi',
  'ujian.mulai_peserta',
  'ujian.lock',
  'ujian.unlock',
  'ujian.dikirim_otomatis',
  'sesi.kehadiran_diperbarui',
  'essay.dikoreksi',
  'berita_acara.disimpan',
  'laporan_pdf.diminta',
  'lock_threshold.updated',
  'import.excel.diajukan',
  'master_data.export_excel',
  'bank_soal.export_word',
  'paket_soal.export_word'
]

const baris = ref<BarisLog[]>([])
const cari = ref('')
const aksi = ref('')
const mulai = ref('')
const sampai = ref('')
const halaman = ref(1)
const totalHalaman = ref(1)
const total = ref(0)
const loading = ref(false)
const errorMessage = ref('')

async function muat() {
  loading.value = true
  errorMessage.value = ''
  try {
    const params: Record<string, string | number> = { page: halaman.value, per_page: 25 }
    if (cari.value.trim()) params.cari = cari.value.trim()
    if (aksi.value) params.aksi = aksi.value
    if (mulai.value) params.mulai = mulai.value
    // Tanggal akhir dibuat inklusif sampai akhir hari.
    if (sampai.value) params.sampai = `${sampai.value} 23:59:59`
    const res = await apiClient.get('/log-aktivitas', { params })
    baris.value = res.data.data ?? []
    totalHalaman.value = res.data.last_page ?? 1
    total.value = res.data.total ?? baris.value.length
  } catch (err) {
    baris.value = []
    errorMessage.value = err instanceof Error ? err.message : 'Gagal memuat audit log.'
  } finally {
    loading.value = false
  }
}

function formatWaktu(iso: string): string {
  return new Date(iso).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'medium' })
}

function ringkasData(data: BarisLog['data']): string {
  return data && Object.keys(data).length > 0 ? JSON.stringify(data) : '-'
}

let timerCari: ReturnType<typeof setTimeout> | null = null
watch([cari], () => {
  if (timerCari) clearTimeout(timerCari)
  timerCari = setTimeout(() => {
    halaman.value = 1
    void muat()
  }, 400)
})
watch([aksi, mulai, sampai], () => {
  halaman.value = 1
  void muat()
})
watch(halaman, muat)
onMounted(muat)
</script>

<template>
  <StafLayout>
    <div class="space-y-4">
      <div>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900">Audit Log</h1>
        <p class="text-sm text-slate-500">Catatan aktivitas kritikal sistem. Hanya dapat dibaca, tidak dapat diubah atau dihapus.</p>
      </div>

      <div v-if="errorMessage" class="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert">{{ errorMessage }}</div>

      <div class="card grid gap-3 bg-white p-4 sm:grid-cols-2 lg:grid-cols-4">
        <label class="text-xs font-semibold text-slate-600">
          Aktor (nama / username)
          <input v-model="cari" class="input mt-1" placeholder="Cari aktor..." />
        </label>
        <label class="text-xs font-semibold text-slate-600">
          Aksi
          <select v-model="aksi" class="input mt-1">
            <option value="">Semua aksi</option>
            <option v-for="a in AKSI" :key="a" :value="a">{{ a }}</option>
          </select>
        </label>
        <label class="text-xs font-semibold text-slate-600">
          Dari tanggal
          <input v-model="mulai" type="date" class="input mt-1" />
        </label>
        <label class="text-xs font-semibold text-slate-600">
          Sampai tanggal
          <input v-model="sampai" type="date" class="input mt-1" />
        </label>
      </div>

      <div class="card overflow-x-auto bg-white">
        <table class="w-full min-w-[760px] text-left text-sm">
          <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase text-slate-500">
            <tr>
              <th class="px-3 py-2">Waktu</th>
              <th class="px-3 py-2">Aktor</th>
              <th class="px-3 py-2">Aksi</th>
              <th class="px-3 py-2">Target</th>
              <th class="px-3 py-2">Rincian</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="b in baris" :key="b.id" class="border-b border-slate-100 align-top">
              <td class="whitespace-nowrap px-3 py-2 text-xs">{{ formatWaktu(b.created_at) }}</td>
              <td class="px-3 py-2">
                <template v-if="b.aktor">
                  <p class="font-medium text-slate-900">{{ b.aktor.name }}</p>
                  <p class="text-xs text-slate-500">{{ b.aktor.username }} - {{ b.aktor.role }}</p>
                </template>
                <span v-else class="text-slate-400">Sistem</span>
              </td>
              <td class="px-3 py-2 font-mono text-xs">{{ b.aksi }}</td>
              <td class="px-3 py-2 text-xs">{{ b.target_type ?? '-' }}<span v-if="b.target_id"> #{{ b.target_id }}</span></td>
              <td class="max-w-xs break-words px-3 py-2 font-mono text-xs text-slate-600">{{ ringkasData(b.data) }}</td>
            </tr>
            <tr v-if="!loading && baris.length === 0">
              <td colspan="5" class="px-3 py-8 text-center text-slate-500">Tidak ada catatan yang cocok.</td>
            </tr>
            <tr v-if="loading">
              <td colspan="5" class="px-3 py-8 text-center text-slate-500">Memuat...</td>
            </tr>
          </tbody>
        </table>
      </div>

      <nav class="flex items-center justify-between text-xs text-slate-600" aria-label="Paginasi">
        <span>{{ total }} catatan</span>
        <div class="flex items-center gap-2">
          <button class="btn-secondary !min-h-0 !py-1.5 text-xs" :disabled="halaman <= 1 || loading" @click="halaman--">Sebelumnya</button>
          <span class="font-mono">{{ halaman }} / {{ totalHalaman }}</span>
          <button class="btn-secondary !min-h-0 !py-1.5 text-xs" :disabled="halaman >= totalHalaman || loading" @click="halaman++">Berikutnya</button>
        </div>
      </nav>
    </div>
  </StafLayout>
</template>
