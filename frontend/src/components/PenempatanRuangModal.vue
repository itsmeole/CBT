<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { apiClient } from '@/api/client'
import { ambilHalaman } from '@/lib/paginasi'
import {
  acakPenempatan,
  ringkasPerRuang,
  susunPayloadPenempatan,
  validasiPenempatan,
  type PetaPenempatan
} from '@/lib/penempatan'

/**
 * Penempatan peserta ke ruang ujian untuk satu jadwal. Pilih kelas dan ruang, lalu
 * acak otomatis atau atur sendiri per siswa. Hasil penempatan menjadi dasar daftar hadir per ruang.
 */
const props = defineProps<{
  jadwal: { id: number; nama: string; tanggal: string; tahun_ajaran_id: number }
}>()
const emit = defineEmits<{ (e: 'close'): void; (e: 'saved'): void }>()

interface KelasItem {
  id: number
  nama: string
  tahun_ajaran_id: number
}
interface SiswaItem {
  id: number
  nis: string
  kelas_id: number
  nama?: string
  user?: { name: string }
  kelas?: { nama: string }
}
interface RuangItem {
  id: number
  nama: string
  kode?: string
  kapasitas: number
}

const UKURAN_HALAMAN = 50

const kelasList = ref<KelasItem[]>([])
const siswaList = ref<SiswaItem[]>([])
const ruangList = ref<RuangItem[]>([])
const sudahDitempatkan = ref<Set<number>>(new Set())
const jumlahSesiAda = ref(0)

const sesiKe = ref(1)
const jamMulai = ref('08:00')
const jamAkhir = ref('09:30')
const kelasDipilih = ref<number[]>([])
const ruangDipilih = ref<number[]>([])
const peta = ref<PetaPenempatan>({})

const saringKelas = ref<number | ''>('')
const cari = ref('')
const halaman = ref(1)
const massalRuang = ref<number | ''>('')

const loading = ref(true)
const menyimpan = ref(false)
const konfirmasiReset = ref(false)
const pesanError = ref('')
const pesanInfo = ref('')

const kelasTahunIni = computed(() => kelasList.value.filter((k) => k.tahun_ajaran_id === props.jadwal.tahun_ajaran_id))
const ruangTerpilih = computed(() => ruangList.value.filter((r) => ruangDipilih.value.includes(r.id)))
const namaSiswa = (s: SiswaItem) => s.nama ?? s.user?.name ?? '-'

const kandidat = computed(() =>
  siswaList.value
    .filter((s) => kelasDipilih.value.includes(s.kelas_id) && !sudahDitempatkan.value.has(s.id))
    .sort((a, b) => (a.kelas?.nama ?? '').localeCompare(b.kelas?.nama ?? '') || namaSiswa(a).localeCompare(namaSiswa(b), 'id'))
)
const kandidatIds = computed(() => kandidat.value.map((s) => s.id))

const tersaring = computed(() => {
  const k = cari.value.trim().toLowerCase()
  return kandidat.value.filter(
    (s) =>
      (saringKelas.value === '' || s.kelas_id === saringKelas.value) &&
      (k === '' || namaSiswa(s).toLowerCase().includes(k) || s.nis.includes(k))
  )
})
const halamanIni = computed(() => ambilHalaman(tersaring.value, halaman.value, UKURAN_HALAMAN))

const ringkasan = computed(() => ringkasPerRuang(peta.value, ruangTerpilih.value))
const totalKursi = computed(() => ruangTerpilih.value.reduce((jumlah, r) => jumlah + r.kapasitas, 0))
const validasi = computed(() => validasiPenempatan(peta.value, kandidatIds.value, ruangTerpilih.value))
const jamValid = computed(() => jamMulai.value !== '' && jamAkhir.value > jamMulai.value)

async function muat() {
  loading.value = true
  try {
    const [kelas, siswa, ruang, ada] = await Promise.all([
      apiClient.get('/kelas'),
      apiClient.get('/siswa'),
      apiClient.get('/ruang'),
      apiClient.get(`/jadwal/${props.jadwal.id}/penempatan`)
    ])
    kelasList.value = kelas.data.data ?? []
    siswaList.value = siswa.data.data ?? []
    ruangList.value = ruang.data.data ?? []
    const sesi: { sesi_ke: number; peserta: { siswa_id: number }[] }[] = ada.data.data.sesi ?? []
    jumlahSesiAda.value = sesi.length
    sudahDitempatkan.value = new Set(sesi.flatMap((s) => s.peserta.map((p) => p.siswa_id)))
    sesiKe.value = sesi.length > 0 ? Math.max(...sesi.map((s) => s.sesi_ke)) + 1 : 1
  } catch (err) {
    pesanError.value = err instanceof Error ? err.message : 'Gagal memuat data penempatan.'
  } finally {
    loading.value = false
  }
}

/** Buang penempatan yang sudah tidak relevan setelah pilihan kelas/ruang berubah. */
watch([kandidatIds, ruangDipilih], () => {
  const idValid = new Set(kandidatIds.value)
  const ruangValid = new Set(ruangDipilih.value)
  const baru: PetaPenempatan = {}
  for (const [siswaId, ruangId] of Object.entries(peta.value)) {
    if (idValid.has(Number(siswaId)) && ruangValid.has(ruangId)) baru[Number(siswaId)] = ruangId
  }
  peta.value = baru
})
watch([saringKelas, cari], () => {
  halaman.value = 1
})

function acak() {
  pesanError.value = ''
  const hasil = acakPenempatan(kandidatIds.value, ruangTerpilih.value)
  if (hasil.error) {
    pesanError.value = hasil.error
    return
  }
  peta.value = hasil.peta
  pesanInfo.value = 'Penempatan diacak. Anda masih bisa mengubah ruang tiap siswa sebelum menyimpan.'
}

function ubahRuang(siswaId: number, nilai: string) {
  const baru = { ...peta.value }
  if (nilai === '') delete baru[siswaId]
  else baru[siswaId] = Number(nilai)
  peta.value = baru
}

function tetapkanSemuaTampil() {
  if (massalRuang.value === '') return
  const baru = { ...peta.value }
  for (const s of tersaring.value) baru[s.id] = massalRuang.value
  peta.value = baru
}

function kosongkan() {
  peta.value = {}
  pesanInfo.value = ''
}

async function simpan() {
  if (!validasi.value.ok || !jamValid.value || menyimpan.value) return
  menyimpan.value = true
  pesanError.value = ''
  try {
    await apiClient.post(`/jadwal/${props.jadwal.id}/penempatan`, {
      sesi_ke: sesiKe.value,
      jam_mulai: jamMulai.value,
      jam_akhir: jamAkhir.value,
      penempatan: susunPayloadPenempatan(peta.value, ruangTerpilih.value)
    })
    emit('saved')
  } catch (err) {
    pesanError.value = err instanceof Error ? err.message : 'Gagal menyimpan penempatan.'
  } finally {
    menyimpan.value = false
  }
}

async function resetPenempatan() {
  menyimpan.value = true
  pesanError.value = ''
  try {
    await apiClient.delete(`/jadwal/${props.jadwal.id}/penempatan`)
    konfirmasiReset.value = false
    peta.value = {}
    await muat()
    pesanInfo.value = 'Penempatan sebelumnya direset. Silakan atur ulang.'
  } catch (err) {
    pesanError.value = err instanceof Error ? err.message : 'Gagal mereset penempatan.'
  } finally {
    menyimpan.value = false
  }
}

onMounted(muat)
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-3" role="dialog" aria-modal="true" aria-labelledby="judul-penempatan">
    <div class="card flex max-h-[94vh] w-full max-w-5xl flex-col bg-white">
      <div class="flex items-start justify-between border-b border-slate-200 p-4">
        <div>
          <h2 id="judul-penempatan" class="text-lg font-bold text-slate-900">Penempatan Peserta ke Ruang</h2>
          <p class="text-xs text-slate-500">{{ jadwal.nama }} - {{ jadwal.tanggal }}</p>
        </div>
        <button class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup" @click="emit('close')">✕</button>
      </div>

      <p v-if="loading" class="p-8 text-center text-sm text-slate-500">Memuat data...</p>

      <div v-else class="flex-1 space-y-4 overflow-y-auto p-4">
        <div v-if="pesanError" class="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert">{{ pesanError }}</div>
        <div v-if="pesanInfo" class="rounded-xl border border-sky-200 bg-sky-50 p-3 text-sm text-sky-800" role="status">{{ pesanInfo }}</div>

        <div v-if="jumlahSesiAda > 0" class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
          <span>Jadwal ini sudah memiliki {{ jumlahSesiAda }} sesi ruang dan {{ sudahDitempatkan.size }} peserta tertempatkan. Siswa tersebut tidak muncul di daftar di bawah.</span>
          <template v-if="!konfirmasiReset">
            <button class="btn-secondary !min-h-0 !py-1.5 text-xs" @click="konfirmasiReset = true">Reset Penempatan</button>
          </template>
          <span v-else class="flex items-center gap-2">
            <span class="text-xs font-semibold">Hapus semua sesi & penempatan jadwal ini?</span>
            <button class="btn-primary !min-h-0 !bg-red-600 !py-1.5 text-xs" :disabled="menyimpan" @click="resetPenempatan">Ya, reset</button>
            <button class="btn-secondary !min-h-0 !py-1.5 text-xs" @click="konfirmasiReset = false">Batal</button>
          </span>
        </div>

        <section class="grid gap-3 sm:grid-cols-3" aria-label="Waktu sesi">
          <label class="text-xs font-semibold text-slate-600">
            Sesi ke
            <input v-model.number="sesiKe" type="number" min="1" max="20" class="input mt-1" />
          </label>
          <label class="text-xs font-semibold text-slate-600">
            Jam mulai
            <input v-model="jamMulai" type="time" class="input mt-1" />
          </label>
          <label class="text-xs font-semibold text-slate-600">
            Jam akhir
            <input v-model="jamAkhir" type="time" class="input mt-1" :aria-invalid="!jamValid" />
            <span v-if="!jamValid" class="mt-1 block font-normal text-red-700">Jam akhir harus setelah jam mulai.</span>
          </label>
        </section>

        <div class="grid gap-4 md:grid-cols-2">
          <section aria-label="Pilih kelas">
            <h3 class="mb-1 text-xs font-bold text-slate-500">1. Kelas peserta</h3>
            <div class="max-h-40 space-y-1 overflow-y-auto rounded-xl border border-slate-200 p-2">
              <label v-for="k in kelasTahunIni" :key="k.id" class="flex cursor-pointer items-center gap-2 rounded p-1 text-sm hover:bg-slate-50">
                <input v-model="kelasDipilih" type="checkbox" :value="k.id" class="h-4 w-4 rounded text-brand-600" />
                <span>{{ k.nama }}</span>
                <span class="ml-auto text-xs text-slate-400">{{ siswaList.filter((s) => s.kelas_id === k.id).length }} siswa</span>
              </label>
              <p v-if="kelasTahunIni.length === 0" class="p-2 text-xs italic text-slate-400">Tidak ada kelas pada tahun ajaran jadwal ini.</p>
            </div>
          </section>
          <section aria-label="Pilih ruang">
            <h3 class="mb-1 text-xs font-bold text-slate-500">2. Ruang yang dipakai</h3>
            <div class="max-h-40 space-y-1 overflow-y-auto rounded-xl border border-slate-200 p-2">
              <label v-for="r in ruangList" :key="r.id" class="flex cursor-pointer items-center gap-2 rounded p-1 text-sm hover:bg-slate-50">
                <input v-model="ruangDipilih" type="checkbox" :value="r.id" class="h-4 w-4 rounded text-brand-600" />
                <span>{{ r.nama }}</span>
                <span class="ml-auto text-xs text-slate-400">kapasitas {{ r.kapasitas }}</span>
              </label>
            </div>
          </section>
        </div>

        <section aria-label="Penempatan" class="space-y-3">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-xs font-bold text-slate-500">3. Penempatan ({{ kandidat.length }} siswa, {{ totalKursi }} kursi)</h3>
            <div class="flex flex-wrap gap-2">
              <button class="btn-primary !min-h-0 !py-1.5 text-xs" :disabled="kandidat.length === 0 || ruangTerpilih.length === 0" @click="acak">🎲 Acak Penempatan</button>
              <button class="btn-secondary !min-h-0 !py-1.5 text-xs" :disabled="Object.keys(peta).length === 0" @click="kosongkan">Kosongkan</button>
            </div>
          </div>

          <ul v-if="ruangTerpilih.length > 0" class="flex flex-wrap gap-2" aria-label="Isi tiap ruang">
            <li
              v-for="r in ringkasan"
              :key="r.ruangId"
              class="rounded-full border px-3 py-1 text-xs font-semibold"
              :class="r.melebihi ? 'border-red-300 bg-red-50 text-red-700' : 'border-slate-200 bg-slate-50 text-slate-700'"
            >
              {{ r.nama }}: {{ r.jumlah }}/{{ r.kapasitas }}<span v-if="r.melebihi"> (melebihi!)</span>
            </li>
          </ul>

          <div v-if="kandidat.length > 0" class="space-y-2">
            <div class="grid gap-2 sm:grid-cols-4">
              <input v-model="cari" class="input" placeholder="Cari nama / NIS..." aria-label="Cari siswa" />
              <select v-model="saringKelas" class="input" aria-label="Saring kelas">
                <option value="">Semua kelas terpilih</option>
                <option v-for="k in kelasTahunIni.filter((x) => kelasDipilih.includes(x.id))" :key="k.id" :value="k.id">{{ k.nama }}</option>
              </select>
              <select v-model="massalRuang" class="input" aria-label="Ruang untuk semua yang tampil">
                <option value="">Tetapkan semua yang tampil ke...</option>
                <option v-for="r in ruangTerpilih" :key="r.id" :value="r.id">{{ r.nama }}</option>
              </select>
              <button class="btn-secondary" :disabled="massalRuang === ''" @click="tetapkanSemuaTampil">Terapkan ({{ tersaring.length }})</button>
            </div>

            <div class="max-h-80 overflow-auto rounded-xl border border-slate-200">
              <table class="w-full text-left text-sm">
                <thead class="sticky top-0 bg-slate-50 text-xs text-slate-500">
                  <tr>
                    <th class="px-3 py-2">NIS</th>
                    <th class="px-3 py-2">Nama</th>
                    <th class="px-3 py-2">Kelas</th>
                    <th class="px-3 py-2">Ruang</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="s in halamanIni.item" :key="s.id" class="border-t border-slate-100">
                    <td class="px-3 py-1.5 font-mono text-xs">{{ s.nis }}</td>
                    <td class="px-3 py-1.5">{{ namaSiswa(s) }}</td>
                    <td class="px-3 py-1.5 text-xs">{{ s.kelas?.nama }}</td>
                    <td class="px-3 py-1.5">
                      <select
                        :value="peta[s.id] ?? ''"
                        class="input !min-h-0 !py-1 text-xs"
                        :class="peta[s.id] === undefined ? 'border-amber-400' : ''"
                        :aria-label="`Ruang untuk ${namaSiswa(s)}`"
                        @change="ubahRuang(s.id, ($event.target as HTMLSelectElement).value)"
                      >
                        <option value="">- belum -</option>
                        <option v-for="r in ruangTerpilih" :key="r.id" :value="r.id">{{ r.nama }}</option>
                      </select>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <nav v-if="halamanIni.totalHalaman > 1" class="flex items-center justify-between text-xs text-slate-600" aria-label="Paginasi siswa">
              <span>{{ halamanIni.dari }}-{{ halamanIni.sampai }} dari {{ halamanIni.total }}</span>
              <span class="flex items-center gap-2">
                <button class="btn-secondary !min-h-0 !py-1 text-xs" :disabled="halamanIni.halaman <= 1" @click="halaman = halamanIni.halaman - 1">Sebelumnya</button>
                <span class="font-mono">{{ halamanIni.halaman }} / {{ halamanIni.totalHalaman }}</span>
                <button class="btn-secondary !min-h-0 !py-1 text-xs" :disabled="halamanIni.halaman >= halamanIni.totalHalaman" @click="halaman = halamanIni.halaman + 1">Berikutnya</button>
              </span>
            </nav>
          </div>
          <p v-else class="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">Pilih kelas peserta terlebih dahulu.</p>
        </section>
      </div>

      <div class="flex flex-col gap-2 border-t border-slate-200 p-4 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-xs" :class="validasi.ok && jamValid ? 'text-emerald-700' : 'text-amber-700'" role="status">
          {{ validasi.ok ? (jamValid ? 'Penempatan siap disimpan.' : 'Perbaiki jam sesi.') : validasi.alasan }}
        </p>
        <div class="flex gap-2">
          <button class="btn-secondary" :disabled="menyimpan" @click="emit('close')">Batal</button>
          <button class="btn-primary" :disabled="!validasi.ok || !jamValid || menyimpan" @click="simpan">
            {{ menyimpan ? 'Menyimpan...' : 'Simpan Penempatan' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
