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
      netral: 'border-l-slate-300',
      info: 'border-l-brand-500',
      baik: 'border-l-emerald-500',
      peringatan: 'border-l-amber-500 bg-amber-50',
      bahaya: 'border-l-red-600 bg-red-50'
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
    class="block rounded-xl border-l-4 bg-white p-4 ring-1 ring-slate-900/[0.07] transition-shadow"
    :class="[aksen, tautan ? 'hover:shadow-md active:scale-[0.99]' : '']"
  >
    <p class="text-xs font-semibold text-slate-500">{{ label }}</p>
    <!-- Nilai teks (mis. "Belum ada backup", "3 jam lalu") memakai huruf lebih kecil agar tidak terpotong. -->
    <p class="mt-1 font-extrabold tabular-nums" :class="[typeof nilai === 'string' ? 'text-xl leading-9' : 'text-3xl', warnaAngka]">{{ nilai ?? '-' }}</p>
    <p v-if="keterangan" class="mt-0.5 text-xs text-slate-500">{{ keterangan }}</p>
  </component>
</template>
