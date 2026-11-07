<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import StafLayout from '@/components/StafLayout.vue'
import { apiClient } from '@/api/client'
import { cetakLaporan } from '@/lib/laporan'

interface SesiOpsi {
  id: number
  label: string
}
interface BeritaAcara {
  isi: string
  jumlah_hadir: number
  jumlah_absen: number
}

const sesiOpsi = ref<SesiOpsi[]>([])
const sesiId = ref<number | null>(null)
const tersimpan = ref<BeritaAcara | null>(null)
const isi = ref('')
const loading = ref(false)
const menyimpan = ref(false)
const mencetak = ref(false)
const errorMessage = ref('')
const feedback = ref('')

const isiValid = computed(() => isi.value.trim().length >= 5)

async function muatSesi() {
  try {
    const res = await apiClient.get('/pengawasan/sesi')
    sesiOpsi.value = (res.data.data ?? []).map(
      (s: { id: number; jadwal: string; mapel: string; ruang: string; tanggal: string | null; sesi_ke: number }) => ({
        id: s.id,
        label: `${s.tanggal ?? ''} ${s.jadwal} - ${s.mapel ?? ''} (${s.ruang ?? 'Ruang'}, sesi ${s.sesi_ke})`.trim()
      })
    )
    if (sesiOpsi.value.length > 0) sesiId.value = sesiOpsi.value[0].id
  } catch (err) {
    errorMessage.value = err instanceof Error ? err.message : 'Gagal memuat daftar sesi.'
  }
}

async function muatBeritaAcara() {
  if (!sesiId.value) return
  loading.value = true
  errorMessage.value = ''
  feedback.value = ''
  try {
    const res = await apiClient.get(`/sesi-ruang/${sesiId.value}/berita-acara`)
    tersimpan.value = res.data.data ?? null
    isi.value = tersimpan.value?.isi ?? ''
  } catch (err) {
    tersimpan.value = null
    errorMessage.value = err instanceof Error ? err.message : 'Gagal memuat berita acara.'
  } finally {
    loading.value = false
  }
}

async function simpan() {
  if (!sesiId.value || !isiValid.value || menyimpan.value) return
  menyimpan.value = true
  errorMessage.value = ''
  try {
    const sudahAda = tersimpan.value !== null
    const url = `/sesi-ruang/${sesiId.value}/berita-acara`
    const res = sudahAda ? await apiClient.put(url, { isi: isi.value.trim() }) : await apiClient.post(url, { isi: isi.value.trim() })
    tersimpan.value = res.data.data
    feedback.value = 'Berita acara tersimpan. Jumlah hadir dan absen dihitung otomatis oleh server.'
  } catch (err) {
    errorMessage.value = err instanceof Error ? err.message : 'Gagal menyimpan berita acara.'
  } finally {
    menyimpan.value = false
  }
}

async function cetak() {
  if (!sesiId.value) return
  mencetak.value = true
  errorMessage.value = ''
  try {
    await cetakLaporan('berita-acara', { sesi_ruang_id: sesiId.value })
  } catch (err) {
    errorMessage.value = err instanceof Error ? err.message : 'Gagal mencetak berita acara.'
  } finally {
    mencetak.value = false
  }
}

watch(sesiId, muatBeritaAcara)
onMounted(muatSesi)
</script>

<template>
  <StafLayout>
    <div class="mx-auto max-w-3xl space-y-4">
      <div>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900">Berita Acara Ujian</h1>
        <p class="text-sm text-slate-500">Isi catatan pelaksanaan ujian di ruang yang Anda awasi sebelum menutup sesi.</p>
      </div>

      <div v-if="errorMessage" class="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert">{{ errorMessage }}</div>
      <div v-if="feedback" class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800" role="status">{{ feedback }}</div>

      <p v-if="sesiOpsi.length === 0" class="card bg-white p-8 text-center text-sm text-slate-500">Anda belum ditugaskan mengawasi sesi ujian apa pun.</p>

      <template v-else>
        <div class="card bg-white p-4">
          <label class="text-xs font-semibold text-slate-600">
            Sesi ruang
            <select v-model="sesiId" class="input mt-1">
              <option v-for="s in sesiOpsi" :key="s.id" :value="s.id">{{ s.label }}</option>
            </select>
          </label>
        </div>

        <div v-if="tersimpan" class="grid grid-cols-2 gap-3 text-center">
          <div class="rounded-xl bg-emerald-50 p-3"><p class="text-xs text-emerald-700">Hadir</p><p class="text-xl font-bold text-emerald-800">{{ tersimpan.jumlah_hadir }}</p></div>
          <div class="rounded-xl bg-red-50 p-3"><p class="text-xs text-red-700">Tidak hadir</p><p class="text-xl font-bold text-red-800">{{ tersimpan.jumlah_absen }}</p></div>
        </div>

        <div class="card space-y-3 bg-white p-4">
          <label class="block text-xs font-semibold text-slate-600">
            Catatan pelaksanaan (kejadian, kendala, siswa bermasalah)
            <textarea v-model="isi" rows="8" class="input mt-1" :disabled="loading" placeholder="Contoh: Ujian berlangsung tertib. Dua siswa terkunci karena layar HP mati dan sudah dibuka kuncinya."></textarea>
          </label>
          <p v-if="isi !== '' && !isiValid" class="text-xs text-red-700">Isi minimal 5 karakter.</p>
          <div class="flex flex-wrap gap-2">
            <button class="btn-primary" :disabled="!isiValid || menyimpan || loading" @click="simpan">
              {{ menyimpan ? 'Menyimpan...' : tersimpan ? 'Perbarui Berita Acara' : 'Simpan Berita Acara' }}
            </button>
            <button class="btn-secondary" :disabled="!tersimpan || mencetak" @click="cetak">{{ mencetak ? 'Membuat PDF...' : 'Cetak PDF' }}</button>
          </div>
        </div>
      </template>
    </div>
  </StafLayout>
</template>
