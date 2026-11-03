<script setup lang="ts">
import { RouterLink } from 'vue-router'
</script>

<template>
  <main class="mx-auto flex min-h-dvh max-w-sm flex-col items-center justify-center gap-4 p-6 text-center">
    <h1 class="text-2xl font-bold">Akses ditolak</h1>
    <p class="text-slate-600">Anda tidak memiliki akses ke halaman ini.</p>
    <RouterLink to="/" class="btn-primary">Kembali ke beranda</RouterLink>
  </main>
</template>

