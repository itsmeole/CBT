<script setup lang="ts">
import { jam } from '@/lib/waktu'
import { computed, onMounted, ref } from 'vue'
import { apiClient } from '@/api/client'

/**
 * Tentukan pengawas untuk satu sesi ruang. Pengawas ditetapkan per sesi (ruang + mata pelajaran + jam),
 * sehingga seorang guru dapat berpindah ruang antar mata pelajaran dalam satu hari. Server menolak
 * bila pengawas bentrok dengan penugasan lain pada jam yang sama.
 */
const props = defineProps<{
  sesi: { id: number; ruang: string; sesi_ke: number; jam_mulai: string; jam_akhir: string; pengawas_ids: number[] }
  judul: string
}>()
const emit = defineEmits<{ (e: 'close'): void; (e: 'saved'): void }>()

interface GuruItem {
  id: number
  nama?: string
  user?: { name: string }
}

const guruList = ref<GuruItem[]>([])
const dipilih = ref<number[]>([...props.sesi.pengawas_ids])
const cari = ref('')
const loading = ref(true)
const menyimpan = ref(false)
const pesanError = ref('')

const namaGuru = (g: GuruItem) => g.nama ?? g.user?.name ?? `Guru ${g.id}`
const tersaring = computed(() => {
  const k = cari.value.trim().toLowerCase()
  return guruList.value.filter((g) => k === '' || namaGuru(g).toLowerCase().includes(k))
})

async function muat() {
  try {
    const res = await apiClient.get('/guru')
    guruList.value = (res.data.data ?? []).sort((a: GuruItem, b: GuruItem) => namaGuru(a).localeCompare(namaGuru(b), 'id'))
  } catch (err) {
    pesanError.value = err instanceof Error ? err.message : 'Gagal memuat daftar guru.'
  } finally {
    loading.value = false
  }
}

async function simpan() {
  menyimpan.value = true
  pesanError.value = ''
  try {
    await apiClient.post(`/sesi-ruang/${props.sesi.id}/pengawas`, { guru_ids: dipilih.value })
    emit('saved')
  } catch (err) {
    pesanError.value = err instanceof Error ? err.message : 'Gagal menyimpan pengawas.'
  } finally {
    menyimpan.value = false
  }
}

onMounted(muat)
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true" aria-labelledby="judul-pengawas">
    <div class="card flex max-h-[90vh] w-full max-w-md flex-col bg-white">
      <div class="border-b border-slate-200 p-4">
        <h2 id="judul-pengawas" class="text-base font-bold text-slate-900">Pengawas {{ sesi.ruang }}</h2>
        <p class="text-xs text-slate-500">{{ judul }} - sesi {{ sesi.sesi_ke }}, {{ jam(sesi.jam_mulai) }}-{{ jam(sesi.jam_akhir) }}</p>
      </div>

      <div class="flex-1 space-y-3 overflow-y-auto p-4">
        <div v-if="pesanError" class="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert">{{ pesanError }}</div>
        <input v-model="cari" class="input" placeholder="Cari guru..." aria-label="Cari guru" />
        <p v-if="loading" class="text-center text-sm text-slate-500">Memuat guru...</p>
        <div v-else class="max-h-64 space-y-1 overflow-y-auto rounded-xl border border-slate-200 p-2">
          <label v-for="g in tersaring" :key="g.id" class="flex cursor-pointer items-center gap-2 rounded p-1.5 text-sm hover:bg-slate-50">
            <input v-model="dipilih" type="checkbox" :value="g.id" class="h-4 w-4 rounded text-brand-600" />
            <span>{{ namaGuru(g) }}</span>
          </label>
          <p v-if="tersaring.length === 0" class="p-2 text-xs italic text-slate-400">Tidak ada guru yang cocok.</p>
        </div>
        <p class="text-xs text-slate-500">{{ dipilih.length }} pengawas dipilih. Satu guru tidak bisa mengawas dua ruang pada jam yang bertabrakan.</p>
      </div>

      <div class="flex justify-end gap-2 border-t border-slate-200 p-4">
        <button class="btn-secondary" :disabled="menyimpan" @click="emit('close')">Batal</button>
        <button class="btn-primary" :disabled="menyimpan || loading" @click="simpan">{{ menyimpan ? 'Menyimpan...' : 'Simpan Pengawas' }}</button>
      </div>
    </div>
  </div>
</template>
