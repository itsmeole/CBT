<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import type { Nada } from '@/lib/dashboard'

/** Tile angka yang bisa diklik. Nada menandai urgensi (mis. merah bila ada siswa terkunci). */
const props = withDefaults(
  defineProps<{
    label: string
    nilai: number | string | null
    keterangan?: string
    nada?: Nada
    tautan?: string
  }>(),
  { nada: 'netral' }
)

const aksen = computed(
  () =>
    ({
      netral: 'border-slate-200',
      info: 'border-sky-200',
      baik: 'border-emerald-200',
      peringatan: 'border-amber-300 bg-amber-50/60',
      bahaya: 'border-red-300 bg-red-50/60'
    })[props.nada]
)
const warnaAngka = computed(
  () =>
    ({
      netral: 'text-slate-900',
      info: 'text-sky-700',
      baik: 'text-emerald-700',
      peringatan: 'text-amber-700',
      bahaya: 'text-red-700'
    })[props.nada]
)
</script>

<template>
  <component
    :is="tautan ? RouterLink : 'div'"
    :to="tautan"
    class="block rounded-2xl border bg-white p-4 transition-shadow"
    :class="[aksen, tautan ? 'hover:shadow-md focus-visible:outline-2 focus-visible:outline-brand-600' : '']"
  >
    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">{{ label }}</p>
    <!-- Nilai teks (mis. "Belum ada backup", "3 jam lalu") memakai huruf lebih kecil agar tidak terpotong. -->
    <p class="mt-1 font-extrabold tabular-nums" :class="[typeof nilai === 'string' ? 'text-xl leading-9' : 'text-3xl', warnaAngka]">{{ nilai ?? '-' }}</p>
    <p v-if="keterangan" class="mt-0.5 text-xs text-slate-500">{{ keterangan }}</p>
  </component>
</template>
