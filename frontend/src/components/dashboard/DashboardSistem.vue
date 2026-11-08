<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import DashAngka from './DashAngka.vue'
import DashKartu from './DashKartu.vue'
import { KELAS_NADA, aksiSensitif, labelAksi, statusBackup, waktuRelatif, type PanelSistem } from '@/lib/dashboard'

const props = defineProps<{ data: PanelSistem }>()

const backup = computed(() => statusBackup(props.data.kesehatan.backup_terakhir))
const antrean = computed(() => props.data.kesehatan.antrean_menunggu)

interface ButirData {
  kunci: string
  label: string
  jumlah: number
  tautan: string
}

const butirData = computed<ButirData[]>(() => [
  { kunci: 'guru', label: 'Guru tanpa pengampu mapel', jumlah: props.data.kualitas_data.guru_tanpa_pengampu, tautan: '/staf/master?tab=guru_mapel' },
  { kunci: 'mapel', label: 'Mapel tanpa pengampu', jumlah: props.data.kualitas_data.mapel_tanpa_pengampu, tautan: '/staf/master?tab=guru_mapel' },
  { kunci: 'kelas', label: 'Kelas tanpa siswa', jumlah: props.data.kualitas_data.kelas_tanpa_siswa, tautan: '/staf/master?tab=kelas' },
  { kunci: 'akun', label: 'Akun nonaktif', jumlah: props.data.kualitas_data.akun_nonaktif, tautan: '/staf/master?tab=siswa' }
])

const ROLE: [keyof PanelSistem['pengguna'], string][] = [
  ['superadmin', 'Superadmin'],
  ['operator', 'Operator'],
  ['guru', 'Guru'],
  ['siswa', 'Siswa']
]
</script>

<template>
  <div class="space-y-4" aria-label="Panel sistem">
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
      <DashAngka
        label="Antrean menunggu"
        :nilai="antrean"
        :keterangan="antrean === null ? 'tidak dapat dibaca' : 'impor Excel & PDF'"
        :nada="antrean !== null && antrean > 50 ? 'peringatan' : 'netral'"
      />
      <DashAngka label="Tugas gagal" :nilai="data.kesehatan.job_gagal" keterangan="perlu diperiksa" :nada="data.kesehatan.job_gagal > 0 ? 'bahaya' : 'baik'" />
      <DashAngka label="PDF gagal (7 hari)" :nilai="data.kesehatan.pdf_gagal_7_hari" :nada="data.kesehatan.pdf_gagal_7_hari > 0 ? 'peringatan' : 'baik'" tautan="/staf/cetak" />
      <DashAngka label="Backup terakhir" :nilai="backup.teks" :nada="backup.nada" keterangan="dijadwalkan harian 02:00 WIB" />
    </div>

    <div class="grid gap-4 xl:grid-cols-3">
      <DashKartu class="xl:col-span-2" judul="Aktivitas terbaru" keterangan="Tindakan sensitif ditandai." tautan="/staf/audit-log" label-tautan="Riwayat lengkap">
        <p v-if="data.aktivitas.length === 0" class="text-sm text-slate-500">Belum ada aktivitas tercatat.</p>
        <ul v-else class="divide-y divide-slate-100">
          <li v-for="a in data.aktivitas" :key="a.id" class="flex items-center justify-between gap-3 py-2.5">
            <div class="flex min-w-0 items-center gap-2.5">
              <span class="h-2 w-2 shrink-0 rounded-full" :class="aksiSensitif(a.aksi) ? 'bg-amber-500' : 'bg-slate-300'" :title="aksiSensitif(a.aksi) ? 'Tindakan sensitif' : 'Rutin'"></span>
              <div class="min-w-0">
                <p class="truncate text-sm font-medium text-slate-900">{{ labelAksi(a.aksi) }}</p>
                <p class="text-xs text-slate-500">{{ a.aktor ?? 'Sistem' }}<span v-if="a.peran"> ({{ a.peran }})</span></p>
              </div>
            </div>
            <span class="shrink-0 text-xs text-slate-500">{{ waktuRelatif(a.waktu) }}</span>
          </li>
        </ul>
      </DashKartu>

      <div class="space-y-4">
        <DashKartu judul="Kualitas data master" :keterangan="data.kualitas_data.tahun_ajaran_aktif ? `Tahun ajaran aktif: ${data.kualitas_data.tahun_ajaran_aktif.nama} (Sem ${data.kualitas_data.tahun_ajaran_aktif.semester})` : undefined">
          <p v-if="!data.kualitas_data.tahun_ajaran_aktif" class="mb-3 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert">
            Belum ada tahun ajaran aktif. <RouterLink to="/staf/master?tab=tahun_ajaran" class="font-bold underline">Atur sekarang</RouterLink>
          </p>
          <ul class="space-y-2">
            <li v-for="b in butirData" :key="b.kunci" class="flex items-center justify-between gap-3 text-sm">
              <span class="text-slate-700">{{ b.label }}</span>
              <RouterLink v-if="b.jumlah > 0" :to="b.tautan" class="rounded-full px-2.5 py-0.5 text-xs font-bold hover:underline" :class="KELAS_NADA.peringatan">{{ b.jumlah }}</RouterLink>
              <span v-else class="rounded-full px-2.5 py-0.5 text-xs font-bold" :class="KELAS_NADA.baik">OK</span>
            </li>
          </ul>
        </DashKartu>

        <DashKartu judul="Pengguna" keterangan="Jumlah akun per peran.">
          <ul class="grid grid-cols-2 gap-2">
            <li v-for="[kunci, label] in ROLE" :key="kunci" class="rounded-xl bg-slate-50 p-2.5 text-center">
              <p class="text-lg font-extrabold tabular-nums text-slate-900">{{ data.pengguna[kunci] }}</p>
              <p class="text-[11px] font-semibold text-slate-500">{{ label }}</p>
            </li>
          </ul>
        </DashKartu>
      </div>
    </div>
  </div>
</template>
