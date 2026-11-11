<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import DashAngka from './DashAngka.vue'
import DashKartu from './DashKartu.vue'
import { KELAS_NADA, labelHari, type PanelGuru } from '@/lib/dashboard'

const props = defineProps<{ data: PanelGuru; hariIni: string }>()

const sesiHariIni = computed(() => props.data.pengawasan.sesi.filter((s) => s.hari_ini).length)

/** Keterangan daftar antrean koreksi: menyebut pembatasan bila ada jadwal yang tidak ditampilkan. */
const keteranganKoreksi = computed(() => {
  const { jumlah_jadwal: jumlah, per_jadwal: tampil } = props.data.koreksi
  return jumlah > tampil.length
    ? `Menampilkan ${tampil.length} jadwal terbaru dari ${jumlah} jadwal yang punya antrean.`
    : 'Jawaban yang sudah dikumpulkan dan belum diberi skor.'
})
</script>

<template>
  <div class="space-y-4" aria-label="Panel guru">
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-3">
      <DashAngka
        label="Essay menunggu koreksi"
        :nilai="data.koreksi.total"
        :keterangan="data.koreksi.total > 0 ? `${data.koreksi.jumlah_jadwal} jadwal` : 'Tidak ada antrean'"
        :nada="data.koreksi.total > 0 ? 'peringatan' : 'baik'"
        tautan="/staf/koreksi-essay"
      />
      <DashAngka
        label="Mengawas hari ini"
        :nilai="sesiHariIni"
        :keterangan="`${data.pengawasan.sesi.length} sesi mendatang`"
        :nada="sesiHariIni > 0 ? 'info' : 'netral'"
        tautan="/staf/monitoring"
      />
      <DashAngka label="Ujian mapel saya" :nilai="data.jadwal_saya.length" keterangan="jadwal mendatang" tautan="/staf/jadwal?tab=jadwal" />
    </div>

    <div class="grid gap-4 xl:grid-cols-2">
      <div class="space-y-4">
        <DashKartu judul="Ujian mapel saya" keterangan="Jadwal mendatang pada mapel yang Anda ampu." tautan="/staf/jadwal?tab=jadwal">
          <p v-if="data.jadwal_saya.length === 0" class="text-sm text-slate-500">Belum ada jadwal ujian mendatang untuk mapel Anda.</p>
          <ul v-else class="divide-y divide-slate-100">
            <li v-for="j in data.jadwal_saya" :key="j.jadwal_id" class="flex items-center justify-between gap-3 py-2.5">
              <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-slate-900">{{ j.nama }}</p>
                <p class="text-xs text-slate-500">{{ j.mapel }} - {{ labelHari(j.tanggal, hariIni) }}</p>
              </div>
              <span class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="j.jumlah_butir > 0 ? KELAS_NADA.netral : KELAS_NADA.bahaya">
                {{ j.jumlah_butir }} butir
              </span>
            </li>
          </ul>
        </DashKartu>

        <DashKartu judul="Hasil ujian terbaru" keterangan="5 ujian terbaru di kelas yang Anda ampu." tautan="/staf/nilai" label-tautan="Rekap nilai">
          <p v-if="data.hasil_terbaru.length === 0" class="text-sm text-slate-500">Belum ada hasil ujian.</p>
          <ul v-else class="space-y-3">
            <li v-for="h in data.hasil_terbaru" :key="h.jadwal_id" class="rounded-xl border border-slate-200 p-3">
              <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                  <p class="truncate text-sm font-semibold text-slate-900">{{ h.nama }}</p>
                  <p class="text-xs text-slate-500">{{ h.mapel }} - {{ labelHari(h.tanggal, hariIni) }}</p>
                </div>
                <div class="shrink-0 text-right">
                  <p class="text-[10px] font-bold text-slate-500">Rata-rata</p>
                  <p class="text-xl font-extrabold tabular-nums text-slate-900">{{ h.rata_rata ?? '-' }}</p>
                </div>
              </div>
              <p class="mt-1.5 flex flex-wrap gap-2 text-xs">
                <span class="rounded-full px-2 py-0.5 font-semibold" :class="KELAS_NADA.baik">Final: {{ h.final }}</span>
                <span v-if="h.menunggu > 0" class="rounded-full px-2 py-0.5 font-semibold" :class="KELAS_NADA.peringatan">Menunggu koreksi: {{ h.menunggu }}</span>
                <RouterLink to="/staf/analisis-butir" class="ml-auto font-semibold text-brand-600 hover:underline">Analisis butir →</RouterLink>
              </p>
            </li>
          </ul>
        </DashKartu>
      </div>

      <div class="space-y-4">
        <DashKartu judul="Tugas pengawasan saya" keterangan="Hari ini dan yang akan datang." tautan="/staf/monitoring" label-tautan="Monitoring">
          <p v-if="data.pengawasan.sesi.length === 0" class="text-sm text-slate-500">Anda belum ditugaskan mengawasi sesi apa pun.</p>
          <ul v-else class="space-y-2.5">
            <li
              v-for="s in data.pengawasan.sesi"
              :key="s.id"
              class="flex flex-wrap items-center justify-between gap-2 rounded-xl border p-3"
              :class="s.hari_ini ? 'border-sky-300 bg-sky-50/60' : 'border-slate-200'"
            >
              <div class="min-w-0">
                <p class="text-sm font-semibold text-slate-900">
                  <span class="font-mono text-slate-500">{{ s.jam_mulai }}-{{ s.jam_akhir }}</span> {{ s.mapel ?? s.jadwal }}
                </p>
                <p class="text-xs text-slate-500">{{ labelHari(s.tanggal, hariIni) }} - {{ s.ruang }} (sesi {{ s.sesi_ke }})</p>
              </div>
            </li>
          </ul>
        </DashKartu>

        <DashKartu judul="Antrean koreksi essay" :keterangan="keteranganKoreksi" tautan="/staf/koreksi-essay" label-tautan="Mulai koreksi">
          <p v-if="data.koreksi.per_jadwal.length === 0" class="flex items-center gap-2 rounded-xl bg-emerald-50 p-3 text-sm font-medium text-emerald-800">
            <span aria-hidden="true">✓</span> Tidak ada jawaban essay yang perlu dikoreksi.
          </p>
          <ul v-else class="divide-y divide-slate-100">
            <li v-for="k in data.koreksi.per_jadwal" :key="k.jadwal_id" class="flex items-center justify-between gap-3 py-2.5">
              <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-slate-900">{{ k.nama }}</p>
                <p class="text-xs text-slate-500">{{ k.mapel }} - {{ labelHari(k.tanggal, hariIni) }}</p>
              </div>
              <span class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-bold" :class="KELAS_NADA.peringatan">{{ k.menunggu }} jawaban</span>
            </li>
          </ul>
        </DashKartu>
      </div>
    </div>
  </div>
</template>
