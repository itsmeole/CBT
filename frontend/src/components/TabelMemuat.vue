<script setup lang="ts">
/** Baris kerangka pemuatan di dalam tabel (pakai di dalam <tbody>). */
withDefaults(defineProps<{ kolom: number; baris?: number }>(), { baris: 5 })
const lebar = ['w-3/4', 'w-1/2', 'w-2/3', 'w-5/6', 'w-1/3']
</script>

<template>
  <tr v-for="n in baris" :key="n" aria-hidden="true" class="animate-pulse">
    <td v-for="k in kolom" :key="k" class="p-3">
      <div class="h-3.5 rounded bg-slate-200/80" :class="lebar[(n + k) % lebar.length]"></div>
    </td>
  </tr>
  <tr class="sr-only"><td :colspan="kolom" role="status">Memuat data...</td></tr>
</template>
