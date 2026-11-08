<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import DashAngka from './DashAngka.vue'
import DashKartu from './DashKartu.vue'
import {
  KELAS_NADA,
  LABEL_STATUS_SESI,
  labelHari,
  persen,
  ringkasKesiapan,
  type PanelOperator
} from '@/lib/dashboard'

const props = defineProps<{ data: PanelOperator; hariIni: string }>()

const ringkasan = computed(() => ringkasKesiapan(props.data.kesiapan))
const live = computed(() => props.data.langsung)
</script>

<template>
  <div class="space-y-4" aria-label="Panel operator">
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
      <DashAngka
        label="Sesi hari ini"
        :nilai="live.sesi_hari_ini"
        :keterangan="`${live.sesi_berjalan} sedang berjalan`"
        :nada="live.sesi_berjalan > 0 ? 'info' : 'netral'"
        tautan="/staf/kontrol-sesi"
      />
      <DashAngka label="Sedang mengerjakan" :nilai="live.mengerjakan" :keterangan="`${live.menunggu} menunggu dimulai`" nada="info" tautan="/staf/monitoring" />
      <DashAngka
        label="Terkunci"
        :nilai="live.terkunci"
        keterangan="menunggu dibuka pengawas"
        :nada="live.terkunci > 0 ? 'bahaya' : 'netral'"
        tautan="/staf/monitoring"
      />
      <DashAngka label="Sudah selesai" :nilai="live.selesai" :keterangan="`dari ${live.peserta} peserta hari ini`" :nada="live.selesai > 0 ? 'baik' : 'netral'" />
    </div>

    <div class="grid gap-4 xl:grid-cols-3">
      <DashKartu class="xl:col-span-2" judul="Jadwal hari ini" keterangan="Urut jam mulai. Diperbarui otomatis." tautan="/staf/kontrol-sesi" label-tautan="Kontrol sesi">
        <p v-if="data.hari_ini.length === 0" class="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
          Tidak ada ujian terjadwal hari ini.
        </p>
        <ul v-else class="space-y-3">
          <li v-for="s in data.hari_ini" :key="s.id" class="rounded-xl border border-slate-200 p-3">
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div class="min-w-0">
                <p class="text-sm font-bold text-slate-900">
                  <span class="font-mono text-slate-500">{{ s.jam_mulai }}-{{ s.jam_akhir }}</span>
                  {{ s.mapel ?? s.jadwal }}
                </p>
                <p class="text-xs text-slate-500">
                  {{ s.jadwal }} - {{ s.ruang }} (sesi {{ s.sesi_ke }}) - Pengawas:
                  <span :class="s.pengawas.length === 0 ? 'font-semibold text-amber-700' : ''">{{ s.pengawas.length ? s.pengawas.join(', ') : 'belum ditetapkan' }}</span>
                </p>
              </div>
              <div class="flex items-center gap-2">
                <span v-if="s.token" class="rounded-lg border border-brand-200 bg-brand-50 px-2 py-0.5 font-mono text-sm font-extrabold tracking-widest text-brand-700" title="Token ujian">{{ s.token }}</span>
                <span class="rounded-full px-2.5 py-0.5 text-xs font-bold" :class="KELAS_NADA[LABEL_STATUS_SESI[s.status].nada]">{{ LABEL_STATUS_SESI[s.status].teks }}</span>
              </div>
            </div>
            <div class="mt-2.5" role="img" :aria-label="`${s.peserta.selesai} dari ${s.peserta.total} peserta selesai`">
              <div class="h-1.5 overflow-hidden rounded-full bg-slate-200">
                <div class="h-full bg-emerald-500" :style="{ width: persen(s.peserta.selesai, s.peserta.total) + '%' }"></div>
              </div>
              <p class="mt-1.5 flex flex-wrap gap-x-3 text-[11px] text-slate-600">
                <span>Peserta <strong>{{ s.peserta.total }}</strong></span>
                <span>Hadir <strong>{{ s.peserta.hadir }}</strong></span>
                <span>Mengerjakan <strong>{{ s.peserta.mengerjakan }}</strong></span>
                <span :class="s.peserta.terkunci > 0 ? 'font-bold text-red-700' : ''">Terkunci <strong>{{ s.peserta.terkunci }}</strong></span>
                <span>Selesai <strong>{{ s.peserta.selesai }}</strong></span>
              </p>
            </div>
          </li>
        </ul>
      </DashKartu>

      <div class="space-y-4">
        <DashKartu judul="Perlu tindakan" keterangan="Hal yang menunggu keputusan Anda.">
          <p v-if="data.perlu_tindakan.length === 0" class="flex items-center gap-2 rounded-xl bg-emerald-50 p-3 text-sm font-medium text-emerald-800">
            <span aria-hidden="true">✓</span> Semua beres. Tidak ada tindakan yang menunggu.
          </p>
          <ul v-else class="space-y-2">
            <li v-for="t in data.perlu_tindakan" :key="t.kode" class="flex items-start justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50/70 p-3">
              <p class="text-sm text-amber-900">{{ t.pesan }}</p>
              <RouterLink :to="t.tautan" class="shrink-0 text-xs font-bold text-amber-800 hover:underline">Buka →</RouterLink>
            </li>
          </ul>
        </DashKartu>

        <DashKartu
          judul="Kesiapan ujian"
          :keterangan="data.kesiapan.length ? `${ringkasan.siap} siap, ${ringkasan.bermasalah} perlu perhatian (14 hari ke depan)` : '14 hari ke depan'"
          tautan="/staf/jadwal?tab=jadwal"
          label-tautan="Atur jadwal"
        >
          <p v-if="data.kesiapan.length === 0" class="text-sm text-slate-500">Belum ada ujian terjadwal dalam 14 hari ke depan.</p>
          <ul v-else class="space-y-2.5">
            <li v-for="j in data.kesiapan" :key="j.jadwal_id" class="rounded-xl border p-3" :class="j.siap ? 'border-emerald-200' : 'border-amber-300'">
              <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                  <p class="truncate text-sm font-semibold text-slate-900">{{ j.nama }}</p>
                  <p class="text-xs text-slate-500">{{ j.mapel }} - {{ labelHari(j.tanggal, hariIni) }} - {{ j.jumlah_sesi }} sesi</p>
                </div>
                <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-bold" :class="j.siap ? KELAS_NADA.baik : KELAS_NADA.peringatan">
                  {{ j.siap ? 'Siap' : `${j.masalah.length} masalah` }}
                </span>
              </div>
              <ul v-if="!j.siap" class="mt-1.5 list-disc space-y-0.5 pl-4 text-xs text-amber-800">
                <li v-for="m in j.masalah" :key="m.kode">{{ m.pesan }}</li>
              </ul>
            </li>
          </ul>
        </DashKartu>
      </div>
    </div>
  </div>
</template>
