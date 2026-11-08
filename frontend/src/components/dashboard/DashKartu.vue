<script setup lang="ts">
import { RouterLink } from 'vue-router'

/** Kartu panel dashboard dengan judul, keterangan, dan tautan "lihat semua" opsional. */
defineProps<{
  judul: string
  keterangan?: string
  tautan?: string
  labelTautan?: string
}>()
</script>

<template>
  <section class="card flex flex-col bg-white p-4 sm:p-5" :aria-label="judul">
    <header class="mb-3 flex items-start justify-between gap-3">
      <div class="min-w-0">
        <h2 class="text-sm font-bold tracking-tight text-slate-900">{{ judul }}</h2>
        <p v-if="keterangan" class="mt-0.5 text-xs text-slate-500">{{ keterangan }}</p>
      </div>
      <RouterLink v-if="tautan" :to="tautan" class="shrink-0 text-xs font-semibold text-brand-600 hover:underline">
        {{ labelTautan ?? 'Lihat semua' }} →
      </RouterLink>
    </header>
    <div class="flex-1">
      <slot />
    </div>
  </section>
</template>
